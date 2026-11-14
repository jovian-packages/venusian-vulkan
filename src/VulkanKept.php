<?php

namespace Jovian\Engines\Vulkan;

use VkBuffer;
use VkDescriptorPool;
use VkDeviceMemory;

/**
 * A storage buffer the device made with kept(), or a descriptor pool a kept set came from, destroyed
 * with its holder: once nothing submitted uses it.
 *
 * @internal
 */
final class VulkanKept
{
    public readonly ?VkBuffer $buffer;

    protected readonly ?VkDeviceMemory $memory;

    /** @param array{VkBuffer, VkDeviceMemory}|null $kept */
    public function __construct(
        protected readonly VKLogicalDevice $device,
        ?array $kept,
        protected readonly ?VkDescriptorPool $pool = null,
    ) {
        [$this->buffer, $this->memory] = $kept ?? [null, null];
    }

    public function __destruct()
    {
        if (! $this->device->isOpen()) {
            return;
        }
        // the GPU may still be reading it
        $this->device->sync();
        if (! is_null($this->pool)) {
            vkDestroyDescriptorPool($this->device->device(), $this->pool, 0);
        }
        if (! is_null($this->buffer)) {
            $this->device->forget([$this->buffer, $this->memory]);
        }
    }
}
