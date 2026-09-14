<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Shaders;

use Jovian\Venusian\Vulkan\Exceptions\VulkanDrawingException;

class Spirv
{
    public static function magic(): int
    {
        return 0x07230203;
    }

    public static function path(string $name): string
    {
        return __DIR__.DIRECTORY_SEPARATOR.$name;
    }

    public static function load(string $name): string
    {
        $path = self::path($name);
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw VulkanDrawingException::spirv("could not read {$name}");
        }
        if (strlen($bytes) % 4 !== 0) {
            throw VulkanDrawingException::spirv("{$name} length is not a multiple of 4");
        }
        $word = unpack('V', substr($bytes, 0, 4))[1];
        if ($word !== self::magic()) {
            throw VulkanDrawingException::spirv("{$name} does not start with the SPIR-V magic word");
        }

        return $bytes;
    }
}
