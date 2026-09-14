<?php

declare(strict_types=1);

use Jovian\Venusian\Vulkan\VulkanExecutor;

it('declares slice-3 Vulkan capabilities honestly', function () {
    $capabilities = VulkanExecutor::declaredCapabilities(8192);

    expect($capabilities->blending)->toBeTrue()
        ->and($capabilities->depth)->toBeFalse()
        ->and($capabilities->instancing)->toBeTrue()
        ->and($capabilities->readback)->toBeTrue()
        ->and($capabilities->max_texture_size)->toBe(8192);
});
