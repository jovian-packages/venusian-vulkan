<?php

namespace Jovian\Engines\Vulkan;

use Surface\Contracts\Rendering\Framebuffers\DamageGranularity;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Painting\PaintTarget;
use Surface\Graphics\GEFramebuffer;
use Surface\NutsAndBolts\Color;
use VkBuffer;
use VkClearColorValue;
use VkClearDepthStencilValue;
use VkClearValue;
use VkCommandBuffer;
use VkDescriptorPool;
use VkDescriptorSet;
use VkDeviceMemory;
use VkFramebuffer;
use VkFramebufferCreateInfo;
use VkImage;
use VkImageBlit;
use VkImageMemoryBarrier;
use VkImageView;
use VkOffset3D;
use VkPipeline;
use VkRect2D;
use VkRenderPassBeginInfo;
use VkViewport;

/**
 * The vulkan engine's framebuffer: an RGBA8 image on the GPU holding premultiplied colour, and a
 * four-sample twin that antialiased shapes are drawn into and resolved from. read() and write()
 * take straight colour, as every GEFramebuffer does: the GPU converts on the way out and in.
 *
 * A write to the image leaves the twin behind; it is filled from the image again before the next
 * antialiased draw. Drawing is handed to the GPU and not waited for: the queue runs it in order,
 * so whatever is drawn or shown next sees it. Only read() waits, for the bytes it gives back.
 *
 * Its stencils only live inside a pass: they are transient attachments, on lazily allocated
 * memory where the GPU has that. A transient framebuffer (a layer's sheet) holds its samples the same
 * way: it is only ever drawn from transparent black, so only its single-sample image is kept.
 */
class VulkanFramebuffer extends GEFramebuffer implements PaintTarget
{
    /** Samples a pixel when edges are antialiased. */
    public const int SAMPLES = 4;

    /** @var array{VkImage, VkDeviceMemory, VkImageView} the single-sample image: premultiplied colour, in the GENERAL layout */
    protected array $image;

    /** @var array{VkImage, VkDeviceMemory, VkImageView} the four-sample twin */
    protected array $samples;

    /** @var array{VkImage, VkDeviceMemory, VkImageView} */
    protected array $stencil;

    /** @var array{VkImage, VkDeviceMemory, VkImageView} */
    protected array $hardStencil;

    /** @var array{VkImage, VkDeviceMemory, VkImageView}|null straight colour on its way in or out: made the first time it is needed */
    protected ?array $staging = null;

    /** @var array<string, VkFramebuffer> by the shape of the pass, and 'staging' */
    protected array $framebuffers = [];

    protected VkDescriptorPool $pool;

    /** Reads the image; reads the staging image. */
    protected VkDescriptorSet $imageSet;

    protected VkDescriptorSet $stagingSet;

    /** Whether the four-sample twin is behind the image: written to since it was last resolved. */
    protected bool $stale = false;

    /** @throws VulkanException */
    public function __construct(
        protected readonly VKLogicalDevice $device,
        protected readonly int $width,
        protected readonly int $height,
        protected readonly bool $transient = false,
    ) {
        parent::__construct($device);

        $color = VKLogicalDevice::COLOR;
        $this->image = $this->make($color, 1, VK_IMAGE_USAGE_COLOR_ATTACHMENT_BIT | VK_IMAGE_USAGE_SAMPLED_BIT | VK_IMAGE_USAGE_TRANSFER_SRC_BIT | VK_IMAGE_USAGE_TRANSFER_DST_BIT | VK_IMAGE_USAGE_HOST_TRANSFER_BIT, VK_IMAGE_ASPECT_COLOR_BIT);
        // a layer's sheet is drawn from transparent black each frame: its samples only live inside a pass
        $this->samples = $this->make($color, self::SAMPLES, VK_IMAGE_USAGE_COLOR_ATTACHMENT_BIT, VK_IMAGE_ASPECT_COLOR_BIT, transient: $transient);
        $this->stencil = $this->make($device->stencilFormat(), self::SAMPLES, VK_IMAGE_USAGE_DEPTH_STENCIL_ATTACHMENT_BIT, VK_IMAGE_ASPECT_STENCIL_BIT, transient: true);
        $this->hardStencil = $this->make($device->stencilFormat(), 1, VK_IMAGE_USAGE_DEPTH_STENCIL_ATTACHMENT_BIT, VK_IMAGE_ASPECT_STENCIL_BIT, transient: true);
        $device->hostGeneral($this->image[0]);

        [$this->pool, [$this->imageSet, $this->stagingSet]] = $device->samplerSets(2);
        $device->point($this->imageSet, $this->image[2]);

        // defined from the start: transparent black, as every new framebuffer holds
        $this->clear(Color::rgba(0, 0, 0, 0.0));
    }

