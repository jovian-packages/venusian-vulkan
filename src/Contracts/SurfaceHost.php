<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Contracts;

/**
 * What a window-system host lends the engine. The engine is host-blind.
 */
interface SurfaceHost
{
    /** 'VK_EXT_metal_surface' today; a later slice adds 'VK_KHR_wayland_surface'. */
    public function wsiExtension(): string;

    /** Raw layer bits for GPUAttachment; 0 when the window engine adopts nothing. */
    public function layerPointer(): int;

    /** 'CAMetalLayer' on Darwin; '' on a host that does not mint a layer. */
    public function layerClass(): string;

    public function setDrawableSize(int $width, int $height): void;

    /** Backing scale MoltenVK multiplies into currentExtent. A no-op on a host that has no layer. */
    public function setContentsScale(float $scale): void;

    /** VkSurfaceKHR bits. */
    public function createSurface(int $instance): int;

    public function release(): void;
}
