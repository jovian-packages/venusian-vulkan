<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Enums;

/**
 * UINT64_MAX as a signed PHP int — the acquire / fence wait that never times out.
 */
enum Timeout: int
{
    case FOREVER = -1;
}
