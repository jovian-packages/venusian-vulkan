<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Hosts;

use Jovian\Venusian\Vulkan\Contracts\SurfaceHost;
use Jovian\Venusian\Vulkan\Exceptions\VulkanDrawingException;
use Surface\Contracts\Drawing\VulkanSurfaceLender;

/**
 * A surface host over a window the Surface host owns (an SDL window on
 * Linux). The host names its WSI extension and turns the engine's instance
 * into a surface; the window sizes itself, so drawable-size writes are
 * no-ops and the swapchain follows the surface's current extent. The
 * host destroys the surface: the executor hands it back through
 * destroySurface() — one owner. Every extension the host names is enabled.
 */
final class LentSurfaceHost implements SurfaceHost
{
    public function __construct(private readonly VulkanSurfaceLender $lender) {}

    /** The first platform surface extension the host names (any VK_*_surface, or VK_KHR_display). */
    public function wsiExtension(): string
    {
        foreach ($this->lender->instanceExtensions() as $extension) {
            if ($extension === 'VK_KHR_surface') {
                continue;
            }
            if (str_ends_with($extension, '_surface') || $extension === 'VK_KHR_display') {
                return $extension;
            }
        }

        throw VulkanDrawingException::noHostForPlatform();
    }

    /** @return list<string> Every extension the host names, VK_KHR_surface first if it left it out. */
    public function instanceExtensions(): array
    {
        $this->wsiExtension();
        $named = array_values($this->lender->instanceExtensions());

        return in_array('VK_KHR_surface', $named, true) ? $named : ['VK_KHR_surface', ...$named];
    }

    public function layerPointer(): int
    {
        return 0;
    }

    public function layerClass(): string
    {
        return '';
    }

    public function setDrawableSize(int $width, int $height): void {}

    public function setContentsScale(float $scale): void {}

    public function createSurface(int $instance): int
    {
        $surface = $this->lender->createSurface($instance);
        if ($surface === 0) {
            throw VulkanDrawingException::noHostForPlatform();
        }

        return $surface;
    }

    public function destroySurface(int $instance, int $surface): void
    {
        $this->lender->destroySurface($instance, $surface);
    }

    public function release(): void {}
}
