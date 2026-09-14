<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Targets;

use Jovian\Bindings\Vulkan\Enums\VkComponentSwizzle;
use Jovian\Bindings\Vulkan\Enums\VkFormat;
use Jovian\Bindings\Vulkan\Enums\VkImageAspectFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkImageUsageFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkImageViewType;
use Jovian\Bindings\Vulkan\Enums\VkPresentModeKHR;
use Jovian\Bindings\Vulkan\Enums\VkSharingMode;
use Jovian\Bindings\Vulkan\Ext\KHRSurface;
use Jovian\Bindings\Vulkan\Ext\KHRSwapchain;
use Jovian\Bindings\Vulkan\Runtime\Bridge;
use Jovian\Bindings\Vulkan\Structs\VkComponentMapping;
use Jovian\Bindings\Vulkan\Structs\VkExtent2D;
use Jovian\Bindings\Vulkan\Structs\VkFramebufferCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkImageSubresourceRange;
use Jovian\Bindings\Vulkan\Structs\VkImageViewCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkSurfaceCapabilitiesKHR;
use Jovian\Bindings\Vulkan\Structs\VkSurfaceFormatKHR;
use Jovian\Bindings\Vulkan\Structs\VkSwapchainCreateInfoKHR;
use Jovian\Bindings\Vulkan\VK\VK10;
use Jovian\Venusian\Vulkan\Enums\Budget;
use Jovian\Venusian\Vulkan\Exceptions\VulkanDrawingException;
use Jovian\Venusian\Vulkan\Support\Blocks;
use Jovian\Venusian\Vulkan\Values\SwapchainChoice;
use Jovian\Venusian\Vulkan\VulkanContext;

/**
 * Swapchain images, views, and the framebuffers bound to one render pass.
 */
final class Swapchain
{
    /**
     * @param  list<int>  $images
     * @param  list<int>  $views
     * @param  list<int>  $framebuffers
     */
    public function __construct(
        public readonly int $handle,
        public readonly SwapchainChoice $choice,
        public readonly array $images,
        public readonly array $views,
        public array $framebuffers,
        private readonly Blocks $blocks,
    ) {}

    public static function query(VulkanContext $ctx, int $surface, int $hostW, int $hostH): SwapchainChoice
    {
        $blocks = new Blocks;
        $supported = $blocks->alloc(Budget::COUNT_BYTES->value);
        VulkanDrawingException::check(
            KHRSurface::vkGetPhysicalDeviceSurfaceSupportKHR(
                $ctx->physicalDevice,
                $ctx->queueFamily,
                $surface,
                $supported,
            ),
            'vkGetPhysicalDeviceSurfaceSupportKHR',
        );
        if (Blocks::countAt($supported) === 0) {
            throw new VulkanDrawingException('surface is not presentable on the graphics queue');
        }

        $capsBlock = $blocks->alloc(VkSurfaceCapabilitiesKHR::size());
        VulkanDrawingException::check(
            KHRSurface::vkGetPhysicalDeviceSurfaceCapabilitiesKHR($ctx->physicalDevice, $surface, $capsBlock),
            'vkGetPhysicalDeviceSurfaceCapabilitiesKHR',
        );
        $caps = VkSurfaceCapabilitiesKHR::unpack($capsBlock);

        $count = $blocks->alloc(Budget::COUNT_BYTES->value);
        VulkanDrawingException::check(
            KHRSurface::vkGetPhysicalDeviceSurfaceFormatsKHR($ctx->physicalDevice, $surface, $count, 0),
            'vkGetPhysicalDeviceSurfaceFormatsKHR',
        );
        $formatCount = Blocks::countAt($count);
        if ($formatCount === 0) {
            throw new VulkanDrawingException('no VkSurfaceFormatKHR offered');
        }
        $formatList = $blocks->alloc($formatCount * VkSurfaceFormatKHR::size());
        VulkanDrawingException::check(
            KHRSurface::vkGetPhysicalDeviceSurfaceFormatsKHR($ctx->physicalDevice, $surface, $count, $formatList),
            'vkGetPhysicalDeviceSurfaceFormatsKHR',
        );
        $formats = [];
        for ($i = 0; $i < $formatCount; $i++) {
            $formats[] = VkSurfaceFormatKHR::unpack($formatList + $i * VkSurfaceFormatKHR::size());
        }

        VulkanDrawingException::check(
            KHRSurface::vkGetPhysicalDeviceSurfacePresentModesKHR($ctx->physicalDevice, $surface, $count, 0),
            'vkGetPhysicalDeviceSurfacePresentModesKHR',
        );
        $modeCount = Blocks::countAt($count);
        $modes = [];
        if ($modeCount > 0) {
            $modeList = $blocks->alloc($modeCount * Budget::COUNT_BYTES->value);
            VulkanDrawingException::check(
                KHRSurface::vkGetPhysicalDeviceSurfacePresentModesKHR($ctx->physicalDevice, $surface, $count, $modeList),
                'vkGetPhysicalDeviceSurfacePresentModesKHR',
            );
            for ($i = 0; $i < $modeCount; $i++) {
                $raw = unpack('V', (string) Bridge::read(
                    $modeList,
                    $i * Budget::COUNT_BYTES->value,
                    Budget::COUNT_BYTES->value,
                ))[1];
                $modes[] = VkPresentModeKHR::tryFrom($raw) ?? $raw;
            }
        }

        $choice = SwapchainChoice::pick($caps, $formats, $modes, $hostW, $hostH);
        $blocks->release();

        return $choice;
    }

