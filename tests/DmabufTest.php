<?php

declare(strict_types=1);

use Jovian\Engines\Vulkan\VulkanDevice;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Framebuffers\DmabufExportable;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\NutsAndBolts\Color;

/*
 * The dmabuf export a GTK canvas imports: a linear RGBA8 image beside the
 * target, exported as a dmabuf fd and blitted into at each present().
 */

function exports(VulkanDevice $device): bool
{
    return PHP_OS_FAMILY === 'Linux'
        && array_diff([VK_KHR_EXTERNAL_MEMORY_FD_EXTENSION_NAME, VK_EXT_EXTERNAL_MEMORY_DMA_BUF_EXTENSION_NAME], $device->extensions()) === [];
}

function dmabufSurface(int $width = 32, int $height = 16): LentSurface
{
    return new LentSurface(SurfaceKind::DMABUF, ['display' => 1], fn (): array => [$width, $height]);
}

it('exports nothing until a dmabuf surface is adopted, and refuses one without the export extensions', function (): void {
    $device = new VulkanDevice;
    $target = $device->target(32, 16, 4);

    expect($target)->toBeInstanceOf(DmabufExportable::class)
        ->and($target->dmabuf())->toBeNull();
    if (! exports($device)) {
        expect(fn () => $device->adopt(dmabufSurface()))
            ->toThrow(DrawingException::class, 'vulkan: dmabuf presenting needs VK_KHR_external_memory_fd and VK_EXT_external_memory_dma_buf; this device lacks ');
    }
});

/** Whether $fd is an open descriptor: php://fd duplicates it, and the duplicate is closed again. */
function fdOpen(int $fd): bool
{
    // A closed descriptor is the answer looked for, not a warning.
    set_error_handler(fn (): bool => true);
    try {
        $handle = fopen("php://fd/{$fd}", 'r');
    } finally {
        restore_error_handler();
    }
    if ($handle === false) {
        return false;
    }
    fclose($handle);

    return true;
}

/** A device presenting into a dmabuf surface, its engine drawn once. @return array{VulkanDevice, LentSurface, GpuRenderingEngine} */
function exporting(int $width = 32, int $height = 16): array
{
    $device = new VulkanDevice;
    $surface = dmabufSurface($width, $height);
    $device->adopt($surface);
    $engine = new GpuRenderingEngine($device, $width, $height);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(16, 32, 48))->fillRect(2, 1, 4, 3, Color::rgb(255, 128, 0)));

    return [$device, $surface, $engine];
}

describe('on a device that exports', function (): void {
    beforeEach(function (): void {
        if (! exports(new VulkanDevice)) {
            $this->markTestSkipped('dmabuf export needs Linux and VK_KHR_external_memory_fd + VK_EXT_external_memory_dma_buf');
        }
    });

    it('exports a linear RGBA8 image at each present', function (): void {
        [$device, $surface, $engine] = exporting();

        expect($engine->framebuffer()->dmabuf())->toBeNull()
            ->and($device->present($surface))->toBeTrue();
        $export = $engine->framebuffer()->dmabuf();

        expect($export['fd'])->toBeGreaterThan(2)
            ->and([$export['width'], $export['height']])->toBe([32, 16])
            ->and($export['stride'])->toBeGreaterThanOrEqual(128)
            ->and($export['offset'])->toBeGreaterThanOrEqual(0)
            ->and($export['fourcc'])->toBe(0x34324241)
            ->and($export['modifier'])->toBe(0);
    });

    it('copies the frame into the export at present(), and the mapped export reads it back', function (): void {
        [$device, $surface, $engine] = exporting();

        $shown = $device->present($surface);
        $exported = $device->exportBytes();
        $frame = $engine->framebuffer()->toRgba8();

        expect($shown)->toBeTrue()
            ->and(strlen($exported))->toBe(32 * 16 * 4)
            ->and(pixelOf($exported, 32, 3, 2))->toBe('ff8000ff')
            ->and($exported)->toBe($frame);
    });

    it('never writes an export the importer holds, and answers false while it holds them all', function (): void {
        [$device, $surface, $engine] = exporting();
        $fds = [];
        foreach (range(1, 3) as $i) {
            expect($device->present($surface))->toBeTrue();
            $fds[] = $engine->framebuffer()->dmabuf()['fd'];
        }

        $held = $device->present($surface);
        $engine->framebuffer()->releaseDmabuf($fds[1]);
        $again = $device->present($surface);

        expect(array_unique($fds))->toHaveCount(3)
            ->and($held)->toBeFalse()
            ->and($again)->toBeTrue()
            ->and($engine->framebuffer()->dmabuf()['fd'])->toBe($fds[1]);
    });

    it('keeps a held export open across a new target size until the importer hands it back', function (): void {
        [$device, $surface, $engine] = exporting();
        $device->present($surface);
        $old = $engine->framebuffer()->dmabuf();
        $framebuffer = $engine->framebuffer();

        $device->target(48, 24, 4);
        $openAfterResize = fdOpen($old['fd']);
        $framebuffer->releaseDmabuf($old['fd']);

        expect($openAfterResize)->toBeTrue()
            ->and(fdOpen($old['fd']))->toBeFalse();
    });

    it('closes what the importer does not hold when the surface is released, and the rest when it hands them back', function (): void {
        [$device, $surface, $engine] = exporting();
        $device->present($surface);
        $held = $engine->framebuffer()->dmabuf()['fd'];
        $framebuffer = $engine->framebuffer();

        $surface->release();

        expect($framebuffer->dmabuf())->toBeNull()
            ->and($device->present($surface))->toBeFalse()
            ->and(fdOpen($held))->toBeTrue();
        $framebuffer->releaseDmabuf($held);
        expect(fdOpen($held))->toBeFalse();
    });

    it('lets an importer hand an export back after the device is released', function (): void {
        [$device, $surface, $engine] = exporting();
        $device->present($surface);
        $held = $engine->framebuffer()->dmabuf()['fd'];
        $framebuffer = $engine->framebuffer();

        $device->release();

        expect($surface->released())->toBeTrue()
            ->and(fdOpen($held))->toBeTrue();
        $framebuffer->releaseDmabuf($held);
        expect(fdOpen($held))->toBeFalse();
    });

    it('refuses a second surface either way round', function (): void {
        [$device] = exporting();

        expect(fn () => $device->adopt(dmabufSurface()))->toThrow(DrawingException::class, 'vulkan: this device already has a surface.')
            ->and(fn () => $device->adopt(new LentSurface(SurfaceKind::VULKAN_SURFACE, ['surface' => 1], fn (): array => [8, 8])))
            ->toThrow(DrawingException::class, 'vulkan: this device already has a surface.');
    });
});
