<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Enums;

/**
 * VkDynamicState is missing from the binding. These are the two values
 * the painter pipeline actually uses (viewport + scissor).
 */
enum DynamicState: int
{
    case VIEWPORT = 0;
    case SCISSOR = 1;
}
