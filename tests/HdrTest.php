<?php

declare(strict_types=1);

use Jovian\Engines\Vulkan\VulkanDevice;
use Surface\Contracts\Drawing\ColorSpace;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Drawing\TargetFormat;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Rasterize\Edges;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\Framebuffers\HdrImage;
use Surface\NutsAndBolts\Color;

/*
 * HDR targets: linear half floats (SDR white 1.0) offscreen everywhere the
 * device renders them; shown where the surface has the colour space.
 */

function vulkanHdrEngine(int $width, int $height, ColorSpace $space = ColorSpace::ExtendedLinearSrgb): ?GpuRenderingEngine
{
    $device = new VulkanDevice;
    $format = $space === ColorSpace::Hdr10Pq ? TargetFormat::Rgb10A2 : TargetFormat::Rgba16Float;
    if (! in_array([$format, $space], $device->targetFormats(), true)) {
        return null;
    }

    return new GpuRenderingEngine($device, $width, $height, Edges::HARD, format: $format, colorspace: $space);
}

it('lists HDR and Display P3 targets only with the swapchain colour space extension', function (): void {
    $device = new VulkanDevice;
    $formats = $device->targetFormats();

    if (! $device->swapchainColorSpaces()) {
        expect($formats)->toBe([]);

        return;
    }
    expect(end($formats))->toBe([TargetFormat::Rgba8, ColorSpace::DisplayP3])
        ->and(count($formats))->toBeIn([1, 4]);
});

it('draws SDR colours as linear light with SDR white at 1.0, and reads them back as they were', function (ColorSpace $space): void {
    $engine = vulkanHdrEngine(4, 4, $space) ?? test()->markTestSkipped('this device renders no half-float target');
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(255, 255, 255)));
    $white = $engine->hdrSnapshot()->rgba16f();
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(128, 64, 0)));

    expect($white)->toBe(str_repeat(pack('v4', 0x3C00, 0x3C00, 0x3C00, 0x3C00), 16))
        ->and(pixelOf($engine->framebuffer()->toRgba8(), 4, 2, 2))->toBe('804000ff');
})->with([ColorSpace::ExtendedLinearSrgb, ColorSpace::Hdr10Pq]);

it('draws an HdrImage brighter than SDR white', function (): void {
    $engine = vulkanHdrEngine(2, 1) ?? test()->markTestSkipped('this device renders no half-float target');
    $hdr = HdrImage::fromFloats([4.0, 2.0, 1.0, 1.0, 0.5, 0.5, 0.5, 1.0], 2, 1);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0))->image($hdr, 0, 0));

    expect($engine->hdrSnapshot()->rgba16f())->toBe(pack('v8', 0x4400, 0x4000, 0x3C00, 0x3C00, 0x3800, 0x3800, 0x3800, 0x3C00));
});

it('writes RGBA8 into an HDR target as linear light', function (): void {
    $engine = vulkanHdrEngine(1, 1) ?? test()->markTestSkipped('this device renders no half-float target');
    $engine->framebuffer()->uploadRgba8("\xFF\xFF\xFF\xFF", new Region(0, 0, 1, 1));

    expect($engine->hdrSnapshot()->rgba16f())->toBe(pack('v4', 0x3C00, 0x3C00, 0x3C00, 0x3C00));
});

it('reads an RGBA8 target as linear half floats', function (): void {
    $engine = new GpuRenderingEngine(new VulkanDevice, 1, 1, Edges::HARD);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(255, 255, 255)));

    expect($engine->hdrSnapshot()->rgba16f())->toBe(pack('v4', 0x3C00, 0x3C00, 0x3C00, 0x3C00));
});

it('refuses an HDR target on a surface that shows none, naming what it shows', function (ColorSpace $space, array $pairs): void {
    $layer = CAMetalLayer::layer();
    $device = new VulkanDevice;
    $device->adopt(new LentSurface(SurfaceKind::METAL_LAYER, ['layer' => $layer->pointer()], fn (): array => [8, 8]));
    $format = $space === ColorSpace::Hdr10Pq ? TargetFormat::Rgb10A2 : TargetFormat::Rgba16Float;
    if (! in_array([$format, $space], $device->targetFormats(), true)) {
        test()->markTestSkipped('this device renders no half-float target');
    }
    $offered = array_filter($pairs, fn (array $pair): bool => in_array($pair, $device->surfaceFormats(), true));

    if ($offered !== []) {
        expect($device->targetAs(8, 8, 1, $format, $space)->viewportWidth())->toBe(8);

        return;
    }
    expect(fn () => $device->targetAs(8, 8, 1, $format, $space))
        ->toThrow(DrawingException::class, "vulkan: this surface shows no {$space->value} swapchain; it offers (format, colour space):");
})->with([
    'extended linear' => [ColorSpace::ExtendedLinearSrgb, [[VK_FORMAT_R16G16B16A16_SFLOAT, VK_COLOR_SPACE_EXTENDED_SRGB_LINEAR_EXT]]],
    'HDR10' => [ColorSpace::Hdr10Pq, [[VK_FORMAT_A2B10G10R10_UNORM_PACK32, VK_COLOR_SPACE_HDR10_ST2084_EXT], [VK_FORMAT_A2R10G10B10_UNORM_PACK32, VK_COLOR_SPACE_HDR10_ST2084_EXT]]],
])->skip(PHP_OS_FAMILY !== 'Darwin', 'a CAMetalLayer surface: macOS');

it('encodes the show pass by the colour space', function (ColorSpace $space, int $code): void {
    $layer = CAMetalLayer::layer();
    $device = new VulkanDevice;
    $device->adopt(new LentSurface(SurfaceKind::METAL_LAYER, ['layer' => $layer->pointer()], fn (): array => [8, 8]));
    $format = $space === ColorSpace::Hdr10Pq ? TargetFormat::Rgb10A2 : TargetFormat::Rgba16Float;
    try {
        $device->targetAs(8, 8, 1, $format, $space);
    } catch (DrawingException) {
        test()->markTestSkipped("this surface shows no {$space->value} swapchain");
    }

    expect($device->transform()[0])->toBe($code);
})->with([
    'extended linear' => [ColorSpace::ExtendedLinearSrgb, 1],
    'HDR10' => [ColorSpace::Hdr10Pq, 2],
])->skip(PHP_OS_FAMILY !== 'Darwin', 'a CAMetalLayer surface: macOS');

it('refuses anything but RGBA8 sRGB while exporting a dmabuf', function (): void {
    $device = new VulkanDevice;
    $device->adopt(new LentSurface(SurfaceKind::DMABUF, ['display' => 1], fn (): array => [8, 8]));

    expect(fn () => $device->targetAs(8, 8, 1, TargetFormat::Rgba8, ColorSpace::DisplayP3))
        ->toThrow(DrawingException::class, 'vulkan: a dmabuf export carries RGBA8 sRGB only.');
})->skip(PHP_OS_FAMILY === 'Darwin', 'dmabuf exports: Linux');
