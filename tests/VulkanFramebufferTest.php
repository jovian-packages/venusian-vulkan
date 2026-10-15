<?php

declare(strict_types=1);

use Jovian\Engines\Vulkan\VulkanDevice;
use Jovian\Engines\Vulkan\VulkanFramebuffer;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\GLFramebuffer;
use Surface\Contracts\Framebuffers\Region;
use Surface\Drawing\Gpu\GpuDevice;

it('is a GpuDevice named vulkan over the first GPU, and enables the swapchain', function (): void {
    $device = new VulkanDevice;

    expect($device)->toBeInstanceOf(GpuDevice::class)
        ->and($device->name())->toBe('vulkan')
        ->and($device->maxSamples())->toBeGreaterThanOrEqual(4)
        ->and($device->surfaces())->toBe([SurfaceKind::VULKAN_SURFACE, SurfaceKind::METAL_LAYER, ...(array_diff([VK_KHR_EXTERNAL_MEMORY_FD_EXTENSION_NAME, VK_EXT_EXTERNAL_MEMORY_DMA_BUF_EXTENSION_NAME], $device->extensions()) === [] ? [SurfaceKind::DMABUF] : [])])
        ->and($device->handles())->toBe(['instance' => $device->instance()->pointer()])
        ->and($device->stencilFormat())->toBeIn([VK_FORMAT_D24_UNORM_S8_UINT, VK_FORMAT_D32_SFLOAT_S8_UINT])
        ->and($device->extensions())->toContain(VK_KHR_SWAPCHAIN_EXTENSION_NAME)
        ->and($device->device())->toBeInstanceOf(VkDevice::class)
        ->and($device->queue())->toBeInstanceOf(VkQueue::class)
        ->and($device->family())->toBeInt();
});

it('skips the CPU device', function (): void {
    $device = new VulkanDevice;

    vkGetPhysicalDeviceProperties($device->physical(), $p);

    expect($p->deviceType)->not->toBe(VK_PHYSICAL_DEVICE_TYPE_CPU);
});

it('makes a transparent black RGBA8 target of the asked size', function (int $samples): void {
    $target = (new VulkanDevice)->target(24, 8, $samples);

    expect($target)->toBeInstanceOf(VulkanFramebuffer::class)
        ->and($target)->toBeInstanceOf(GLFramebuffer::class)
        ->and([$target->viewportWidth(), $target->viewportHeight()])->toBe([24, 8])
        ->and($target->hostFormat())->toEqual(FormatSpec::rgba8())
        ->and($target->toRgba8())->toBe(str_repeat("\x00\x00\x00\x00", 24 * 8))
        ->and($target->image())->toBeInstanceOf(VkImage::class)
        ->and($target->view())->toBeInstanceOf(VkImageView::class);
})->with([1, 4]);

it('draws with one sample or four', function (): void {
    (new VulkanDevice)->target(8, 8, 2);
})->throws(DrawingException::class, 'vulkan draws with 1 or 4 samples, got 2.');

it('refuses four samples when the device has fewer', function (): void {
    $device = new class extends VulkanDevice
    {
        public function maxSamples(): int
        {
            return 1;
        }
    };

    $device->target(8, 8, 4);
})->throws(DrawingException::class, 'vulkan: this device renders at most 1 sample; anti-aliased edges need 4.');

it('refuses an empty target', function (): void {
    (new VulkanDevice)->target(0, 8, 4);
})->throws(DrawingException::class, 'vulkan needs a target of at least 1 × 1, got 0 × 8.');

