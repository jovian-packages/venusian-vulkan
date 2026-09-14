<?php

declare(strict_types=1);

use Jovian\Bindings\Vulkan\Enums\VkColorSpaceKHR;
use Jovian\Bindings\Vulkan\Enums\VkCompositeAlphaFlagBitsKHR;
use Jovian\Bindings\Vulkan\Enums\VkFormat;
use Jovian\Bindings\Vulkan\Enums\VkPresentModeKHR;
use Jovian\Bindings\Vulkan\Enums\VkSurfaceTransformFlagBitsKHR;
use Jovian\Bindings\Vulkan\Structs\VkExtent2D;
use Jovian\Bindings\Vulkan\Structs\VkSurfaceCapabilitiesKHR;
use Jovian\Bindings\Vulkan\Structs\VkSurfaceFormatKHR;
use Jovian\Venusian\Vulkan\Enums\SurfaceSentinel;
use Jovian\Venusian\Vulkan\Values\SwapchainChoice;

function vulkanCaps(
    int $minImageCount = 1,
    int $maxImageCount = 0,
    ?VkExtent2D $currentExtent = null,
    ?VkExtent2D $minImageExtent = null,
    ?VkExtent2D $maxImageExtent = null,
    int $supportedCompositeAlpha = 1,
    int $currentTransform = 1,
): VkSurfaceCapabilitiesKHR {
    return new VkSurfaceCapabilitiesKHR(
        minImageCount: $minImageCount,
        maxImageCount: $maxImageCount,
        currentExtent: $currentExtent ?? new VkExtent2D(800, 600),
        minImageExtent: $minImageExtent ?? new VkExtent2D(1, 1),
        maxImageExtent: $maxImageExtent ?? new VkExtent2D(4096, 4096),
        maxImageArrayLayers: 1,
        supportedTransforms: VkSurfaceTransformFlagBitsKHR::IDENTITY_BIT_KHR->value,
        currentTransform: $currentTransform,
        supportedCompositeAlpha: $supportedCompositeAlpha,
        supportedUsageFlags: 0,
    );
}

function vulkanFormat(VkFormat $format, VkColorSpaceKHR $space = VkColorSpaceKHR::SRGB_NONLINEAR_KHR): VkSurfaceFormatKHR
{
    return new VkSurfaceFormatKHR(format: $format, colorSpace: $space);
}

it('prefers B8G8R8A8_UNORM over R8G8B8A8_UNORM', function () {
    $choice = SwapchainChoice::pick(
        vulkanCaps(),
        [
            vulkanFormat(VkFormat::R8G8B8A8_UNORM),
            vulkanFormat(VkFormat::B8G8R8A8_UNORM),
        ],
        [VkPresentModeKHR::MAILBOX_KHR, VkPresentModeKHR::FIFO_KHR],
        640,
        480,
    );

    expect($choice->format)->toBe(VkFormat::B8G8R8A8_UNORM)
        ->and($choice->colorSpace)->toBe(VkColorSpaceKHR::SRGB_NONLINEAR_KHR);
});

it('falls back to R8G8B8A8_UNORM when BGRA is absent', function () {
    $choice = SwapchainChoice::pick(
        vulkanCaps(),
        [vulkanFormat(VkFormat::R5G6B5_UNORM_PACK16), vulkanFormat(VkFormat::R8G8B8A8_UNORM)],
        [VkPresentModeKHR::FIFO_KHR],
        640,
        480,
    );

    expect($choice->format)->toBe(VkFormat::R8G8B8A8_UNORM);
});

it('falls back to the first format when neither preferred format is offered', function () {
    $choice = SwapchainChoice::pick(
        vulkanCaps(),
        [vulkanFormat(VkFormat::B8G8R8A8_SRGB), vulkanFormat(VkFormat::R8G8B8A8_SRGB)],
        [VkPresentModeKHR::FIFO_KHR],
        640,
        480,
    );

    expect($choice->format)->toBe(VkFormat::B8G8R8A8_SRGB);
});

it('uses currentExtent when the surface reports a defined size', function () {
    $choice = SwapchainChoice::pick(
        vulkanCaps(currentExtent: new VkExtent2D(800, 600)),
        [vulkanFormat(VkFormat::B8G8R8A8_UNORM)],
        [VkPresentModeKHR::FIFO_KHR],
        1920,
        1080,
    );

    expect($choice->width)->toBe(800)
        ->and($choice->height)->toBe(600);
});

it('prefers host pixels when currentExtent is the 1x point size of a retina host', function () {
    $choice = SwapchainChoice::pick(
        vulkanCaps(currentExtent: new VkExtent2D(400, 300)),
        [vulkanFormat(VkFormat::B8G8R8A8_UNORM)],
        [VkPresentModeKHR::FIFO_KHR],
        800,
        600,
    );

    expect($choice->width)->toBe(800)
        ->and($choice->height)->toBe(600)
        ->and(SwapchainChoice::hostIsRetinaMultiple(400, 300, 800, 600))->toBeTrue()
        ->and(SwapchainChoice::hostIsRetinaMultiple(800, 600, 1920, 1080))->toBeFalse();
});

