<?php

namespace Jovian\Engines\Vulkan;

use CALayer;
use CAMetalLayer;
use NSView;
use VkExtent2D;
use VkImage;
use VkImageBlit;
use VkImageMemoryBarrier;
use VkMetalSurfaceCreateInfoEXT;
use VkOffset3D;
use VkPresentInfoKHR;
use VkSemaphore;
use VkSemaphoreCreateInfo;
use VkSurfaceKHR;
use VkSwapchainCreateInfoKHR;
use VkSwapchainKHR;

/**
 * What the vulkan engine adds to a view a stage lends it on macOS: a child view of its own, the
 * size of the stage's, hosting a CAMetalLayer, and a Vulkan surface and swapchain over that layer.
 * MoltenVK presents in a CAMetalLayer and nowhere else. The stage's own view is left as it was;
 * release() takes the child view away again.
 *
 * The view is ext-appkit's and the layer ext-metal's. Neither is a dependency of this package:
 * a surface is only made where both are loaded, and nothing else of ext-metal is used. MoltenVK
 * sets the layer's device and drawable size itself.
 *
 * Showing a frame is one blit on the GPU into the swapchain's next image, in the same queue the
 * frame was drawn on, and is not waited for.
 */
class VulkanSurface
{
    protected const int FORMAT = VK_FORMAT_B8G8R8A8_UNORM;

    protected readonly NSView $view;

    protected readonly CAMetalLayer $layer;

    protected ?VkSurfaceKHR $surface = null;

    protected ?VkSwapchainKHR $swapchain = null;

    /** @var list<VkImage> the swapchain's images */
    protected array $images = [];

    /** @var array{int, int} the swapchain's size, in pixels */
    protected array $size = [0, 0];

    /** VK_PRESENT_MODE_*: a frame is shown as soon as it is drawn where the surface can, as the stage paces showing. */
    protected int $mode = VK_PRESENT_MODE_FIFO_KHR;

    /** @var list<array{VkSemaphore, VkSemaphore}> pairs free to use: signalled when an image is acquired, and when a frame is in it */
    protected array $semaphores = [];

    /** @var list<VkSemaphore> every semaphore made */
    protected array $made = [];

    protected bool $released = false;

    protected function __construct(
        protected readonly VKLogicalDevice $device,
        protected readonly NSView $stage,
    ) {
        $this->layer = CAMetalLayer::layer();
        // the layer is set before the view wants one, so the view hosts it rather than making its own
        $this->view = NSView::initWithFrame($stage->bounds());
        $this->view->setLayer(CALayer::fromPointer($this->layer->pointer()));
        $this->view->setWantsLayer(true);
        $stage->addSubview($this->view);
    }

    /**
     * A surface over $stage, or null where the GPU cannot present to it: the stage is then shown on as pixels.
     *
     * @internal
     *
     * @throws VulkanException
     */
    public static function over(VKLogicalDevice $device, NSView $stage): ?self
    {
        $made = new self($device, $stage);
        $info = new VkMetalSurfaceCreateInfoEXT;
        $info->pLayer = $made->layer->pointer();
        if (vkCreateMetalSurfaceEXT($device->instance(), $info, 0, $surface) !== VK_SUCCESS) {
            $made->release();

            return null;
        }
        $made->surface = $surface;

        vkGetPhysicalDeviceSurfaceSupportKHR($device->physical(), $device->family(), $surface, $supported);
        vkGetPhysicalDeviceSurfaceFormatsKHR($device->physical(), $surface, $count, null);
        vkGetPhysicalDeviceSurfaceFormatsKHR($device->physical(), $surface, $count, $formats);
        $has = false;
        foreach ($formats ?? [] as $format) {
            $has = $has || ($format->format === self::FORMAT && $format->colorSpace === VK_COLOR_SPACE_SRGB_NONLINEAR_KHR);
        }
        if (! $supported || ! $has) {
            $made->release();

            return null;
        }
        vkGetPhysicalDeviceSurfacePresentModesKHR($device->physical(), $surface, $count, null);
        vkGetPhysicalDeviceSurfacePresentModesKHR($device->physical(), $surface, $count, $modes);
        if (in_array(VK_PRESENT_MODE_IMMEDIATE_KHR, $modes ?? [], true)) {
            $made->mode = VK_PRESENT_MODE_IMMEDIATE_KHR;
        }

        return $made;
    }

