<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Values;

use Jovian\Bindings\Vulkan\Structs\VkPhysicalDeviceMemoryProperties;

/**
 * Subset selector over unpacked VkPhysicalDeviceMemoryProperties.
 *
 * The first type whose bit is set in memoryTypeBits and that carries every
 * requested property flag wins. Equality is the wrong test: V3D's one type
 * is a superset of HOST_VISIBLE|HOST_COHERENT.
 */
final readonly class MemoryTypes
{
    /**
     * @param  list<int>  $propertyFlags  propertyFlags per type index
     */
    public function __construct(
        public array $propertyFlags,
    ) {}

    public static function fromProperties(VkPhysicalDeviceMemoryProperties $properties): self
    {
        $flags = [];
        for ($i = 0; $i < $properties->memoryTypeCount; $i++) {
            $flags[$i] = $properties->memoryTypes[$i]->propertyFlags;
        }

        return new self($flags);
    }

    public function select(int $typeBits, int $required): int
    {
        foreach ($this->propertyFlags as $index => $flags) {
            if (($typeBits & (1 << $index)) === 0) {
                continue;
            }
            if (($flags & $required) === $required) {
                return $index;
            }
        }

        return -1;
    }
}
