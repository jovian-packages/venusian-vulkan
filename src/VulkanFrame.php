<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan;

/**
 * One in-flight frame. Dropped at endFrame(); a mid-frame readPixels
 * keeps this object and only flips pass_open / acquire_waited.
 */
final class VulkanFrame
{
    public function __construct(
        public int $image_index,
        public bool $pass_open,
        public bool $acquire_waited,
        public int $staging_cursor,
    ) {}
}
