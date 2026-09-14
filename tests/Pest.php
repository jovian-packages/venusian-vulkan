<?php

declare(strict_types=1);

/*
| Pest bootstrap for jovian/venusian-vulkan.
|
| Extension-dependent suites skip when ext-vulkan (and, for attach, ext-metal)
| is absent. A skipped test is not evidence the GPU path works — run those
| on a Mac with the extensions loaded (export HERD_PHP_84_INI_SCAN_DIR first).
*/

use Jovian\Venusian\Vulkan\VulkanContext;

function vulkanExtensionLoaded(): bool
{
    return extension_loaded('vulkan');
}

function metalExtensionLoaded(): bool
{
    return extension_loaded('metal');
}

function vulkanRequireExtension(): void
{
    if (! vulkanExtensionLoaded()) {
        test()->skip('ext-vulkan is not loaded');
    }
}

function vulkanContext(): VulkanContext
{
    static $context = null;

    vulkanRequireExtension();

    if (is_null($context)) {
        $context = VulkanContext::boot('');
    }

    return $context;
}

/** One Surface vertex: x y z r g b a u v. */
function vulkanVertex(float $x, float $y, float $r, float $g, float $b, float $a = 1.0, float $u = 0.0, float $v = 0.0): string
{
    return pack('g9', $x, $y, 0.0, $r, $g, $b, $a, $u, $v);
}
