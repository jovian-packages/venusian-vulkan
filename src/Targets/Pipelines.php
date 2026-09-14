<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Targets;

use Jovian\Bindings\Vulkan\Enums\VkBlendFactor;
use Jovian\Bindings\Vulkan\Enums\VkBlendOp;
use Jovian\Bindings\Vulkan\Enums\VkColorComponentFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkCullModeFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkFrontFace;
use Jovian\Bindings\Vulkan\Enums\VkLogicOp;
use Jovian\Bindings\Vulkan\Enums\VkPolygonMode;
use Jovian\Bindings\Vulkan\Enums\VkPrimitiveTopology;
use Jovian\Bindings\Vulkan\Enums\VkSampleCountFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkShaderStageFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkVertexInputRate;
use Jovian\Bindings\Vulkan\Structs\VkGraphicsPipelineCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkPipelineColorBlendAttachmentState;
use Jovian\Bindings\Vulkan\Structs\VkPipelineColorBlendStateCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkPipelineDynamicStateCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkPipelineInputAssemblyStateCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkPipelineMultisampleStateCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkPipelineRasterizationStateCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkPipelineShaderStageCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkPipelineVertexInputStateCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkPipelineViewportStateCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkVertexInputAttributeDescription;
use Jovian\Bindings\Vulkan\Structs\VkVertexInputBindingDescription;
use Jovian\Bindings\Vulkan\VK\VK10;
use Jovian\Venusian\Vulkan\Enums\Budget;
use Jovian\Venusian\Vulkan\Enums\DynamicState;
use Jovian\Venusian\Vulkan\Enums\PainterAttribute;
use Jovian\Venusian\Vulkan\Exceptions\VulkanDrawingException;
use Jovian\Venusian\Vulkan\Support\Blocks;
use Jovian\Venusian\Vulkan\VulkanContext;
use Surface\Contracts\Drawing\Topology;

/**
 * One graphics pipeline per Surface topology, compiled against the
 * executor's clear pass. Viewport and scissor are dynamic.
 */
final class Pipelines
{
    /**
     * @param  array<int, int>  $handles  Topology value → pipeline handle
     */
    public function __construct(
        public readonly int $layout,
        private array $handles,
        private readonly Blocks $blocks,
    ) {}

