<?php

declare(strict_types=1);

use Jovian\Bindings\Vulkan\Enums\VkResult;
use Jovian\Bindings\Vulkan\Runtime\Bridge;
use Jovian\Bindings\Vulkan\Structs\VkApplicationInfo;
use Jovian\Venusian\Vulkan\Enums\Budget;
use Jovian\Venusian\Vulkan\Exceptions\VulkanDrawingException;
use Jovian\Venusian\Vulkan\Support\Blocks;

beforeEach(function () {
    if (! vulkanExtensionLoaded()) {
        test()->markTestSkipped('ext-vulkan is not loaded');
    }

    Bridge::load();
});

it('allocates, writes, and reads a tracked block', function () {
    $blocks = new Blocks;
    $ptr = $blocks->alloc(8);

    expect($ptr)->toBeGreaterThan(0);
    expect(Bridge::write($ptr, 0, pack('P', 0x1122334455667788)))->toBeTrue();
    expect(Blocks::handleAt($ptr))->toBe(0x1122334455667788);

    $blocks->release();
});

it('keep() tracks a packed pointer and refuses 0', function () {
    $blocks = new Blocks;
    $packed = (new VkApplicationInfo)->pack();
    $kept = $blocks->keep($packed);

    expect($kept)->toBeGreaterThan(0)->and($kept)->toBe($packed);
    expect(fn () => $blocks->keep(0))->toThrow(VulkanDrawingException::class);

    $blocks->release();
});

it('builds cstrings as a pointer array', function () {
    $blocks = new Blocks;
    $array = $blocks->cstrings(['VK_KHR_surface', 'VK_EXT_metal_surface']);

    $first = Blocks::handleAt($array, 0);
    $second = Blocks::handleAt($array, 1);

    expect(Bridge::readCString($first))->toBe('VK_KHR_surface')
        ->and(Bridge::readCString($second))->toBe('VK_EXT_metal_surface');

    $blocks->release();
});

it('packs handles and uint32s', function () {
    $blocks = new Blocks;
    $handles = $blocks->handles([1, 2, 3]);
    $counts = $blocks->uint32s([7, 9]);

    expect(Blocks::handleAt($handles, 0))->toBe(1)
        ->and(Blocks::handleAt($handles, 1))->toBe(2)
        ->and(Blocks::handleAt($handles, 2))->toBe(3)
        ->and(Blocks::countAt($counts))->toBe(7)
        ->and(unpack('V', (string) Bridge::read($counts, Budget::COUNT_BYTES->value, Budget::COUNT_BYTES->value))[1])->toBe(9);

    $blocks->release();
});

it('create() writes an out-handle and checks VkResult', function () {
    $blocks = new Blocks;

    $handle = $blocks->create('fakeCreate', function (int $out): int {
        Bridge::write($out, 0, pack('P', 0xABC));

        return VkResult::SUCCESS->value;
    });

    expect($handle)->toBe(0xABC);

    expect(fn () => $blocks->create('fakeFail', fn (int $out): int => VkResult::ERROR_DEVICE_LOST->value))
        ->toThrow(VulkanDrawingException::class);

    $blocks->release();
});

it('create() refuses a null handle after SUCCESS', function () {
    $blocks = new Blocks;

    expect(fn () => $blocks->create('nullHandle', function (int $out): int {
        Bridge::write($out, 0, pack('P', 0));

        return VkResult::SUCCESS->value;
    }))->toThrow(VulkanDrawingException::class);

    $blocks->release();
});
