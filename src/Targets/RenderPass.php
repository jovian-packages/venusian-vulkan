<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Targets;

use Jovian\Bindings\Vulkan\Enums\VkAccessFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkAttachmentLoadOp;
use Jovian\Bindings\Vulkan\Enums\VkAttachmentStoreOp;
use Jovian\Bindings\Vulkan\Enums\VkFormat;
use Jovian\Bindings\Vulkan\Enums\VkImageLayout;
use Jovian\Bindings\Vulkan\Enums\VkPipelineBindPoint;
use Jovian\Bindings\Vulkan\Enums\VkPipelineStageFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkSampleCountFlagBits;
use Jovian\Bindings\Vulkan\Structs\VkAttachmentDescription;
use Jovian\Bindings\Vulkan\Structs\VkAttachmentReference;
use Jovian\Bindings\Vulkan\Structs\VkRenderPassCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkSubpassDependency;
use Jovian\Bindings\Vulkan\Structs\VkSubpassDescription;
use Jovian\Bindings\Vulkan\VK\VK10;
use Jovian\Venusian\Vulkan\Enums\Sentinel;
use Jovian\Venusian\Vulkan\Support\Blocks;
use Jovian\Venusian\Vulkan\VulkanContext;

/**
 * The two compatible colour passes one executor shares: clear for
 * beginFrame(), load for the post-readPixels reopen.
 */
final class RenderPass
{
    public function __construct(
        public readonly VkFormat|int $format,
        public readonly int $clear,
        public readonly int $load,
        private readonly Blocks $blocks,
    ) {}

    public static function create(VulkanContext $ctx, VkFormat|int $format): self
    {
        $blocks = new Blocks;
        $clear = self::createPass(
            $blocks,
            $ctx->device,
            $format,
            VkAttachmentLoadOp::CLEAR,
            VkImageLayout::UNDEFINED,
            VkPipelineStageFlagBits::COLOR_ATTACHMENT_OUTPUT_BIT->value,
            0,
        );
        $load = self::createPass(
            $blocks,
            $ctx->device,
            $format,
            VkAttachmentLoadOp::LOAD,
            VkImageLayout::TRANSFER_SRC_OPTIMAL,
            VkPipelineStageFlagBits::TRANSFER_BIT->value
                | VkPipelineStageFlagBits::COLOR_ATTACHMENT_OUTPUT_BIT->value,
            VkAccessFlagBits::TRANSFER_READ_BIT->value,
        );

        return new self($format, $clear, $load, $blocks);
    }

    public function destroy(int $device): void
    {
        if ($this->clear !== 0) {
            VK10::vkDestroyRenderPass($device, $this->clear, 0);
        }
        if ($this->load !== 0) {
            VK10::vkDestroyRenderPass($device, $this->load, 0);
        }
        $this->blocks->release();
    }

    private static function createPass(
        Blocks $blocks,
        int $device,
        VkFormat|int $format,
        VkAttachmentLoadOp $loadOp,
        VkImageLayout $initialLayout,
        int $srcStage,
        int $srcAccess,
    ): int {
        $attachments = $blocks->alloc(VkAttachmentDescription::size());
        (new VkAttachmentDescription(
            format: $format,
            samples: VkSampleCountFlagBits::COUNT_1_BIT,
            loadOp: $loadOp,
            storeOp: VkAttachmentStoreOp::STORE,
            stencilLoadOp: VkAttachmentLoadOp::DONT_CARE,
            stencilStoreOp: VkAttachmentStoreOp::DONT_CARE,
            initialLayout: $initialLayout,
            finalLayout: VkImageLayout::PRESENT_SRC_KHR,
        ))->packInto($attachments);

        $colorRefs = $blocks->alloc(VkAttachmentReference::size());
        (new VkAttachmentReference(
            attachment: 0,
            layout: VkImageLayout::COLOR_ATTACHMENT_OPTIMAL,
        ))->packInto($colorRefs);

        $subpasses = $blocks->alloc(VkSubpassDescription::size());
        (new VkSubpassDescription(
            pipelineBindPoint: VkPipelineBindPoint::GRAPHICS,
            colorAttachmentCount: 1,
            pColorAttachments: $colorRefs,
        ))->packInto($subpasses);

        $dependencies = $blocks->alloc(VkSubpassDependency::size());
        (new VkSubpassDependency(
            srcSubpass: Sentinel::SUBPASS_EXTERNAL->value,
            dstSubpass: 0,
            srcStageMask: $srcStage,
            dstStageMask: VkPipelineStageFlagBits::COLOR_ATTACHMENT_OUTPUT_BIT->value,
            srcAccessMask: $srcAccess,
            dstAccessMask: $loadOp === VkAttachmentLoadOp::LOAD
                ? VkAccessFlagBits::COLOR_ATTACHMENT_READ_BIT->value
                    | VkAccessFlagBits::COLOR_ATTACHMENT_WRITE_BIT->value
                : VkAccessFlagBits::COLOR_ATTACHMENT_WRITE_BIT->value,
        ))->packInto($dependencies);

        $info = $blocks->keep((new VkRenderPassCreateInfo(
            attachmentCount: 1,
            pAttachments: $attachments,
            subpassCount: 1,
            pSubpasses: $subpasses,
            dependencyCount: 1,
            pDependencies: $dependencies,
        ))->pack());

        return $blocks->create(
            'vkCreateRenderPass',
            static fn (int $out) => VK10::vkCreateRenderPass($device, $info, 0, $out),
        );
    }
}
