<?php

declare(strict_types=1);

use Jovian\Bindings\Vulkan\Enums\VkFormat;
use Jovian\Venusian\Vulkan\Enums\Budget;
use Jovian\Venusian\Vulkan\Enums\DynamicState;
use Jovian\Venusian\Vulkan\Enums\PainterAttribute;
use Jovian\Venusian\Vulkan\Enums\QueueFamily;
use Jovian\Venusian\Vulkan\Enums\Sentinel;
use Jovian\Venusian\Vulkan\Enums\SurfaceSentinel;
use Jovian\Venusian\Vulkan\Enums\Timeout;

it('maps the Surface vertex to Vulkan formats, offsets, and a 36-byte stride', function () {
    expect(PainterAttribute::POSITION->value)->toBe(0)
        ->and(PainterAttribute::COLOR->value)->toBe(1)
        ->and(PainterAttribute::UV->value)->toBe(2)
        ->and(PainterAttribute::POSITION->format())->toBe(VkFormat::R32G32B32_SFLOAT)
        ->and(PainterAttribute::COLOR->format())->toBe(VkFormat::R32G32B32A32_SFLOAT)
        ->and(PainterAttribute::UV->format())->toBe(VkFormat::R32G32_SFLOAT)
        ->and(PainterAttribute::POSITION->offset())->toBe(0)
        ->and(PainterAttribute::COLOR->offset())->toBe(12)
        ->and(PainterAttribute::UV->offset())->toBe(28)
        ->and(PainterAttribute::stride())->toBe(36)
        ->and(PainterAttribute::stride())->toBe(strlen(pack('g9', 0, 0, 0, 0, 0, 0, 0, 0, 0)));
});

it('hand-writes the missing VkDynamicState cases', function () {
    expect(DynamicState::VIEWPORT->value)->toBe(0)
        ->and(DynamicState::SCISSOR->value)->toBe(1);
});

it('keeps colliding Vulkan sentinels in separate enums', function () {
    expect(Sentinel::SUBPASS_EXTERNAL->value)->toBe(4294967295)
        ->and(Sentinel::WHOLE_SIZE->value)->toBe(-1)
        ->and(QueueFamily::IGNORED->value)->toBe(4294967295)
        ->and(SurfaceSentinel::EXTENT_UNDEFINED->value)->toBe(4294967295)
        ->and(SurfaceSentinel::IMAGE_COUNT_UNBOUNDED->value)->toBe(0)
        ->and(Timeout::FOREVER->value)->toBe(-1);
});

it('records the allocator and descriptor budgets', function () {
    expect(Budget::STAGING_INITIAL_BYTES->value)->toBe(262144)
        ->and(Budget::DESCRIPTOR_SETS->value)->toBe(256)
        ->and(Budget::PUSH_CONSTANT_BYTES->value)->toBe(64)
        ->and(Budget::HANDLE_BYTES->value)->toBe(8)
        ->and(Budget::COUNT_BYTES->value)->toBe(4);
});

it('does not ship StagingRegion — Vulkan stages by cursor', function () {
    expect(class_exists(\Jovian\Venusian\Vulkan\Enums\StagingRegion::class))->toBeFalse();
});
