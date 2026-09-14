<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Enums;

/**
 * Allocator and descriptor ceilings the executor and context share.
 */
enum Budget: int
{
    case STAGING_INITIAL_BYTES = 262144;
    case DESCRIPTOR_SETS = 256;
    case PUSH_CONSTANT_BYTES = 64;
    case HANDLE_BYTES = 8;
    case COUNT_BYTES = 4;
}
