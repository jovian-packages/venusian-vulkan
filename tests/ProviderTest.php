<?php

declare(strict_types=1);

use Jovian\Engines\Vulkan\VulkanDevice;
use Jovian\Engines\Vulkan\Providers\VenusianVulkanServiceProvider;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\NutsAndBolts\Color;

it('registers the vulkan engine on the drawing manager', function (): void {
    $drawing = vulkanDrawing();

    VenusianVulkanServiceProvider::extend($drawing);
    $engine = $drawing->renderer('vulkan', ['width' => 16, 'height' => 8, 'edges' => 'hard']);

    expect($drawing->engines())->toBe(['velvet', 'vulkan'])
        ->and($engine)->toBeInstanceOf(GpuRenderingEngine::class)
        ->and($engine->name())->toBe('vulkan')
        ->and($engine->device())->toBeInstanceOf(VulkanDevice::class)
        ->and([$engine->width(), $engine->height()])->toBe([16, 8]);

    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(255, 255, 255)));
    expect(pixelOf($engine->framebuffer()->toRgba8(), 16, 0, 0))->toBe('ffffffff');
});

it('refuses the Velvet-only arguments by name', function (): void {
    $drawing = vulkanDrawing();
    VenusianVulkanServiceProvider::extend($drawing);

    $drawing->renderer('vulkan', ['width' => 16, 'height' => 8, 'mode' => 'ring']);
})->throws(DrawingException::class, "vulkan does not take 'mode'. It takes: output, width, height, edges, format, colorspace, frames_in_flight, resolution.");
