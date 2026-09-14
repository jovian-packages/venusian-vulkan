<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Values;

/**
 * Pure bump: 4-align the cursor, then advance it by the payload.
 *
 * Growth is not this object's job. When next exceeds the live buffer the
 * executor retires that buffer and allocates grownSize(old, needed).
 */
final readonly class StagingPlan
{
    public function __construct(
        public int $offset,
        public int $next,
        public int $bytes,
    ) {}

    public static function place(int $cursor, int $bytes): self
    {
        $offset = ($cursor + 3) & ~3;

        return new self($offset, $offset + $bytes, $bytes);
    }

    public static function grownSize(int $old, int $needed): int
    {
        return max($old * 2, $needed);
    }
}
