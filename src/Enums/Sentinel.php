<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Enums;

/**
 * Vulkan sentinels that collide as PHP ints with other ~0 / -1 constants.
 */
enum Sentinel: int
{
    case SUBPASS_EXTERNAL = 4294967295;
    case WHOLE_SIZE = -1;
}
