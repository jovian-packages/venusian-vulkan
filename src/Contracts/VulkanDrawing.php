<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Contracts;

use Jovian\Bindings\Vulkan\Values\ApiVersion;

/**
 * The Vulkan-only half a sketch reaches beside the Painter, through
 * `$gpu->executor()` and `instanceof VulkanDrawing`.
 */
interface VulkanDrawing
{
    public function instance(): int;

    public function physicalDevice(): int;

    public function device(): int;

    public function queue(): int;

    public function queueFamily(): int;

    public function surface(): int;

    public function swapchain(): int;

    public function swapchainFormat(): int;

    /** Non-null only inside a frame. */
    public function commandBuffer(): ?int;

    public function renderPass(): int;

    public function pipelineLayout(): int;

    public function loaderVersion(): ApiVersion;

    public function retirementPending(): int;
}
