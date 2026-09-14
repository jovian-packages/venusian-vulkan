<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Enums;

/**
 * Swapchain extent / image-count sentinels from VkSurfaceCapabilitiesKHR.
 */
enum SurfaceSentinel: int
{
    case EXTENT_UNDEFINED = 4294967295;
    case IMAGE_COUNT_UNBOUNDED = 0;
}
