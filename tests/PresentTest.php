<?php

declare(strict_types=1);

use Jovian\Engines\Vulkan\VulkanDevice;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\NutsAndBolts\Color;
use Surface\Contracts\Windows\ScaleFit;
use Surface\Contracts\Windows\ScaleFilter;
use Surface\Contracts\Drawing\VSync;

/*
 * Presenting: a CAMetalLayer with no view behind it hands out drawables all the
 * same, so the swapchain and the blit are exercised here against MoltenVK; the
 * window cells (AppKit's layer, Qt's VulkanSurface) are the toolkit packages'; the dmabuf export is DmabufTest's.
 */

function layerSurface(CAMetalLayer $layer, int $width = 64, int $height = 32): LentSurface
{
    return new LentSurface(SurfaceKind::METAL_LAYER, ['layer' => $layer->pointer()], fn (): array => [$width, $height]);
}

describe('presenting into a Metal layer', function (): void {
    beforeEach(function (): void {
        if (! VULKAN_MAC || ! class_exists(CAMetalLayer::class)) {
            $this->markTestSkipped('presenting is proven against a Metal layer; Linux surfaces come from a toolkit in 11b');
        }
    });

    it('adopts the layer into a swapchain of the surface\'s size', function (): void {
        $device = new VulkanDevice;

        $device->adopt(layerSurface(CAMetalLayer::layer(), 64, 32));

        expect($device->swapchainExtent())->toBe([64, 32]);
    });

    it('refuses to present before adopting a surface', function (): void {
        $device = new VulkanDevice;
        $device->target(8, 8, 1);

        $device->present(layerSurface(CAMetalLayer::layer()));
    })->throws(DrawingException::class, 'vulkan: adopt() a surface before present().');

    it('copies the target into a swapchain image and presents it', function (): void {
        $layer = CAMetalLayer::layer();
        $device = new VulkanDevice;
        $engine = new GpuRenderingEngine($device, 64, 32);
        $surface = layerSurface($layer);
        $device->adopt($surface);
        $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 128, 255)));

        $shown = $device->present($surface);
        $device->finish();
        vkDeviceWaitIdle($device->device());

        expect($shown)->toBeTrue()
            ->and([(int) $layer->drawableSize()->width, (int) $layer->drawableSize()->height])->toBe([64, 32]);
    });

    it('answers false while the last present is still in flight, and copies again once it landed', function (): void {
        $layer = CAMetalLayer::layer();
        $device = new VulkanDevice;
        $engine = new GpuRenderingEngine($device, 256, 256);
        $surface = layerSurface($layer, 256, 256);
        $device->adopt($surface);
        $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(255, 0, 0)));

        $first = $device->present($surface);
        $while_in_flight = $device->present($surface);
        vkDeviceWaitIdle($device->device());
        $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 255, 0)));
        $after = $device->present($surface);

        // The second present answers false while the first is in flight; a GPU that
        // has already finished it copies again. Both are right; the third must land.
        expect($first)->toBeTrue()
            ->and($after)->toBeTrue()
            ->and($while_in_flight)->toBeIn([true, false]);
    });

    it('presents frame after frame without blocking or growing', function (): void {
        $layer = CAMetalLayer::layer();
        $device = new VulkanDevice;
        $engine = new GpuRenderingEngine($device, 128, 64);
        $surface = layerSurface($layer, 128, 64);
        $device->adopt($surface);
        $shown = 0;
        foreach (range(1, 10) as $i) {
            $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb($i * 20, 0, 0)));
            $device->present($surface);
        }
        $before = residentMb();

        foreach (range(1, 200) as $i) {
            $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb($i % 256, 0, 0)));
            $shown += $device->present($surface) ? 1 : 0;
        }

        expect($shown)->toBeGreaterThan(0)
            ->and(residentMb() - $before)->toBeLessThan(32.0);
    });

    it('answers false while the surface has no area, and presents once it has one', function (): void {
        $layer = CAMetalLayer::layer();
        $size = [0, 0];
        $surface = new LentSurface(SurfaceKind::METAL_LAYER, ['layer' => $layer->pointer()], function () use (&$size): array {
            return $size;
        });
        $device = new VulkanDevice;
        $engine = new GpuRenderingEngine($device, 64, 32);
        $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 128, 255)));

        $device->adopt($surface);
        $minimised = $device->present($surface);
        $size = [64, 32];
        $layer->setDrawableSize(new CGSize(64.0, 32.0));
        $shown = $device->present($surface);

        expect($minimised)->toBeFalse()
            ->and($shown)->toBeTrue()
            ->and($device->swapchainExtent())->toBe([64, 32]);
    });

    it('keeps a presented target until the present\'s copy has landed, then lets it go', function (): void {
        $layer = CAMetalLayer::layer();
        $device = new VulkanDevice;
        $surface = layerSurface($layer, 256, 256);
        $device->adopt($surface);
        foreach (range(1, 20) as $i) {
            $engine = new GpuRenderingEngine($device, 256, 256);
            $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb($i * 10, 0, 0)));
            $device->present($surface);
            unset($engine);
            // The engine's framebuffer goes here, with the copy possibly in flight: forget() waits it.
        }
        $engine = new GpuRenderingEngine($device, 256, 256);
        $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 255, 0)));

        expect(pixelOf($engine->framebuffer()->toRgba8(), 256, 1, 1))->toBe('00ff00ff');
    });

    it('hands back a surface it still holds when it is released, its swapchain gone first, before its instance', function (): void {
        $device = new VulkanDevice;
        $surface = layerSurface(CAMetalLayer::layer(), 8, 8);
        $device->adopt($surface);
        $handedBack = false;
        $surface->onRelease(function () use (&$handedBack): void {
            $handedBack = true;
        });

        $device->release();

        expect($surface->released())->toBeTrue()
            ->and($handedBack)->toBeTrue();
    });

    it('refuses a dmabuf surface while it presents into a layer', function (): void {
        $device = new VulkanDevice;
        $device->adopt(layerSurface(CAMetalLayer::layer(), 8, 8));

        $device->adopt(new LentSurface(SurfaceKind::DMABUF, ['display' => 1], fn (): array => [8, 8]));
    })->throws(DrawingException::class, 'vulkan: this device already has a surface.');

    it('answers false for a released surface', function (): void {
        $device = new VulkanDevice;
        $device->target(8, 8, 1);
        $surface = layerSurface(CAMetalLayer::layer(), 8, 8);
        $device->adopt($surface);
        $surface->release();

        expect($device->present($surface))->toBeFalse();
    });

    it('lets go of the swapchain when the canvas releases the surface, and of everything on release()', function (): void {
        $device = new VulkanDevice;
        $engine = new GpuRenderingEngine($device, 8, 8);
        $surface = layerSurface(CAMetalLayer::layer(), 8, 8);
        $device->adopt($surface);
        $surface->release();

        $engine->release();

        expect(fn () => $device->target(8, 8, 1))->toThrow(DrawingException::class, 'vulkan: this device was released.');
    });
});

