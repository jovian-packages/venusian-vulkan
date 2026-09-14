<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Enums;

/**
 * VK_QUEUE_FAMILY_IGNORED lives alone so it does not share a backing
 * value with SUBPASS_EXTERNAL or WHOLE_SIZE.
 */
enum QueueFamily: int
{
    case IGNORED = 4294967295;
}