    public static function create(
        VulkanContext $ctx,
        int $surface,
        SwapchainChoice $choice,
        int $old = 0,
    ): self {
        $blocks = new Blocks;
        $info = $blocks->keep((new VkSwapchainCreateInfoKHR(
            surface: $surface,
            minImageCount: $choice->imageCount,
            imageFormat: $choice->format,
            imageColorSpace: $choice->colorSpace,
            imageExtent: new VkExtent2D(width: $choice->width, height: $choice->height),
            imageArrayLayers: 1,
            imageUsage: VkImageUsageFlagBits::COLOR_ATTACHMENT_BIT->value
                | VkImageUsageFlagBits::TRANSFER_SRC_BIT->value,
            imageSharingMode: VkSharingMode::EXCLUSIVE,
            preTransform: $choice->preTransform,
            compositeAlpha: $choice->compositeAlpha,
            presentMode: $choice->presentMode,
            clipped: true,
            oldSwapchain: $old,
        ))->pack());

        $handle = $blocks->create(
            'vkCreateSwapchainKHR',
            static fn (int $out) => KHRSwapchain::vkCreateSwapchainKHR($ctx->device, $info, 0, $out),
        );

        $count = $blocks->alloc(Budget::COUNT_BYTES->value);
        VulkanDrawingException::check(
            KHRSwapchain::vkGetSwapchainImagesKHR($ctx->device, $handle, $count, 0),
            'vkGetSwapchainImagesKHR',
        );
        $n = Blocks::countAt($count);
        $imageList = $blocks->alloc(max(1, $n) * Budget::HANDLE_BYTES->value);
        VulkanDrawingException::check(
            KHRSwapchain::vkGetSwapchainImagesKHR($ctx->device, $handle, $count, $imageList),
            'vkGetSwapchainImagesKHR',
        );

        $images = [];
        $views = [];
        for ($i = 0; $i < $n; $i++) {
            $image = Blocks::handleAt($imageList, $i);
            $images[] = $image;
            $viewInfo = $blocks->keep((new VkImageViewCreateInfo(
                image: $image,
                viewType: VkImageViewType::TYPE_2D,
                format: $choice->format,
                components: new VkComponentMapping(
                    r: VkComponentSwizzle::IDENTITY,
                    g: VkComponentSwizzle::IDENTITY,
                    b: VkComponentSwizzle::IDENTITY,
                    a: VkComponentSwizzle::IDENTITY,
                ),
                subresourceRange: new VkImageSubresourceRange(
                    aspectMask: VkImageAspectFlagBits::COLOR_BIT->value,
                    baseMipLevel: 0,
                    levelCount: 1,
                    baseArrayLayer: 0,
                    layerCount: 1,
                ),
            ))->pack());
            $views[] = $blocks->create(
                'vkCreateImageView',
                static fn (int $out) => VK10::vkCreateImageView($ctx->device, $viewInfo, 0, $out),
            );
        }

        return new self($handle, $choice, $images, $views, [], $blocks);
    }

    public function attachFramebuffers(VulkanContext $ctx, int $pass): void
    {
        $this->destroyFramebuffers($ctx->device);
        foreach ($this->views as $view) {
            $attachments = $this->blocks->handles([$view]);
            $info = $this->blocks->keep((new VkFramebufferCreateInfo(
                renderPass: $pass,
                attachmentCount: 1,
                pAttachments: $attachments,
                width: $this->choice->width,
                height: $this->choice->height,
                layers: 1,
            ))->pack());
            $this->framebuffers[] = $this->blocks->create(
                'vkCreateFramebuffer',
                static fn (int $out) => VK10::vkCreateFramebuffer($ctx->device, $info, 0, $out),
            );
        }
    }

    public function isBgra(): bool
    {
        $format = $this->choice->format;

        return ($format instanceof VkFormat ? $format->value : (int) $format)
            === VkFormat::B8G8R8A8_UNORM->value;
    }

    public function destroy(VulkanContext $ctx): void
    {
        $this->destroyFramebuffers($ctx->device);
        foreach ($this->views as $view) {
            if ($view !== 0) {
                VK10::vkDestroyImageView($ctx->device, $view, 0);
            }
        }
        if ($this->handle !== 0) {
            KHRSwapchain::vkDestroySwapchainKHR($ctx->device, $this->handle, 0);
        }
        $this->blocks->release();
    }

    private function destroyFramebuffers(int $device): void
    {
        foreach ($this->framebuffers as $framebuffer) {
            if ($framebuffer !== 0) {
                VK10::vkDestroyFramebuffer($device, $framebuffer, 0);
            }
        }
        $this->framebuffers = [];
    }
}
