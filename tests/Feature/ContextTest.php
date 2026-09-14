<?php

declare(strict_types=1);

use Jovian\Bindings\Metal\Runtime\Lifetime;
use Jovian\Bindings\Metal\Runtime\Registry;
use Jovian\Bindings\Vulkan\Enums\VkBufferUsageFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkMemoryPropertyFlagBits;
use Jovian\Bindings\Vulkan\Runtime\Bridge;
use Jovian\Bindings\Vulkan\VK\VK10;
use Jovian\Bindings\Vulkan\Values\ApiVersion;
use Jovian\Venusian\Vulkan\Exceptions\VulkanDrawingException;
use Jovian\Venusian\Vulkan\VulkanContext;
use Jovian\Venusian\Vulkan\VulkanEngine;

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

it('boots a headless instance, device, and queue', function () {
    $context = vulkanContext();

    expect($context->instance)->toBeGreaterThan(0)
        ->and($context->physicalDevice)->toBeGreaterThan(0)
        ->and($context->device)->toBeGreaterThan(0)
        ->and($context->queue)->toBeGreaterThan(0)
        ->and($context->queueFamily)->toBeGreaterThanOrEqual(0)
        ->and($context->instanceExtensions)->toBe([])
        ->and($context->loaderVersion)->toBeInstanceOf(ApiVersion::class)
        ->and($context->maxPushConstantsSize)->toBeGreaterThanOrEqual(64)
        ->and($context->maxTextureSize)->toBeGreaterThanOrEqual(64)
        ->and($context->vertexModule)->toBeGreaterThan(0)
        ->and($context->fragmentModule)->toBeGreaterThan(0)
        ->and($context->descriptorSetLayout)->toBeGreaterThan(0)
        ->and($context->pipelineLayout)->toBeGreaterThan(0)
        ->and($context->sampler)->toBeGreaterThan(0)
        ->and($context->descriptorPool)->toBeGreaterThan(0)
        ->and($context->placeholder()->image)->toBeGreaterThan(0)
        ->and($context->placeholder()->view)->toBeGreaterThan(0)
        ->and($context->placeholder()->set)->toBeGreaterThan(0);
});

it('uploads and destroys a sampled texture', function () {
    $context = vulkanContext();
    $rgba = pack(
        'C*',
        255, 0, 0, 255,
        0, 255, 0, 255,
        0, 0, 255, 255,
        255, 255, 255, 255,
    );

    $texture = $context->uploadTexture($rgba, 2, 2);

    expect($texture->image)->toBeGreaterThan(0)
        ->and($texture->memory)->toBeGreaterThan(0)
        ->and($texture->view)->toBeGreaterThan(0)
        ->and($texture->set)->toBeGreaterThan(0);

    $context->destroyTexture($texture);
});

it('maps a host-visible buffer for a write/read roundtrip', function () {
    $context = vulkanContext();
    $payload = 'abcdefghijklmnop';
    $flags = VkMemoryPropertyFlagBits::HOST_VISIBLE_BIT->value
        | VkMemoryPropertyFlagBits::HOST_COHERENT_BIT->value;

    [$buffer, $memory] = $context->createBuffer(
        strlen($payload),
        VkBufferUsageFlagBits::TRANSFER_SRC_BIT->value,
        $flags,
    );

    expect($buffer)->toBeGreaterThan(0)->and($memory)->toBeGreaterThan(0);

    $mapped = $context->mapMemory($memory);
    expect($mapped)->toBeGreaterThan(0);
    expect(Bridge::write($mapped, 0, $payload))->toBeTrue();
    expect(Bridge::read($mapped, 0, strlen($payload)))->toBe($payload);

    VK10::vkUnmapMemory($context->device, $memory);
    VK10::vkDestroyBuffer($context->device, $buffer, 0);
    VK10::vkFreeMemory($context->device, $memory, 0);
});

it('caches the engine context and refuses a later WSI mismatch', function () {
    $engine = new VulkanEngine;
    $first = $engine->context();

    expect($first)->toBeInstanceOf(VulkanContext::class)
        ->and($engine->context([]))->toBe($first);

    expect(fn () => $engine->context(['VK_KHR_surface', 'VK_EXT_metal_surface']))
        ->toThrow(VulkanDrawingException::class);
});
