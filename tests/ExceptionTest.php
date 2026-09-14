<?php

declare(strict_types=1);

use Jovian\Bindings\Vulkan\Enums\VkResult;
use Jovian\Venusian\Vulkan\Exceptions\VulkanDrawingException;
use Surface\Contracts\Drawing\DrawingException;

it('is a DrawingException', function () {
    expect(new VulkanDrawingException('x'))->toBeInstanceOf(DrawingException::class);
});

it('names a VkResult case on result()', function () {
    $exception = VulkanDrawingException::result('vkCreateInstance', VkResult::ERROR_OUT_OF_HOST_MEMORY);

    expect($exception->getMessage())
        ->toContain('vkCreateInstance')
        ->toContain('ERROR_OUT_OF_HOST_MEMORY');
});

it('names a VkResult from a raw integer code', function () {
    $exception = VulkanDrawingException::result('vkQueuePresentKHR', VkResult::ERROR_OUT_OF_DATE_KHR->value);

    expect($exception->getMessage())->toContain('ERROR_OUT_OF_DATE_KHR');
});

it('falls back to the raw code when VkResult has no case', function () {
    $exception = VulkanDrawingException::result('vkFoo', 123456);

    expect($exception->getMessage())
        ->toContain('vkFoo')
        ->toContain('123456');
});

it('unwraps VkResult through code()', function () {
    expect(VulkanDrawingException::code(VkResult::SUCCESS))->toBe(0)
        ->and(VulkanDrawingException::code(VkResult::ERROR_DEVICE_LOST))->toBe(-4)
        ->and(VulkanDrawingException::code(-4))->toBe(-4);
});

it('check() is silent on SUCCESS and throws otherwise', function () {
    VulkanDrawingException::check(VkResult::SUCCESS, 'vkCreateInstance');
    VulkanDrawingException::check(0, 'vkCreateInstance');

    expect(fn () => VulkanDrawingException::check(VkResult::ERROR_DEVICE_LOST, 'vkCreateDevice'))
        ->toThrow(VulkanDrawingException::class)
        ->and(fn () => VulkanDrawingException::check(-4, 'vkCreateDevice'))
        ->toThrow(VulkanDrawingException::class);
});

it('builds the named factories', function () {
    expect(VulkanDrawingException::loader()->getMessage())->toContain('load')
        ->and(VulkanDrawingException::noDevice()->getMessage())->toContain('device')
        ->and(VulkanDrawingException::noHostForPlatform()->getMessage())->toContain('host')
        ->and(VulkanDrawingException::missingExtension('VK_KHR_swapchain')->getMessage())->toContain('VK_KHR_swapchain')
        ->and(VulkanDrawingException::instanceExtensionMismatch()->getMessage())->toContain('extension')
        ->and(VulkanDrawingException::texturesExhausted()->getMessage())->toContain('exhaust')
        ->and(VulkanDrawingException::textureSize(8192, 8192)->getMessage())->toContain('8192')
        ->and(VulkanDrawingException::noMemoryType()->getMessage())->toContain('memory')
        ->and(VulkanDrawingException::allocation()->getMessage())->toContain('alloc')
        ->and(VulkanDrawingException::spirv('bad magic')->getMessage())->toContain('bad magic')
        ->and(VulkanDrawingException::pushConstantsTooSmall(32)->getMessage())->toContain('32')
        ->and(VulkanDrawingException::released()->getMessage())->toContain('released')
        ->and(VulkanDrawingException::outOfFrame('readPixels()')->getMessage())->toContain('readPixels()');
});