    /**
     * Shows $frame: the GPU copies its image into the swapchain's next image and presents it. When the swapchain
     * has no image free, the frame is not shown.
     *
     * @internal
     *
     * @throws VulkanException
     */
    public function show(VulkanFramebuffer $frame, float $scale): void
    {
        if ($this->released) {
            return;
        }
        [$width, $height] = [$frame->width(), $frame->height()];
        $this->fit($width, $height, $scale);
        $vk = $this->device->device();

        [$acquired, $drawn] = $this->pair();
        $result = vkAcquireNextImageKHR($vk, $this->swapchain, 0, $acquired, null, $index);
        if ($result !== VK_SUCCESS && $result !== VK_SUBOPTIMAL_KHR) {
            $this->semaphores[] = [$acquired, $drawn];
            if ($result === VK_ERROR_OUT_OF_DATE_KHR) {
                // made again for the next frame
                $this->size = [0, 0];

                return;
            }
            if ($result === VK_NOT_READY || $result === VK_TIMEOUT) {
                return;
            }
            VKLogicalDevice::ok($result, 'vkAcquireNextImageKHR');
        }
        $target = $this->images[$index];

        $commands = $this->device->commands();
        $all = VK_PIPELINE_STAGE_ALL_COMMANDS_BIT;
        $transfer = VK_PIPELINE_STAGE_TRANSFER_BIT;
        // what drew the frame so far, then the swapchain's image made ready to be written
        self::barrier($commands, $frame->image(), VK_IMAGE_LAYOUT_GENERAL, VK_IMAGE_LAYOUT_GENERAL, VK_ACCESS_MEMORY_WRITE_BIT, VK_ACCESS_TRANSFER_READ_BIT, $all, $transfer);
        self::barrier($commands, $target, VK_IMAGE_LAYOUT_UNDEFINED, VK_IMAGE_LAYOUT_TRANSFER_DST_OPTIMAL, 0, VK_ACCESS_TRANSFER_WRITE_BIT, $transfer, $transfer);

        $region = new VkImageBlit;
        foreach ([$region->srcSubresource, $region->dstSubresource] as $layers) {
            $layers->aspectMask = VK_IMAGE_ASPECT_COLOR_BIT;
            $layers->layerCount = 1;
        }
        $far = new VkOffset3D;
        [$far->x, $far->y, $far->z] = [$width, $height, 1];
        $region->srcOffsets = [new VkOffset3D, $far];
        $region->dstOffsets = [new VkOffset3D, $far];
        vkCmdBlitImage($commands, $frame->image(), VK_IMAGE_LAYOUT_GENERAL, $target, VK_IMAGE_LAYOUT_TRANSFER_DST_OPTIMAL, 1, [$region], VK_FILTER_NEAREST);

        self::barrier($commands, $target, VK_IMAGE_LAYOUT_TRANSFER_DST_OPTIMAL, VK_IMAGE_LAYOUT_PRESENT_SRC_KHR, VK_ACCESS_TRANSFER_WRITE_BIT, 0, $transfer, VK_PIPELINE_STAGE_BOTTOM_OF_PIPE_BIT);
        self::barrier($commands, $frame->image(), VK_IMAGE_LAYOUT_GENERAL, VK_IMAGE_LAYOUT_GENERAL, VK_ACCESS_TRANSFER_READ_BIT, VK_ACCESS_MEMORY_READ_BIT | VK_ACCESS_MEMORY_WRITE_BIT, $transfer, $all);

        // the pair is free again once the GPU has finished this frame
        $this->device->after($commands, function () use ($acquired, $drawn): void {
            $this->semaphores[] = [$acquired, $drawn];
        });
        $this->device->submit($commands, $acquired, $drawn);

        $present = new VkPresentInfoKHR;
        $present->waitSemaphoreCount = 1;
        $present->pWaitSemaphores = [$drawn];
        $present->swapchainCount = 1;
        $present->pSwapchains = [$this->swapchain];
        $present->pImageIndices = [$index];
        $result = vkQueuePresentKHR($this->device->queue(), $present);
        if ($result === VK_ERROR_OUT_OF_DATE_KHR) {
            $this->size = [0, 0];
        } elseif ($result !== VK_SUBOPTIMAL_KHR) {
            VKLogicalDevice::ok($result, 'vkQueuePresentKHR');
        }
    }