it('uploads a region and reads it back, tightly packed, rows top-down', function (int $samples): void {
    $target = (new VulkanDevice)->target(16, 8, $samples);
    $block = "\x10\x20\x30\xff\x11\x21\x31\xff\x12\x22\x32\xff".str_repeat("\x40\x50\x60\xff", 3);

    $target->uploadRgba8($block, new Region(5, 1, 3, 2));

    expect($target->readRgba8(new Region(5, 1, 3, 2)))->toBe($block)
        ->and(strlen($target->readRgba8(new Region(0, 0, 16, 8))))->toBe(16 * 8 * 4)
        ->and(pixelOf($target->toRgba8(), 16, 5, 1))->toBe('102030ff')
        ->and(pixelOf($target->toRgba8(), 16, 7, 1))->toBe('122232ff')
        ->and(pixelOf($target->toRgba8(), 16, 6, 2))->toBe('405060ff')
        ->and(pixelOf($target->toRgba8(), 16, 4, 1))->toBe('00000000');
})->with([1, 4]);

it('serves the pixel calls of a Framebuffer through the GPU', function (): void {
    $target = (new VulkanDevice)->target(16, 8, 4);

    $target->setPixel(2, 2, 0xFF0000FF)->fill(0x0000FFFF)->setSegment(0, 0, 4, 1, 0x00FF00FF);

    expect(pixelOf($target->toRgba8(), 16, 2, 2))->toBe('0000ffff')
        ->and(pixelOf($target->toRgba8(), 16, 1, 0))->toBe('00ff00ff')
        ->and($target->getPixel(1, 0))->toBe(0x00FF00FF)
        ->and($target->damage())->toEqual([new Region(0, 0, 16, 8)]);
});

it('keeps an old framebuffer readable after the target is re-made', function (): void {
    $device = new VulkanDevice;
    $old = $device->target(8, 8, 4);
    $old->fill(0xFF0000FF);

    $new = $device->target(12, 6, 1);

    expect($new)->not->toBe($old)
        ->and([$new->viewportWidth(), $new->viewportHeight()])->toBe([12, 6])
        ->and(pixelOf($old->toRgba8(), 8, 3, 3))->toBe('ff0000ff')
        ->and(pixelOf($new->toRgba8(), 12, 3, 3))->toBe('00000000');
});

it('reads frame after frame back without holding on to GPU memory', function (): void {
    // 256 KB a readback of the whole 256 × 256 target; the staging buffer goes with each.
    $target = (new VulkanDevice)->target(256, 256, 1);
    $whole = new Region(0, 0, 256, 256);
    foreach (range(1, 20) as $i) {
        $target->readRgba8($whole);
    }
    $before = residentMb();

    foreach (range(1, 200) as $i) {
        $target->readRgba8($whole);
    }

    expect(residentMb() - $before)->toBeLessThan(8.0);
});

it('refuses an upload whose bytes do not fill the region', function (): void {
    (new VulkanDevice)->target(16, 8, 1)->uploadRgba8("\xff\x00\x00\xff", new Region(0, 0, 16, 8));
})->throws(DrawingException::class, 'vulkan: 4 bytes do not fill a 16 × 8 region (512 bytes).');

it('refuses a region outside the target', function (string $call): void {
    $target = (new VulkanDevice)->target(16, 8, 1);
    $region = new Region(10, 4, 16, 8);

    $call === 'read' ? $target->readRgba8($region) : $target->uploadRgba8(str_repeat("\x00", 16 * 8 * 4), $region);
})->with(['read', 'upload'])->throws(DrawingException::class, 'vulkan: the region 16 × 8 at (10, 4) lies outside the 16 × 8 target.');

it('refuses a target past the largest image', function (): void {
    $device = new VulkanDevice;
    $largest = $device->maxImageDimension2D();

    expect(fn () => $device->target($largest + 1, 8, 1))
        ->toThrow(DrawingException::class, 'vulkan: '.($largest + 1)." × 8 is past the largest image this device makes, {$largest} × {$largest}.");
});

it('refuses calls on a released device with its own message', function (): void {
    $device = new VulkanDevice;
    $device->release();

    $device->target(8, 8, 1);
})->throws(DrawingException::class, 'vulkan: this device was released.');
