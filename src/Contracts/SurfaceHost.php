<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Contracts;

/**
 * What a window-system host lends the engine. The engine is host-blind.
 */
interface SurfaceHost
{
    /** The window-system surface extension: 'VK_EXT_metal_surface' for a layer, whatever a lender names otherwise. */
    public function wsiExtension(): string;

    /** @return list<string> Every instance extension the context must enable for this host, VK_KHR_surface included. */
    public function instanceExtensions(): array;

    /** Raw layer bits for GPUAttachment; 0 when the window engine adopts nothing. */
    public function layerPointer(): int;

    /** 'CAMetalLayer' on Darwin; '' on a host that does not mint a layer. */
    public function layerClass(): string;

    public function setDrawableSize(int $width, int $height): void;

    /** Backing scale MoltenVK multiplies into currentExtent. A no-op on a host that has no layer. */
    public function setContentsScale(float $scale): void;

    /** VkSurfaceKHR bits. */
    public function createSurface(int $instance): int;

    /** The only destroyer of a surface this host created. The executor calls it once, after the swapchain is gone. */
    public function destroySurface(int $instance, int $surface): void;

    public function release(): void;
}