    public static function create(VulkanContext $ctx, int $renderPass): self
    {
        $blocks = new Blocks;
        $topologies = Topology::cases();
        $count = count($topologies);

        $entry = $blocks->cstring('main');
        $stages = $blocks->alloc(2 * VkPipelineShaderStageCreateInfo::size());
        (new VkPipelineShaderStageCreateInfo(
            stage: VkShaderStageFlagBits::VERTEX_BIT,
            module: $ctx->vertexModule,
            pName: $entry,
        ))->packInto($stages);
        (new VkPipelineShaderStageCreateInfo(
            stage: VkShaderStageFlagBits::FRAGMENT_BIT,
            module: $ctx->fragmentModule,
            pName: $entry,
        ))->packInto($stages + VkPipelineShaderStageCreateInfo::size());

        $bindings = $blocks->alloc(VkVertexInputBindingDescription::size());
        (new VkVertexInputBindingDescription(
            binding: 0,
            stride: PainterAttribute::stride(),
            inputRate: VkVertexInputRate::VERTEX,
        ))->packInto($bindings);

        $attributes = $blocks->alloc(count(PainterAttribute::cases()) * VkVertexInputAttributeDescription::size());
        foreach (PainterAttribute::cases() as $i => $attribute) {
            (new VkVertexInputAttributeDescription(
                location: $attribute->value,
                binding: 0,
                format: $attribute->format(),
                offset: $attribute->offset(),
            ))->packInto($attributes + $i * VkVertexInputAttributeDescription::size());
        }

        $vertexInput = $blocks->keep((new VkPipelineVertexInputStateCreateInfo(
            vertexBindingDescriptionCount: 1,
            pVertexBindingDescriptions: $bindings,
            vertexAttributeDescriptionCount: count(PainterAttribute::cases()),
            pVertexAttributeDescriptions: $attributes,
        ))->pack());

        $viewport = $blocks->keep((new VkPipelineViewportStateCreateInfo(
            viewportCount: 1,
            scissorCount: 1,
        ))->pack());

        $raster = $blocks->keep((new VkPipelineRasterizationStateCreateInfo(
            depthClampEnable: false,
            rasterizerDiscardEnable: false,
            polygonMode: VkPolygonMode::FILL,
            cullMode: VkCullModeFlagBits::NONE->value,
            frontFace: VkFrontFace::COUNTER_CLOCKWISE,
            depthBiasEnable: false,
            lineWidth: 1.0,
        ))->pack());

        $msaa = $blocks->keep((new VkPipelineMultisampleStateCreateInfo(
            rasterizationSamples: VkSampleCountFlagBits::COUNT_1_BIT,
            sampleShadingEnable: false,
        ))->pack());

        $blendAttachment = $blocks->alloc(VkPipelineColorBlendAttachmentState::size());
        (new VkPipelineColorBlendAttachmentState(
            blendEnable: true,
            srcColorBlendFactor: VkBlendFactor::SRC_ALPHA,
            dstColorBlendFactor: VkBlendFactor::ONE_MINUS_SRC_ALPHA,
            colorBlendOp: VkBlendOp::ADD,
            srcAlphaBlendFactor: VkBlendFactor::SRC_ALPHA,
            dstAlphaBlendFactor: VkBlendFactor::ONE_MINUS_SRC_ALPHA,
            alphaBlendOp: VkBlendOp::ADD,
            colorWriteMask: VkColorComponentFlagBits::R_BIT->value
                | VkColorComponentFlagBits::G_BIT->value
                | VkColorComponentFlagBits::B_BIT->value
                | VkColorComponentFlagBits::A_BIT->value,
        ))->packInto($blendAttachment);

        $blend = $blocks->keep((new VkPipelineColorBlendStateCreateInfo(
            logicOpEnable: false,
            logicOp: VkLogicOp::COPY,
            attachmentCount: 1,
            pAttachments: $blendAttachment,
            blendConstants: [0.0, 0.0, 0.0, 0.0],
        ))->pack());

        $dynamic = $blocks->keep((new VkPipelineDynamicStateCreateInfo(
            dynamicStateCount: 2,
            pDynamicStates: $blocks->uint32s([
                DynamicState::VIEWPORT->value,
                DynamicState::SCISSOR->value,
            ]),
        ))->pack());

        $infos = $blocks->alloc($count * VkGraphicsPipelineCreateInfo::size());
        foreach ($topologies as $i => $topology) {
            $assembly = $blocks->keep((new VkPipelineInputAssemblyStateCreateInfo(
                topology: self::vulkanTopology($topology),
                primitiveRestartEnable: false,
            ))->pack());
            (new VkGraphicsPipelineCreateInfo(
                stageCount: 2,
                pStages: $stages,
                pVertexInputState: $vertexInput,
                pInputAssemblyState: $assembly,
                pViewportState: $viewport,
                pRasterizationState: $raster,
                pMultisampleState: $msaa,
                pColorBlendState: $blend,
                pDynamicState: $dynamic,
                layout: $ctx->pipelineLayout,
                renderPass: $renderPass,
                subpass: 0,
                basePipelineHandle: 0,
                basePipelineIndex: -1,
            ))->packInto($infos + $i * VkGraphicsPipelineCreateInfo::size());
        }

        $out = $blocks->alloc($count * Budget::HANDLE_BYTES->value);
        VulkanDrawingException::check(
            VK10::vkCreateGraphicsPipelines($ctx->device, 0, $count, $infos, 0, $out),
            'vkCreateGraphicsPipelines',
        );

        $handles = [];
        foreach ($topologies as $i => $topology) {
            $handle = Blocks::handleAt($out, $i);
            if ($handle === 0) {
                throw VulkanDrawingException::allocation();
            }
            $handles[$topology->value] = $handle;
        }

        return new self($ctx->pipelineLayout, $handles, $blocks);
    }

    public function get(Topology $topology): int
    {
        return $this->handles[$topology->value] ?? 0;
    }

    public function destroy(int $device): void
    {
        foreach ($this->handles as $pipeline) {
            if ($pipeline !== 0) {
                VK10::vkDestroyPipeline($device, $pipeline, 0);
            }
        }
        $this->handles = [];
        $this->blocks->release();
    }

    private static function vulkanTopology(Topology $topology): VkPrimitiveTopology
    {
        return match ($topology) {
            Topology::POINTS => VkPrimitiveTopology::POINT_LIST,
            Topology::LINES => VkPrimitiveTopology::LINE_LIST,
            Topology::LINE_STRIP => VkPrimitiveTopology::LINE_STRIP,
            Topology::TRIANGLES => VkPrimitiveTopology::TRIANGLE_LIST,
            Topology::TRIANGLE_STRIP => VkPrimitiveTopology::TRIANGLE_STRIP,
        };
    }
}
