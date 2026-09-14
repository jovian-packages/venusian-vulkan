<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Enums;

use Jovian\Bindings\Vulkan\Enums\VkFormat;

/**
 * The Surface vertex `x y z r g b a u v` (nine floats, stride 36) as
 * Vulkan vertex-input locations and formats.
 */
enum PainterAttribute: int
{
    case POSITION = 0;
    case COLOR = 1;
    case UV = 2;

    public function format(): VkFormat
    {
        return match ($this) {
            self::POSITION => VkFormat::R32G32B32_SFLOAT,
            self::COLOR => VkFormat::R32G32B32A32_SFLOAT,
            self::UV => VkFormat::R32G32_SFLOAT,
        };
    }

    /** Byte offset inside one vertex. */
    public function offset(): int
    {
        return match ($this) {
            self::POSITION => 0,
            self::COLOR => 12,
            self::UV => 28,
        };
    }

    public static function stride(): int
    {
        return 36;
    }
}
