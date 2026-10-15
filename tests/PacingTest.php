<?php

declare(strict_types=1);

use Jovian\Engines\Vulkan\VulkanDevice;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\NutsAndBolts\Color;

/*
 * Frames in flight and present reports, offscreen: a frame ring of up to
 * three, and present wait where the device has it.
 */

it('draws three frames ahead and reads back the last', function (): void {
    $device = new VulkanDevice;
    $device->setFramesInFlight(3);
    $engine = new GpuRenderingEngine($device, 8, 8);
    foreach ([[255, 0, 0], [0, 255, 0], [0, 0, 255], [255, 255, 0], [0, 255, 255]] as [$r, $g, $b]) {
        $engine->frame(fn (RenderingEngine $draw) => $draw->clear(Color::rgb($r, $g, $b)));
    }

    expect(pixelOf($engine->framebuffer()->toRgba8(), 8, 4, 4))->toBe('00ffffff')
        ->and(fn () => $device->setFramesInFlight(0))->toThrow(DrawingException::class, 'vulkan keeps 1 to 3 frames in flight, got 0.');
});

it('reports presents only with present wait, and nothing before the first', function (): void {
    $device = new VulkanDevice;

    expect([$device->submitted(), $device->lastPresented()])->toBe([0, null]);
    if ($device->presentWaits()) {
        expect($device->waitPresented(1, 1_000_000))->toBeFalse();

        return;
    }
    expect(fn () => $device->waitPresented(1, 1_000_000))->toThrow(DrawingException::class, 'vulkan: this device has no '.VK_KHR_PRESENT_WAIT_EXTENSION_NAME.': presents() is null for it.');
});
