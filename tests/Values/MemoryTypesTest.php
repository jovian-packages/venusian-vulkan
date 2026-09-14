<?php

declare(strict_types=1);

use Jovian\Bindings\Vulkan\Enums\VkMemoryPropertyFlagBits;
use Jovian\Bindings\Vulkan\Structs\VkMemoryType;
use Jovian\Bindings\Vulkan\Structs\VkPhysicalDeviceMemoryProperties;
use Jovian\Venusian\Vulkan\Values\MemoryTypes;

function vulkanMemoryProperties(array $propertyFlags): VkPhysicalDeviceMemoryProperties
{
    $types = [];
    foreach ($propertyFlags as $index => $flags) {
        $types[$index] = new VkMemoryType(propertyFlags: $flags, heapIndex: 0);
    }

    return new VkPhysicalDeviceMemoryProperties(
        memoryTypeCount: count($types),
        memoryTypes: $types,
    );
}

/** MoltenVK on Apple silicon: three types, device-local and two host-visible flavours. */
function vulkanMacMemoryProperties(): VkPhysicalDeviceMemoryProperties
{
    return vulkanMemoryProperties([
        VkMemoryPropertyFlagBits::DEVICE_LOCAL_BIT->value,
        VkMemoryPropertyFlagBits::DEVICE_LOCAL_BIT->value
            | VkMemoryPropertyFlagBits::HOST_VISIBLE_BIT->value
            | VkMemoryPropertyFlagBits::HOST_COHERENT_BIT->value
            | VkMemoryPropertyFlagBits::HOST_CACHED_BIT->value,
        VkMemoryPropertyFlagBits::DEVICE_LOCAL_BIT->value
            | VkMemoryPropertyFlagBits::HOST_VISIBLE_BIT->value
            | VkMemoryPropertyFlagBits::HOST_COHERENT_BIT->value,
    ]);
}

/** Mesa V3D on the Pi: one type that is a superset of every flag we ask for. */
function vulkanPiMemoryProperties(): VkPhysicalDeviceMemoryProperties
{
    return vulkanMemoryProperties([
        VkMemoryPropertyFlagBits::DEVICE_LOCAL_BIT->value
            | VkMemoryPropertyFlagBits::HOST_VISIBLE_BIT->value
            | VkMemoryPropertyFlagBits::HOST_COHERENT_BIT->value
            | VkMemoryPropertyFlagBits::HOST_CACHED_BIT->value,
    ]);
}

/** lavapipe / llvmpipe: one type, device-local and host-visible, no HOST_CACHED. */
function vulkanLlvmpipeMemoryProperties(): VkPhysicalDeviceMemoryProperties
{
    return vulkanMemoryProperties([
        VkMemoryPropertyFlagBits::DEVICE_LOCAL_BIT->value
            | VkMemoryPropertyFlagBits::HOST_VISIBLE_BIT->value
            | VkMemoryPropertyFlagBits::HOST_COHERENT_BIT->value,
    ]);
}

function vulkanHostVisibleCoherent(): int
{
    return VkMemoryPropertyFlagBits::HOST_VISIBLE_BIT->value
        | VkMemoryPropertyFlagBits::HOST_COHERENT_BIT->value;
}

it('selects the first Mac type that is a subset match', function () {
    $types = MemoryTypes::fromProperties(vulkanMacMemoryProperties());

    expect($types->select(0b111, VkMemoryPropertyFlagBits::DEVICE_LOCAL_BIT->value))->toBe(0)
        ->and($types->select(0b111, vulkanHostVisibleCoherent()))->toBe(1)
        ->and($types->select(0b100, vulkanHostVisibleCoherent()))->toBe(2);
});

it('honours memoryTypeBits on the Mac table', function () {
    $types = MemoryTypes::fromProperties(vulkanMacMemoryProperties());

    expect($types->select(0b001, vulkanHostVisibleCoherent()))->toBe(-1)
        ->and($types->select(0b010, VkMemoryPropertyFlagBits::DEVICE_LOCAL_BIT->value))->toBe(1)
        ->and($types->select(0, vulkanHostVisibleCoherent()))->toBe(-1);
});

it('selects the Pi type by subset, not equality — the equality trap', function () {
    $types = MemoryTypes::fromProperties(vulkanPiMemoryProperties());
    $required = vulkanHostVisibleCoherent();
    $flags = vulkanPiMemoryProperties()->memoryTypes[0]->propertyFlags;

    expect($flags === $required)->toBeFalse()
        ->and(($flags & $required) === $required)->toBeTrue()
        ->and($types->select(0b1, $required))->toBe(0)
        ->and($types->select(0b1, VkMemoryPropertyFlagBits::DEVICE_LOCAL_BIT->value))->toBe(0)
        ->and($types->select(0b1, VkMemoryPropertyFlagBits::HOST_CACHED_BIT->value))->toBe(0);
});

it('selects the llvmpipe type and refuses flags it does not carry', function () {
    $types = MemoryTypes::fromProperties(vulkanLlvmpipeMemoryProperties());

    expect($types->select(0b1, vulkanHostVisibleCoherent()))->toBe(0)
        ->and($types->select(0b1, VkMemoryPropertyFlagBits::DEVICE_LOCAL_BIT->value))->toBe(0)
        ->and($types->select(0b1, VkMemoryPropertyFlagBits::HOST_CACHED_BIT->value))->toBe(-1);
});

it('returns -1 when no type matches', function () {
    $types = MemoryTypes::fromProperties(vulkanMemoryProperties([
        VkMemoryPropertyFlagBits::DEVICE_LOCAL_BIT->value,
    ]));

    expect($types->select(0b1, vulkanHostVisibleCoherent()))->toBe(-1)
        ->and($types->select(0b1, VkMemoryPropertyFlagBits::LAZILY_ALLOCATED_BIT->value))->toBe(-1);
});
