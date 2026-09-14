<?php

declare(strict_types=1);

/*
| Pest bootstrap for jovian/venusian-vulkan.
|
| Extension-dependent suites skip when ext-vulkan (and, for attach, ext-metal
| with the suggested jovian/metal) is absent. A skipped test is not evidence the GPU path works — run those
| on a Mac with the extensions loaded (export HERD_PHP_84_INI_SCAN_DIR first).
*/

use Jovian\Venusian\Vulkan\VulkanContext;
use Surface\Contracts\Drawing\VulkanSurfaceLender;

function vulkanExtensionLoaded(): bool
{
    return extension_loaded('vulkan');
}

/** ext-metal loaded and jovian/metal (a suggest) installed — the Metal symbols the tests touch exist. */
function metalExtensionLoaded(): bool
{
    return extension_loaded('metal') && class_exists(\Jovian\Bindings\Metal\Runtime\Registry::class);
}

function vulkanRequireExtension(): void
{
    if (! vulkanExtensionLoaded()) {
        test()->skip('ext-vulkan is not loaded');
    }
}

function vulkanContext(): VulkanContext
{
    static $context = null;

    vulkanRequireExtension();

    if (is_null($context)) {
        $context = VulkanContext::boot([]);
    }

    return $context;
}

/** One Surface vertex: x y z r g b a u v. */
function vulkanVertex(float $x, float $y, float $r, float $g, float $b, float $a = 1.0, float $u = 0.0, float $v = 0.0): string
{
    return pack('g9', $x, $y, 0.0, $r, $g, $b, $a, $u, $v);
}

/**
 * A VULKAN_SURFACE lender that records the instances it was asked about.
 * Here, not in a test file, so any single file can run alone.
 *
 * @param  list<string>  $extensions
 */
function lender(array $extensions = ['VK_KHR_surface', 'VK_KHR_wayland_surface'], int $surface = 77): VulkanSurfaceLender
{
    return new class($extensions, $surface) implements VulkanSurfaceLender
    {
        /** @var list<int> */
        public array $instances = [];

        /** @var list<array{int, int}> */
        public array $destroyed = [];

        /** @param list<string> $extensions */
        public function __construct(private array $extensions, private int $surface) {}

        public function instanceExtensions(): array
        {
            return $this->extensions;
        }

        public function createSurface(int $instance): int
        {
            $this->instances[] = $instance;

            return $this->surface;
        }

        public function destroySurface(int $instance, int $surface): void
        {
            $this->destroyed[] = [$instance, $surface];
        }

        public function drawableSize(): array
        {
            return [640, 480];
        }
    };
}

/**
 * The first window-system instance extension the loader lists. Picked from
 * the loader, never from the OS — the Pi runs x11 under XWayland.
 *
 * @param  list<string>  $listed
 */
function listedWsi(array $listed): ?string
{
    foreach (['VK_EXT_metal_surface', 'VK_KHR_xlib_surface', 'VK_KHR_xcb_surface', 'VK_KHR_wayland_surface'] as $extension) {
        if (in_array($extension, $listed, true)) {
            return $extension;
        }
    }

    return null;
}
