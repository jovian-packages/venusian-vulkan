<?php

namespace Jovian\Engines\Vulkan;

use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Framebuffers\DmabufExportable;
use Surface\Contracts\Framebuffers\HdrReadback;
use Surface\Contracts\Framebuffers\Region;
use Surface\Framebuffers\GLFramebuffer;
use Surface\Framebuffers\HdrImage;
use VkDeviceMemory;
use VkImage;
use VkImageView;

/**
 * The vulkan engine's framebuffer: the single-sample RGBA8 image its device
 * resolves each frame into (or draws into with hard edges), kept in
 * VK_IMAGE_LAYOUT_GENERAL for its whole life. Rows run top-down in the image
 * as they do in every Surface framebuffer: reads and writes need no flip.
 * An HDR target holds linear half floats, SDR white at 1.0: read as RGBA8 it
 * is sRGB, clamped at SDR white; read as half floats it is as stored.
 */
final class VulkanFramebuffer extends GLFramebuffer implements DmabufExportable, HdrReadback
{
    public function __construct(
        private readonly VulkanDevice $device,
        private readonly VkImage $image,
        private readonly VkImageView $view,
        private readonly VkDeviceMemory $memory,
        private readonly int $w,
        private readonly int $h,
        private readonly bool $hdr = false,
    ) {
        parent::__construct();
    }

    /** Whether the image holds linear half floats. */
    public function hdr(): bool
    {
        return $this->hdr;
    }


    /** The image is this framebuffer's to destroy: a re-made target leaves the old one readable. */
    public function __destruct()
    {
        $this->device->forget($this);
    }

    /** The device whose queue holds the image. */
    public function device(): VulkanDevice
    {
        return $this->device;
    }

    public function image(): VkImage
    {
        return $this->image;
    }

    public function view(): VkImageView
    {
        return $this->view;
    }

    public function memory(): VkDeviceMemory
    {
        return $this->memory;
    }

    /** The device's dmabuf export of this frame, while this is its target and a DMABUF surface is adopted. */
    public function dmabuf(): ?array
    {
        return $this->device->dmabufOf($this);
    }

    /** The importer is done with the export it was handed with $fd. */
    public function releaseDmabuf(int $fd): void
    {
        $this->device->releaseDmabuf($fd);
    }

    public function width(): int
    {
        return $this->w;
    }

    public function height(): int
    {
        return $this->h;
    }

    public function readRgba8(Region $region): string
    {
        $this->inside($region);
        if (! $this->hdr) {
            return $this->device->read($this->image, $region);
        }

        return HdrImage::fromRgba16f($this->readRgba16f($region), $region->width, $region->height)->toRgba8();
    }

    public function uploadRgba8(string $rgba8, Region $region): void
    {
        $this->inside($region);
        $size = $region->width * $region->height * 4;
        if (strlen($rgba8) !== $size) {
            throw new DrawingException('vulkan: '.strlen($rgba8)." bytes do not fill a {$region->width} × {$region->height} region ({$size} bytes).");
        }
        $this->device->write($this->image, $this->hdr ? LinearHalves::fromRgba8($rgba8, $region->width, $region->height) : $rgba8, $region);
    }

    public function readRgba16f(Region $region): string
    {
        $this->inside($region);
        if (! $this->hdr) {
            return LinearHalves::fromRgba8($this->device->read($this->image, $region), $region->width, $region->height);
        }

        return $this->device->read($this->image, $region, 8);
    }

    /** A copy takes whatever region it is given: anything past the image is refused here. */
    private function inside(Region $region): void
    {
        if ($region->x < 0 || $region->y < 0 || $region->width < 1 || $region->height < 1
            || $region->x + $region->width > $this->w || $region->y + $region->height > $this->h) {
            throw new DrawingException("vulkan: the region {$region->width} × {$region->height} at ({$region->x}, {$region->y}) lies outside the {$this->w} × {$this->h} target.");
        }
    }
}
