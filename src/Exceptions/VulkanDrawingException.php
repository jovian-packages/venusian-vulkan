<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Exceptions;

use Jovian\Bindings\Vulkan\Enums\VkResult;
use Surface\Contracts\Drawing\DrawingException;

class VulkanDrawingException extends DrawingException
{
    public static function loader(): self
    {
        return new self('Bridge::load() could not open a Vulkan library');
    }

    public static function result(string $call, VkResult|int $code): self
    {
        $value = self::code($code);
        $name = VkResult::tryFrom($value)?->name ?? (string) $value;

        return new self("{$call} returned {$name}");
    }

    public static function noDevice(): self
    {
        return new self('no Vulkan physical device with a graphics queue');
    }

    public static function noHostForPlatform(): self
    {
        return new self('no Vulkan surface host for this platform');
    }

    public static function missingExtension(string $extension): self
    {
        return new self("required Vulkan extension {$extension} is not present");
    }

    public static function instanceExtensionMismatch(): self
    {
        return new self('Vulkan instance was created with a different WSI extension');
    }

    public static function texturesExhausted(): self
    {
        return new self('Vulkan descriptor pool is exhausted');
    }

    public static function textureSize(int $width, int $height): self
    {
        return new self("texture {$width}x{$height} exceeds device limits");
    }

    public static function noMemoryType(): self
    {
        return new self('no Vulkan memory type matches the requested flags');
    }

    public static function allocation(): self
    {
        return new self('Vulkan host allocation returned 0');
    }

    public static function spirv(string $reason): self
    {
        return new self("SPIR-V is not valid: {$reason}");
    }

    public static function pushConstantsTooSmall(int $bytes): self
    {
        return new self("device maxPushConstantsSize {$bytes} is below 64");
    }

    public static function released(): self
    {
        return new self('executor has been released');
    }

    public static function outOfFrame(string $operation): self
    {
        return new self("{$operation} is only legal between beginFrame() and endFrame().");
    }

    public static function code(VkResult|int $code): int
    {
        return $code instanceof VkResult ? $code->value : $code;
    }

    public static function check(VkResult|int $code, string $call): void
    {
        if (self::code($code) !== VkResult::SUCCESS->value) {
            throw self::result($call, $code);
        }
    }
}