    /**
     * Takes the child view away and destroys the swapchain and the surface. Only once the GPU has finished
     * everything submitted.
     *
     * @internal
     */
    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        $vk = $this->device->device();
        foreach ($this->made as $semaphore) {
            vkDestroySemaphore($vk, $semaphore, 0);
        }
        if (! is_null($this->swapchain)) {
            vkDestroySwapchainKHR($vk, $this->swapchain, 0);
        }
        if (! is_null($this->surface)) {
            vkDestroySurfaceKHR($this->device->instance(), $this->surface, 0);
        }
        [$this->made, $this->semaphores, $this->images, $this->swapchain, $this->surface] = [[], [], [], null, null];
        $this->view->removeFromSuperview();
    }

    /**
     * The child view sized to the stage's, and a swapchain of $width x $height pixels: made again when the size changes.
     *
     * @throws VulkanException
     */
    protected function fit(int $width, int $height, float $scale): void
    {
        $this->view->setFrame($this->stage->bounds());
        if ($this->size === [$width, $height]) {
            return;
        }
        $this->layer->setContentsScale($scale);
        vkGetPhysicalDeviceSurfaceCapabilitiesKHR($this->device->physical(), $this->surface, $capabilities);

        $info = new VkSwapchainCreateInfoKHR;
        $info->surface = $this->surface;
        $count = max(3, $capabilities->minImageCount);
        $info->minImageCount = $capabilities->maxImageCount > 0 ? min($count, $capabilities->maxImageCount) : $count;
        $info->imageFormat = self::FORMAT;
        $info->imageColorSpace = VK_COLOR_SPACE_SRGB_NONLINEAR_KHR;
        $info->imageExtent = new VkExtent2D;
        [$info->imageExtent->width, $info->imageExtent->height] = [$width, $height];
        $info->imageArrayLayers = 1;
        $info->imageUsage = VK_IMAGE_USAGE_COLOR_ATTACHMENT_BIT | VK_IMAGE_USAGE_TRANSFER_DST_BIT;
        $info->imageSharingMode = VK_SHARING_MODE_EXCLUSIVE;
        $info->preTransform = $capabilities->currentTransform;
        $info->compositeAlpha = VK_COMPOSITE_ALPHA_OPAQUE_BIT_KHR;
        $info->presentMode = $this->mode;
        $info->clipped = true;
        $info->oldSwapchain = $this->swapchain;

        $vk = $this->device->device();
        // nothing submitted may still be writing the old swapchain's images when it goes
        $this->device->sync();
        VKLogicalDevice::ok(vkCreateSwapchainKHR($vk, $info, 0, $swapchain), 'vkCreateSwapchainKHR');
        if (! is_null($this->swapchain)) {
            vkDestroySwapchainKHR($vk, $this->swapchain, 0);
        }
        $this->swapchain = $swapchain;
        VKLogicalDevice::ok(vkGetSwapchainImagesKHR($vk, $swapchain, $count, null), 'vkGetSwapchainImagesKHR');
        VKLogicalDevice::ok(vkGetSwapchainImagesKHR($vk, $swapchain, $count, $images), 'vkGetSwapchainImagesKHR');
        $this->images = $images;
        $this->size = [$width, $height];
    }

    /**
     * A pair of semaphores nothing submitted is using.
     *
     * @return array{VkSemaphore, VkSemaphore}
     *
     * @throws VulkanException
     */
    protected function pair(): array
    {
        if ($this->semaphores !== []) {
            return array_shift($this->semaphores);
        }
        $pair = [];
        for ($i = 0; $i < 2; $i++) {
            VKLogicalDevice::ok(vkCreateSemaphore($this->device->device(), new VkSemaphoreCreateInfo, 0, $semaphore), 'vkCreateSemaphore');
            $pair[] = $this->made[] = $semaphore;
        }

        return $pair;
    }

    protected static function barrier(\VkCommandBuffer $commands, VkImage $image, int $old, int $new, int $srcAccess, int $dstAccess, int $srcStage, int $dstStage): void
    {
        $b = new VkImageMemoryBarrier;
        $b->srcAccessMask = $srcAccess;
        $b->dstAccessMask = $dstAccess;
        $b->oldLayout = $old;
        $b->newLayout = $new;
        $b->srcQueueFamilyIndex = VK_QUEUE_FAMILY_IGNORED;
        $b->dstQueueFamilyIndex = VK_QUEUE_FAMILY_IGNORED;
        $b->image = $image;
        $b->subresourceRange->aspectMask = VK_IMAGE_ASPECT_COLOR_BIT;
        $b->subresourceRange->levelCount = 1;
        $b->subresourceRange->layerCount = 1;
        vkCmdPipelineBarrier($commands, $srcStage, $dstStage, 0, 0, [], 0, [], 1, [$b]);
    }
}