it('clamps the host size when currentExtent is undefined', function () {
    $undefined = new VkExtent2D(
        SurfaceSentinel::EXTENT_UNDEFINED->value,
        SurfaceSentinel::EXTENT_UNDEFINED->value,
    );

    $fits = SwapchainChoice::pick(
        vulkanCaps(
            currentExtent: $undefined,
            minImageExtent: new VkExtent2D(64, 64),
            maxImageExtent: new VkExtent2D(4096, 4096),
        ),
        [vulkanFormat(VkFormat::B8G8R8A8_UNORM)],
        [VkPresentModeKHR::FIFO_KHR],
        1920,
        1080,
    );

    $clamped = SwapchainChoice::pick(
        vulkanCaps(
            currentExtent: $undefined,
            minImageExtent: new VkExtent2D(64, 64),
            maxImageExtent: new VkExtent2D(4096, 4096),
        ),
        [vulkanFormat(VkFormat::B8G8R8A8_UNORM)],
        [VkPresentModeKHR::FIFO_KHR],
        16,
        8000,
    );

    expect($fits->width)->toBe(1920)
        ->and($fits->height)->toBe(1080)
        ->and($clamped->width)->toBe(64)
        ->and($clamped->height)->toBe(4096);
});

it('treats a zero currentExtent as undefined and uses the clamped host size', function () {
    $choice = SwapchainChoice::pick(
        vulkanCaps(currentExtent: new VkExtent2D(0, 0)),
        [vulkanFormat(VkFormat::B8G8R8A8_UNORM)],
        [VkPresentModeKHR::FIFO_KHR],
        64,
        64,
    );

    expect($choice->width)->toBe(64)
        ->and($choice->height)->toBe(64);
});

it('asks for two images, clamped to max unless max is unbounded', function () {
    $unbounded = SwapchainChoice::pick(
        vulkanCaps(minImageCount: 1, maxImageCount: SurfaceSentinel::IMAGE_COUNT_UNBOUNDED->value),
        [vulkanFormat(VkFormat::B8G8R8A8_UNORM)],
        [VkPresentModeKHR::FIFO_KHR],
        640,
        480,
    );
    $tight = SwapchainChoice::pick(
        vulkanCaps(minImageCount: 1, maxImageCount: 1),
        [vulkanFormat(VkFormat::B8G8R8A8_UNORM)],
        [VkPresentModeKHR::FIFO_KHR],
        640,
        480,
    );
    $minThree = SwapchainChoice::pick(
        vulkanCaps(minImageCount: 3, maxImageCount: 0),
        [vulkanFormat(VkFormat::B8G8R8A8_UNORM)],
        [VkPresentModeKHR::FIFO_KHR],
        640,
        480,
    );

    expect($unbounded->imageCount)->toBe(2)
        ->and($tight->imageCount)->toBe(1)
        ->and($minThree->imageCount)->toBe(3);
});

it('always picks FIFO even when mailbox is offered', function () {
    $choice = SwapchainChoice::pick(
        vulkanCaps(),
        [vulkanFormat(VkFormat::B8G8R8A8_UNORM)],
        [VkPresentModeKHR::IMMEDIATE_KHR, VkPresentModeKHR::MAILBOX_KHR, VkPresentModeKHR::FIFO_KHR],
        640,
        480,
    );

    expect($choice->presentMode)->toBe(VkPresentModeKHR::FIFO_KHR);
});

it('prefers OPAQUE composite alpha and otherwise the first supported bit', function () {
    $opaque = SwapchainChoice::pick(
        vulkanCaps(supportedCompositeAlpha: VkCompositeAlphaFlagBitsKHR::OPAQUE_BIT_KHR->value
            | VkCompositeAlphaFlagBitsKHR::PRE_MULTIPLIED_BIT_KHR->value),
        [vulkanFormat(VkFormat::B8G8R8A8_UNORM)],
        [VkPresentModeKHR::FIFO_KHR],
        640,
        480,
    );
    $firstBit = SwapchainChoice::pick(
        vulkanCaps(supportedCompositeAlpha: VkCompositeAlphaFlagBitsKHR::PRE_MULTIPLIED_BIT_KHR->value
            | VkCompositeAlphaFlagBitsKHR::INHERIT_BIT_KHR->value),
        [vulkanFormat(VkFormat::B8G8R8A8_UNORM)],
        [VkPresentModeKHR::FIFO_KHR],
        640,
        480,
    );

    expect($opaque->compositeAlpha)->toBe(VkCompositeAlphaFlagBitsKHR::OPAQUE_BIT_KHR->value)
        ->and($firstBit->compositeAlpha)->toBe(VkCompositeAlphaFlagBitsKHR::PRE_MULTIPLIED_BIT_KHR->value);
});

it('passes currentTransform through', function () {
    $choice = SwapchainChoice::pick(
        vulkanCaps(currentTransform: VkSurfaceTransformFlagBitsKHR::ROTATE_90_BIT_KHR->value),
        [vulkanFormat(VkFormat::B8G8R8A8_UNORM)],
        [VkPresentModeKHR::FIFO_KHR],
        640,
        480,
    );

    expect($choice->preTransform)->toBe(VkSurfaceTransformFlagBitsKHR::ROTATE_90_BIT_KHR->value);
});