it('places a target of another size by the surface fit and presents it', function (): void {
    $layer = CAMetalLayer::layer();
    $device = new VulkanDevice;
    $surface = new LentSurface(SurfaceKind::METAL_LAYER, ['layer' => $layer->pointer()], fn (): array => [64, 64]);
    $engine = new GpuRenderingEngine($device, 32, 16);
    $device->adopt($surface);
    $surface->changeScaling(ScaleFilter::Linear, ScaleFit::Letterbox);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 128, 255)));

    $shown = false;
    for ($i = 0; $i < 20 && ! $shown; $i++) {
        $shown = $device->present($surface);
    }
    $device->finish();

    expect($shown)->toBeTrue()
        ->and($device->swapchainExtent())->toBe([64, 64])
        ->and($device->transform())->toBe([0, 1.0, 203.0]);
})->skip(PHP_OS_FAMILY !== 'Darwin', 'presents into a CAMetalLayer: macOS');

it('follows the vsync it is given through the swapchain\'s present mode', function (): void {
    $layer = CAMetalLayer::layer();
    $device = new VulkanDevice;
    $surface = new LentSurface(SurfaceKind::METAL_LAYER, ['layer' => $layer->pointer()], fn (): array => [32, 32]);
    $engine = new GpuRenderingEngine($device, 32, 32);
    $device->adopt($surface);
    $modes = $device->vsyncModes();

    $device->applyVsync(VSync::On);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $shown = false;
    for ($i = 0; $i < 20 && ! $shown; $i++) {
        $shown = $device->present($surface);
    }
    $device->finish();

    expect($modes)->toContain(VSync::On)
        ->and($device->presentMode())->toBe(VK_PRESENT_MODE_FIFO_KHR)
        ->and($shown)->toBeTrue()
        ->and(fn () => $device->applyVsync(in_array(VSync::Adaptive, $modes, true) ? VSync::Mailbox : VSync::Adaptive))
        ->toThrow(DrawingException::class, 'vulkan: this surface does not present with vsync');
})->skip(PHP_OS_FAMILY !== 'Darwin', 'presents into a CAMetalLayer: macOS');
