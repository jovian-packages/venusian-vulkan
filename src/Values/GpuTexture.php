<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Values;

/**
 * The four Vulkan handles that make one sampled image.
 */
final readonly class GpuTexture
{
    public function __construct(
        public int $image,
        public int $memory,
        public int $view,
        public int $set,
    ) {}
}
