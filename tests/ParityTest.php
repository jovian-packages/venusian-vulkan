<?php

declare(strict_types=1);

use Surface\Contracts\Rasterize\Edges;
use Venusian\Surface\Tests\Support\GpuParity\GpuParity;

/*
 * The suite every GPU engine runs: Surface's scene set against Velvet, partial
 * frames against whole redraws, flushRegion() against the native packings.
 */

GpuParity::register('vulkan', fn (int $width, int $height, Edges $edges) => vulkanEngine($width, $height, $edges));
