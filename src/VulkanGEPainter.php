<?php

namespace Jovian\Engines\Vulkan;

use Surface\Contracts\Rendering\Painting\PaintTarget;
use Surface\Graphics\GEFramebuffer;
use Surface\Graphics\GEPainter;

/** The vulkan engine's painter, as Graphics hands it out. */
class VulkanGEPainter extends GEPainter
{
    protected function source(GEFramebuffer $framebuffer): PaintTarget
    {
        /** @var VulkanFramebuffer $framebuffer the base checked it is this engine's */
        return $framebuffer;
    }
}
