<?php

declare(strict_types=1);

use Jovian\Bindings\Metal\Runtime\Lifetime;
use Jovian\Bindings\Metal\Runtime\Registry;
use Jovian\Bindings\Vulkan\Enums\VkFormat;
use Jovian\Venusian\Vulkan\Targets\Pipelines;
use Jovian\Venusian\Vulkan\Targets\RenderPass;
use Surface\Contracts\Drawing\Topology;

beforeEach(function () {
    if (! vulkanExtensionLoaded()) {
        test()->markTestSkipped('ext-vulkan is not loaded');
    }

    if (metalExtensionLoaded()) {
        Lifetime::reset();
        Registry::reset();
    }
});

afterEach(function () {
    if (metalExtensionLoaded()) {
        Registry::reset();
        Lifetime::reset();
    }
});

it('compiles a clear/load pass and five topology pipelines headless', function () {
    $context = vulkanContext();
    $pass = RenderPass::create($context, VkFormat::R8G8B8A8_UNORM);

    expect($pass->format)->toBe(VkFormat::R8G8B8A8_UNORM)
        ->and($pass->clear)->toBeGreaterThan(0)
        ->and($pass->load)->toBeGreaterThan(0)
        ->and($pass->clear)->not->toBe($pass->load);

    $pipelines = Pipelines::create($context, $pass->clear);

    foreach (Topology::cases() as $topology) {
        expect($pipelines->get($topology))->toBeGreaterThan(0);
    }

    $handles = [];
    foreach (Topology::cases() as $topology) {
        $handles[] = $pipelines->get($topology);
    }
    expect(count(array_unique($handles)))->toBe(5);

    $pipelines->destroy($context->device);
    $pass->destroy($context->device);
});