    /** Set once free() has destroyed what it holds. */
    protected bool $freed = false;

    public function __destruct()
    {
        // a device already closed took everything with it
        if ($this->freed || ! $this->device->isOpen()) {
            return;
        }
        // nothing the GPU is still drawing into may be destroyed under it
        $this->device->sync();
        $this->free();
    }

    /**
     * Destroys what it holds now, without waiting: only once nothing submitted still uses it, as when
     * a submission that comes after every one that used it has finished.
     *
     * @internal
     */
    public function free(): void
    {
        if ($this->freed || ! $this->device->isOpen()) {
            return;
        }
        $this->freed = true;
        $vk = $this->device->device();
        foreach ($this->framebuffers as $framebuffer) {
            vkDestroyFramebuffer($vk, $framebuffer, 0);
        }
        vkDestroyDescriptorPool($vk, $this->pool, 0);
        foreach (array_filter([$this->image, $this->samples, $this->stencil, $this->hardStencil, $this->staging]) as [$image, $memory, $view]) {
            vkDestroyImageView($vk, $view, 0);
            vkDestroyImage($vk, $image, 0);
            vkFreeMemory($vk, $memory, 0);
        }
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function format(): FormatSpec
    {
        return FormatSpec::rgba8();
    }

    // PaintTarget: what a painter needs to know of what it draws on

    public function viewportWidth(): int
    {
        return $this->width;
    }

    public function viewportHeight(): int
    {
        return $this->height;
    }

    public function damageGranularity(): DamageGranularity
    {
        return DamageGranularity::pixel($this->width, $this->height);
    }

    /** The image keeps what was drawn until it is drawn over. */
    public function preservesContentsOnPresent(): bool
    {
        return true;
    }

    /**
     * Moves the pixels of $region by ($dx, $dy) on the GPU: what moves past the region's edge is
     * dropped, and the strip left behind keeps its old pixels. Blitted out to an image of its own
     * and back, since a blit within one image may not overlap itself.
     *
     * @throws VulkanException
     */
    public function scroll(Region $region, int $dx, int $dy): void
    {
        $area = Region::wholeSurface($this->width, $this->height)->intersect($region);
        $from = $area?->intersect(new Region($area->x - $dx, $area->y - $dy, $area->width, $area->height));
        if (is_null($from) || $from->isEmpty() || ($dx === 0 && $dy === 0)) {
            return;
        }
        [$copy, $memory] = $this->device->image(VKLogicalDevice::COLOR, $from->width, $from->height, 1, VK_IMAGE_USAGE_TRANSFER_SRC_BIT | VK_IMAGE_USAGE_TRANSFER_DST_BIT);
        $commands = $this->device->commands();

        $barrier = function (VkImage $image, int $old, int $srcAccess, int $dstAccess, int $srcStage, int $dstStage) use ($commands): void {
            $b = new VkImageMemoryBarrier;
            $b->srcAccessMask = $srcAccess;
            $b->dstAccessMask = $dstAccess;
            $b->oldLayout = $old;
            $b->newLayout = VK_IMAGE_LAYOUT_GENERAL;
            $b->srcQueueFamilyIndex = VK_QUEUE_FAMILY_IGNORED;
            $b->dstQueueFamilyIndex = VK_QUEUE_FAMILY_IGNORED;
            $b->image = $image;
            $b->subresourceRange->aspectMask = VK_IMAGE_ASPECT_COLOR_BIT;
            $b->subresourceRange->levelCount = 1;
            $b->subresourceRange->layerCount = 1;
            vkCmdPipelineBarrier($commands, $srcStage, $dstStage, 0, 0, [], 0, [], 1, [$b]);
        };
        $blit = function (VkImage $src, int $sx, int $sy, VkImage $dst, int $tx, int $ty) use ($commands, $from): void {
            $region = new VkImageBlit;
            foreach ([$region->srcSubresource, $region->dstSubresource] as $layers) {
                $layers->aspectMask = VK_IMAGE_ASPECT_COLOR_BIT;
                $layers->layerCount = 1;
            }
            $offset = function (int $x, int $y, int $z): VkOffset3D {
                $o = new VkOffset3D;
                [$o->x, $o->y, $o->z] = [$x, $y, $z];

                return $o;
            };
            $region->srcOffsets = [$offset($sx, $sy, 0), $offset($sx + $from->width, $sy + $from->height, 1)];
            $region->dstOffsets = [$offset($tx, $ty, 0), $offset($tx + $from->width, $ty + $from->height, 1)];
            vkCmdBlitImage($commands, $src, VK_IMAGE_LAYOUT_GENERAL, $dst, VK_IMAGE_LAYOUT_GENERAL, 1, [$region], VK_FILTER_NEAREST);
        };

        // what drew the image so far, then the copy made ready to be written
        $all = VK_PIPELINE_STAGE_ALL_COMMANDS_BIT;
        $barrier($this->image[0], VK_IMAGE_LAYOUT_GENERAL, VK_ACCESS_MEMORY_WRITE_BIT, VK_ACCESS_TRANSFER_READ_BIT, $all, VK_PIPELINE_STAGE_TRANSFER_BIT);
        $barrier($copy, VK_IMAGE_LAYOUT_UNDEFINED, 0, VK_ACCESS_TRANSFER_WRITE_BIT, VK_PIPELINE_STAGE_TOP_OF_PIPE_BIT, VK_PIPELINE_STAGE_TRANSFER_BIT);
        $blit($this->image[0], $from->x, $from->y, $copy, 0, 0);
        $barrier($copy, VK_IMAGE_LAYOUT_GENERAL, VK_ACCESS_TRANSFER_WRITE_BIT, VK_ACCESS_TRANSFER_READ_BIT, VK_PIPELINE_STAGE_TRANSFER_BIT, VK_PIPELINE_STAGE_TRANSFER_BIT);
        $barrier($this->image[0], VK_IMAGE_LAYOUT_GENERAL, VK_ACCESS_TRANSFER_READ_BIT, VK_ACCESS_TRANSFER_WRITE_BIT, VK_PIPELINE_STAGE_TRANSFER_BIT, VK_PIPELINE_STAGE_TRANSFER_BIT);
        $blit($copy, 0, 0, $this->image[0], $from->x + $dx, $from->y + $dy);
        $barrier($this->image[0], VK_IMAGE_LAYOUT_GENERAL, VK_ACCESS_TRANSFER_WRITE_BIT, VK_ACCESS_MEMORY_READ_BIT | VK_ACCESS_MEMORY_WRITE_BIT, VK_PIPELINE_STAGE_TRANSFER_BIT, $all);

        $vk = $this->device->device();
        $this->device->after($commands, function () use ($vk, $copy, $memory): void {
            vkDestroyImage($vk, $copy, 0);
            vkFreeMemory($vk, $memory, 0);
        });
        $this->device->submit($commands);
        $this->stale = true;
    }

    /** @throws VulkanException */
    public function clear(Color $color): static
    {
        // the colour as its bytes, then premultiplied, as the image holds it
        $alpha = round($color->alpha * 255) / 255;
        $byte = fn (float $channel): float => round($channel * 255) / 255 * $alpha;

        $commands = $this->device->commands();
        $this->beginPass($commands, 'aa', VK_ATTACHMENT_LOAD_OP_CLEAR, [$byte($color->red), $byte($color->green), $byte($color->blue), $alpha]);
        vkCmdEndRenderPass($commands);
        $this->device->submit($commands);
        $this->stale = false;

        return $this;
    }

    /**
     * Opens a pass for drawing shapes: $vertices (points in surface pixels) uploaded and bound,
     * the viewport and scissor over the whole surface, and the frame's size and where on the
     * surface it starts ($ox, $oy) pushed to the vertex stage. With $clear it starts from
     * transparent black; otherwise from what it holds, the twin filled from the image first when
     * it is behind. Bind a pipeline, push what its fragment reads, draw; then end().
     *
     * With $area the pass covers only that part of the surface: only it is loaded, drawn and
     * resolved, so a small shape on a large surface costs what the shape covers. Nothing may be
     * drawn outside it: the scissor starts as $area.
     *
     * @internal
     *
     * @throws VulkanException
     */
    public function begin(VkCommandBuffer $commands, bool $antialiased, string $vertices, int $ox = 0, int $oy = 0, bool $clear = false, ?Region $area = null): void
    {
        // uploads are commands of their own, and no command of that kind goes inside a pass
        $buffer = $this->device->vertices($commands, $vertices);

        if ($clear) {
            $this->beginPass($commands, $antialiased ? 'aa' : 'hard', VK_ATTACHMENT_LOAD_OP_CLEAR, [0.0, 0.0, 0.0, 0.0], $area);
            $this->stale = $antialiased ? false : $this->stale;
        } else {
            if ($antialiased && $this->transient) {
                throw VulkanException::because('a transient framebuffer keeps no samples: it is only drawn from transparent black.');
            }
            if ($antialiased && $this->stale) {
                $this->restore($commands);
            }
            $this->beginPass($commands, $antialiased ? 'aa' : 'hard', VK_ATTACHMENT_LOAD_OP_LOAD, null, $area);
        }

        vkCmdBindVertexBuffers($commands, 0, 1, [$buffer], [0]);
        vkCmdPushConstants($commands, $this->device->layout(), VK_SHADER_STAGE_VERTEX_BIT, 0, VKLogicalDevice::PUSH_FRAME, pack('g4', (float) $this->width, (float) $this->height, (float) $ox, (float) $oy));
    }

    /**
     * Ends the pass begin() opened. A draw straight into the image leaves the twin behind.
     *
     * @internal
     */
    public function end(VkCommandBuffer $commands, bool $antialiased): void
    {
        vkCmdEndRenderPass($commands);
        if (! $antialiased) {
            $this->stale = true;
        }
    }

    /**
     * The pipeline of 'place' vertices and $fragment for a pass begin() opens.
     *
     * @internal
     *
     * @throws VulkanException
     */
    public function pipeline(string $fragment, bool $antialiased, bool $blend = true, ?string $stencil = null): VkPipeline
    {
        return $this->device->pipeline('place', $fragment, $antialiased ? 'aa' : 'hard', $blend, $stencil);
    }

    /**
     * What the fragment stage reads, as bytes: a multiple of 4, at most VKLogicalDevice::PUSH_PAINT.
     *
     * @internal
     */
    public function paint(VkCommandBuffer $commands, string $bytes): void
    {
        vkCmdPushConstants($commands, $this->device->layout(), VK_SHADER_STAGE_FRAGMENT_BIT, VKLogicalDevice::PUSH_FRAME, strlen($bytes), $bytes);
    }

    /**
     * The set that reads the image, for a pass that draws this framebuffer onto another.
     *
     * @internal
     */
    public function sampled(): VkDescriptorSet
    {
        return $this->imageSet;
    }

    /** @internal the single-sample image: premultiplied colour, in the GENERAL layout */
    public function image(): VkImage
    {
        return $this->image[0];
    }

    /** What a stage's view was given to show frames in, when it lent one. */
    protected ?VulkanSurface $surface = null;

    /**
     * Shows it in $surface from now on.
     *
     * @internal
     */
    public function showIn(VulkanSurface $surface): static
    {
        $this->surface = $surface;

        return $this;
    }

    /**
     * In a surface over the view the stage lent, the GPU copies the image to the swapchain's next image and
     * shows it: nothing comes back to the CPU, and the copy is not waited for. Otherwise as pixels, through
     * the stage. On a paced stage this runs once a refresh.
     *
     * @throws VulkanException
     */
    protected function show(): void
    {
        if (is_null($this->surface)) {
            parent::show();

            return;
        }
        $this->surface->show($this, $this->stage()->pixelScale());
    }

    /** @internal */
    public static function scissor(VkCommandBuffer $commands, Region $region): void
    {
        $rect = new VkRect2D;
        [$rect->offset->x, $rect->offset->y] = [$region->x, $region->y];
        [$rect->extent->width, $rect->extent->height] = [$region->width, $region->height];
        vkCmdSetScissor($commands, 0, 1, [$rect]);
    }

    /** @throws VulkanException */
    protected function readRgba8(Region $region): string
    {
        // the image's premultiplied colour, straight, into the staging image; then its bytes out
        $commands = $this->device->commands();
        $this->convert($commands, 'unpremultiply', $this->imageSet, 'staging', $region);
        $this->device->submit($commands);
        $this->device->sync();

        return $this->device->hostRead($this->staging()[0], $region);
    }

    /** @throws VulkanException */
    protected function writeRgba8(string $rgba8, Region $region): void
    {
        // the staging image is the CPU's to write once nothing submitted is still reading it
        $staging = $this->staging();
        $this->device->sync();
        $this->device->hostWrite($staging[0], $rgba8, $region);

        $commands = $this->device->commands();
        $this->convert($commands, 'premultiply', $this->stagingSet, 'plain', $region);
        $this->device->submit($commands);
        $this->stale = true;
    }

    /**
     * One raycast pass over $region of the image with $fragment, replacing what is there: $storage is set 0 and
     * $sampled set 1 of the raycast pipeline layout, $push the fragment's push constants. Leaves the twin behind.
     *
     * @internal
     *
     * @throws VulkanException
     */
    public function cover(VkCommandBuffer $commands, Region $region, string $fragment, VkDescriptorSet $storage, VkDescriptorSet $sampled, string $push): void
    {
        $this->beginPass($commands, 'plain', VK_ATTACHMENT_LOAD_OP_LOAD, null, $region);
        $layout = $this->device->raycastLayout();
        vkCmdBindPipeline($commands, VK_PIPELINE_BIND_POINT_GRAPHICS, $this->device->pipeline('cover', $fragment, 'plain', blend: false, raycast: true));
        vkCmdBindDescriptorSets($commands, VK_PIPELINE_BIND_POINT_GRAPHICS, $layout, 0, 2, [$storage, $sampled], 0, []);
        vkCmdPushConstants($commands, $layout, VK_SHADER_STAGE_FRAGMENT_BIT, VKLogicalDevice::PUSH_FRAME, strlen($push), $push);
        vkCmdDraw($commands, 3, 1, 0, 0);
        vkCmdEndRenderPass($commands);
        $this->stale = true;
    }

    /** One pass that draws the image into every sample of the twin. */
    protected function restore(VkCommandBuffer $commands): void
    {
        $this->beginPass($commands, 'samples', VK_ATTACHMENT_LOAD_OP_DONT_CARE);
        vkCmdBindPipeline($commands, VK_PIPELINE_BIND_POINT_GRAPHICS, $this->device->pipeline('cover', 'copy', 'samples', blend: false));
        vkCmdBindDescriptorSets($commands, VK_PIPELINE_BIND_POINT_GRAPHICS, $this->device->layout(), 0, 1, [$this->imageSet], 0, []);
        vkCmdDraw($commands, 3, 1, 0, 0);
        vkCmdEndRenderPass($commands);
        $this->stale = false;
    }

    /** One pass that draws what $from reads into $target ('staging', or 'plain': the image) over $region with $fragment, replacing what is there. */
    protected function convert(VkCommandBuffer $commands, string $fragment, VkDescriptorSet $from, string $target, Region $region): void
    {
        $this->beginPass($commands, $target, VK_ATTACHMENT_LOAD_OP_LOAD);
        self::scissor($commands, $region);
        vkCmdBindPipeline($commands, VK_PIPELINE_BIND_POINT_GRAPHICS, $this->device->pipeline('cover', $fragment, 'plain', blend: false));
        vkCmdBindDescriptorSets($commands, VK_PIPELINE_BIND_POINT_GRAPHICS, $this->device->layout(), 0, 1, [$from], 0, []);
        vkCmdDraw($commands, 3, 1, 0, 0);
        vkCmdEndRenderPass($commands);
    }

    /**
     * Begins the pass of $shape ('aa', 'samples', 'hard', 'plain', or 'staging': plain, over the
     * staging image), colour loaded as $load says, with the viewport and scissor over the whole
     * surface.
     *
     * @param array{float, float, float, float}|null $clear the colour a CLEAR pass starts from, premultiplied
     * @param Region|null $area the part of the surface the pass covers; null: all of it
     *
     * @throws VulkanException
     */
    protected function beginPass(VkCommandBuffer $commands, string $shape, int $load, ?array $clear = null, ?Region $area = null): void
    {
        $area ??= new Region(0, 0, $this->width, $this->height);
        $pass = $shape === 'staging' ? 'plain' : $shape;
        if ($pass === 'aa' && $this->transient) {
            $pass = 'sheet';
        }

        $color = new VkClearValue;
        $color->color = new VkClearColorValue;
        $color->color->float32 = $clear ?? [0.0, 0.0, 0.0, 0.0];
        $depthStencil = new VkClearValue;
        $depthStencil->depthStencil = new VkClearDepthStencilValue;
        $depthStencil->depthStencil->depth = 1.0;
        $depthStencil->depthStencil->stencil = 0;

        $begin = new VkRenderPassBeginInfo;
        $begin->renderPass = $this->device->pass($pass, $load);
        $begin->framebuffer = $this->framebufferFor($shape);
        [$begin->renderArea->offset->x, $begin->renderArea->offset->y] = [$area->x, $area->y];
        [$begin->renderArea->extent->width, $begin->renderArea->extent->height] = [$area->width, $area->height];
        $begin->pClearValues = match ($pass) {
            'aa' => [$color, $depthStencil, $color],
            'hard' => [$color, $depthStencil],
            default => [$color],
        };
        $begin->clearValueCount = count($begin->pClearValues);
        vkCmdBeginRenderPass($commands, $begin, VK_SUBPASS_CONTENTS_INLINE);

        $viewport = new VkViewport;
        [$viewport->x, $viewport->y, $viewport->width, $viewport->height, $viewport->minDepth, $viewport->maxDepth] = [0.0, 0.0, (float) $this->width, (float) $this->height, 0.0, 1.0];
        vkCmdSetViewport($commands, 0, 1, [$viewport]);
        self::scissor($commands, $area);
    }

    /** @throws VulkanException */
    protected function framebufferFor(string $shape): VkFramebuffer
    {
        if (isset($this->framebuffers[$shape])) {
            return $this->framebuffers[$shape];
        }
        $views = match ($shape) {
            'aa' => [$this->samples[2], $this->stencil[2], $this->image[2]],
            'samples' => [$this->samples[2]],
            'hard' => [$this->image[2], $this->hardStencil[2]],
            'plain' => [$this->image[2]],
            'staging' => [$this->staging()[2]],
        };
        $info = new VkFramebufferCreateInfo;
        $info->renderPass = $this->device->pass($shape === 'staging' ? 'plain' : $shape, VK_ATTACHMENT_LOAD_OP_LOAD);
        $info->attachmentCount = count($views);
        $info->pAttachments = $views;
        $info->width = $this->width;
        $info->height = $this->height;
        $info->layers = 1;
        VKLogicalDevice::ok(vkCreateFramebuffer($this->device->device(), $info, 0, $framebuffer), 'vkCreateFramebuffer');

        return $this->framebuffers[$shape] = $framebuffer;
    }

    /**
     * @return array{VkImage, VkDeviceMemory, VkImageView}
     *
     * @throws VulkanException
     */
    protected function staging(): array
    {
        if (is_null($this->staging)) {
            $this->staging = $this->make(VKLogicalDevice::COLOR, 1, VK_IMAGE_USAGE_COLOR_ATTACHMENT_BIT | VK_IMAGE_USAGE_SAMPLED_BIT | VK_IMAGE_USAGE_HOST_TRANSFER_BIT, VK_IMAGE_ASPECT_COLOR_BIT);
            $this->device->hostGeneral($this->staging[0]);
            $this->device->point($this->stagingSet, $this->staging[2]);
        }

        return $this->staging;
    }

    /**
     * @return array{VkImage, VkDeviceMemory, VkImageView}
     *
     * @throws VulkanException
     */
    protected function make(int $format, int $samples, int $usage, int $aspect, bool $transient = false): array
    {
        [$image, $memory] = $this->device->image($format, $this->width, $this->height, $samples, $usage, $transient);

        return [$image, $memory, $this->device->view($image, $format, $aspect)];
    }
}
