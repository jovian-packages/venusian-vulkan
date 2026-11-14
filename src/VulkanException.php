<?php

namespace Jovian\Engines\Vulkan;

use Surface\Contracts\Graphics\GraphicsException;
use Throwable;

/** What Vulkan refused or failed at, in the vulkan engine's words. */
class VulkanException extends GraphicsException
{
    public static function because(string $what, ?Throwable $previous = null): self
    {
        return new self("vulkan: {$what}", 0, $previous);
    }
}
