<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan;

use Jovian\Bindings\Vulkan\Enums\VkAccessFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkBufferUsageFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkCommandBufferLevel;
use Jovian\Bindings\Vulkan\Enums\VkCommandBufferUsageFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkCommandPoolCreateFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkFormat;
use Jovian\Bindings\Vulkan\Enums\VkImageAspectFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkImageLayout;
use Jovian\Bindings\Vulkan\Enums\VkIndexType;
use Jovian\Bindings\Vulkan\Enums\VkMemoryPropertyFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkPipelineBindPoint;
use Jovian\Bindings\Vulkan\Enums\VkPipelineStageFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkResult;
use Jovian\Bindings\Vulkan\Enums\VkShaderStageFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkSubpassContents;
use Jovian\Bindings\Vulkan\Ext\KHRSwapchain;
use Jovian\Bindings\Vulkan\Runtime\Bridge;
use Jovian\Bindings\Vulkan\Structs\VkBufferImageCopy;
use Jovian\Bindings\Vulkan\Structs\VkClearColorValue;
use Jovian\Bindings\Vulkan\Structs\VkClearValue;
use Jovian\Bindings\Vulkan\Structs\VkCommandBufferAllocateInfo;
use Jovian\Bindings\Vulkan\Structs\VkCommandBufferBeginInfo;
use Jovian\Bindings\Vulkan\Structs\VkCommandPoolCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkExtent2D;
use Jovian\Bindings\Vulkan\Structs\VkExtent3D;
use Jovian\Bindings\Vulkan\Structs\VkFenceCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkImageSubresourceLayers;
use Jovian\Bindings\Vulkan\Structs\VkOffset2D;
use Jovian\Bindings\Vulkan\Structs\VkOffset3D;
use Jovian\Bindings\Vulkan\Structs\VkPresentInfoKHR;
use Jovian\Bindings\Vulkan\Structs\VkRect2D;
use Jovian\Bindings\Vulkan\Structs\VkRenderPassBeginInfo;
use Jovian\Bindings\Vulkan\Structs\VkSemaphoreCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkSubmitInfo;
use Jovian\Bindings\Vulkan\Structs\VkViewport;
use Jovian\Bindings\Vulkan\VK\VK10;
use Jovian\Bindings\Vulkan\Values\ApiVersion;
use Jovian\Venusian\Vulkan\Contracts\SurfaceHost;
use Jovian\Venusian\Vulkan\Contracts\VulkanDrawing;
use Jovian\Venusian\Vulkan\Enums\Budget;
use Jovian\Venusian\Vulkan\Enums\Timeout;
use Jovian\Venusian\Vulkan\Exceptions\VulkanDrawingException;
use Jovian\Venusian\Vulkan\Support\Blocks;
use Jovian\Venusian\Vulkan\Targets\Pipelines;
use Jovian\Venusian\Vulkan\Targets\RenderPass;
use Jovian\Venusian\Vulkan\Targets\Swapchain;
use Jovian\Venusian\Vulkan\Values\GpuTexture;
use Jovian\Venusian\Vulkan\Values\Retirement;
use Jovian\Venusian\Vulkan\Values\StagingPlan;
use Surface\Contracts\Drawing\Executor;
use Surface\Contracts\Drawing\ExecutorCapabilities;
use Surface\Contracts\Drawing\TextureHandle;
use Surface\Contracts\Drawing\Topology;
use Surface\Contracts\Drawing\Transform;
use Surface\Contracts\NativeWindows\Views\Color;

/**
 * One surface, one swapchain, one command buffer. Staging growth and
 * texture release retire handles; reap() runs behind the frame fence.
 */
final class VulkanExecutor implements Executor, VulkanDrawing
{
    private readonly Blocks $scratch;

    private readonly Retirement $retirement;

    private int $surface;

    private int $commandPool = 0;

    private int $commandBufferHandle = 0;

    private int $fence = 0;

    private int $imageAvailable = 0;

    private int $renderFinished = 0;

    private int $fenceHandles = 0;

    private int $imageAvailableHandles = 0;

    private int $renderFinishedHandles = 0;

    private int $commandBufferHandles = 0;

    private int $waitStage = 0;

    private int $imageIndexBlock = 0;

    private int $swapchainHandleBlock = 0;

    private int $pushConstants = 0;

    private int $viewportBlock = 0;

    private int $scissorBlock = 0;

    private int $clearValue = 0;

    private int $renderPassBegin = 0;

    private int $submitInfo = 0;

    private int $presentInfo = 0;

    private int $beginInfo = 0;

    private int $vertexBuffers = 0;

    private int $vertexOffsets = 0;

    private int $descriptorSets = 0;

    private int $copyRegion = 0;

    private int $stagingBuffer = 0;

    private int $stagingMemory = 0;

    private int $stagingMapped = 0;

    private int $stagingSize = 0;

    private int $readbackBuffer = 0;

    private int $readbackMemory = 0;

    private int $readbackMapped = 0;

    private int $readbackSize = 0;

    private ?Swapchain $swapchain = null;

    private ?RenderPass $passes = null;

    private ?Pipelines $pipelines = null;

    private ?VulkanFrame $frame = null;

    /** @var array<int, GpuTexture> */
    private array $textures = [];

    private int $nextTextureId = 1;

    private int $pixelWidth;

    private int $pixelHeight;

    private int $viewportX = 0;

    private int $viewportY = 0;

    private int $viewportWidth;

    private int $viewportHeight;

    private int $scissorX = 0;

    private int $scissorY = 0;

    private int $scissorWidth;

    private int $scissorHeight;

    private bool $rebuildPending = false;

    private bool $fencePending = false;

    private bool $released = false;

    public function __construct(
        private readonly VulkanContext $context,
        private readonly SurfaceHost $host,
        int $width,
        int $height,
    ) {
        $this->scratch = new Blocks;
        $this->retirement = new Retirement;
        $this->pixelWidth = max(1, $width);
        $this->pixelHeight = max(1, $height);
        $this->viewportWidth = $this->pixelWidth;
        $this->viewportHeight = $this->pixelHeight;
        $this->scissorWidth = $this->pixelWidth;
        $this->scissorHeight = $this->pixelHeight;

        $this->surface = $this->host->createSurface($this->context->instance);
        if ($this->surface === 0) {
            throw VulkanDrawingException::allocation();
        }

        try {
            $this->allocateSync();
            $this->allocateScratch();
            $this->allocateStaging(Budget::STAGING_INITIAL_BYTES->value);
            $this->host->setDrawableSize($this->pixelWidth, $this->pixelHeight);
            $this->rebuildTarget();
        } catch (\Throwable $failure) {
            // A surface left alive keeps a lent window claimed (NATIVE_WINDOW_IN_USE on retry).
            try {
                $this->release();
            } catch (\Throwable) {
            }

            throw $failure;
        }
    }

    public static function declaredCapabilities(int $maxTextureSize): ExecutorCapabilities
    {
        return new ExecutorCapabilities(
            blending: true,
            depth: false,
            instancing: true,
            readback: true,
            max_texture_size: $maxTextureSize,
        );
    }

    public function capabilities(): ExecutorCapabilities
    {
        return self::declaredCapabilities($this->context->maxTextureSize);
    }

    public function instance(): int
    {
        return $this->context->instance;
    }

    public function physicalDevice(): int
    {
        return $this->context->physicalDevice;
    }

    public function device(): int
    {
        return $this->context->device;
    }

    public function queue(): int
    {
        return $this->context->queue;
    }

    public function queueFamily(): int
    {
        return $this->context->queueFamily;
    }

    public function surface(): int
    {
        return $this->surface;
    }

    public function swapchain(): int
    {
        return $this->swapchain?->handle ?? 0;
    }

    public function swapchainFormat(): int
    {
        if (is_null($this->swapchain)) {
            return 0;
        }

        return self::formatInt($this->swapchain->choice->format);
    }

    public function commandBuffer(): ?int
    {
        return is_null($this->frame) ? null : $this->commandBufferHandle;
    }

    public function renderPass(): int
    {
        return $this->passes?->clear ?? 0;
    }

    public function pipelineLayout(): int
    {
        return $this->context->pipelineLayout;
    }

    public function loaderVersion(): ApiVersion
    {
        return $this->context->loaderVersion;
    }

    public function retirementPending(): int
    {
        return $this->retirement->pending();
    }

    public function resize(int $width, int $height): void
    {
        $this->assertAlive();
        $this->pixelWidth = max(1, $width);
        $this->pixelHeight = max(1, $height);
        $this->host->setDrawableSize($this->pixelWidth, $this->pixelHeight);
        $this->rebuildPending = true;
    }

    /** @return array{int, int} */
    public function drawableSize(): array
    {
        return [$this->pixelWidth, $this->pixelHeight];
    }

    public function beginFrame(Color $clear): bool
    {
        $this->assertAlive();
        if (! is_null($this->frame)) {
            $this->endFrame();
        }

        $this->waitFence();
        $this->retirement->reap();

        $index = -1;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($this->rebuildPending) {
                $this->rebuildTarget();
            }

            if (is_null($this->swapchain) || $this->pixelWidth < 1 || $this->pixelHeight < 1) {
                return false;
            }

            $code = VulkanDrawingException::code(KHRSwapchain::vkAcquireNextImageKHR(
                $this->context->device,
                $this->swapchain->handle,
                Timeout::FOREVER->value,
                $this->imageAvailable,
                0,
                $this->imageIndexBlock,
            ));
            if ($code === VkResult::ERROR_OUT_OF_DATE_KHR->value) {
                $this->rebuildPending = true;

                continue;
            }
            if ($code === VkResult::SUBOPTIMAL_KHR->value) {
                // MoltenVK reports SUBOPTIMAL while contentsScale is 1 and
                // the drawable is @2x. The image is already host pixels.
            } elseif ($code !== VkResult::SUCCESS->value) {
                throw VulkanDrawingException::result('vkAcquireNextImageKHR', $code);
            }

            $index = Blocks::countAt($this->imageIndexBlock);

            break;
        }

        if ($index < 0) {
            return false;
        }
        $this->frame = new VulkanFrame(
            image_index: $index,
            pass_open: false,
            acquire_waited: false,
            staging_cursor: 0,
        );
        $this->beginRecording();
        $this->beginPass($this->passes->clear, $clear);
        $this->applyViewport(0, 0, $this->pixelWidth, $this->pixelHeight);
        $this->applyScissor(0, 0, $this->pixelWidth, $this->pixelHeight);

        return true;
    }

    public function viewport(int $x, int $y, int $width, int $height): void
    {
        $this->assertInFrame('viewport');
        $this->applyViewport($x, $y, $width, $height);
    }

    public function scissor(int $x, int $y, int $width, int $height): void
    {
        $this->assertInFrame('scissor');
        $this->applyScissor($x, $y, $width, $height);
    }

    public function unscissor(): void
    {
        $this->assertInFrame('unscissor');
        $this->applyScissor(0, 0, $this->pixelWidth, $this->pixelHeight);
    }

    public function texture(string $rgba8, int $width, int $height): TextureHandle
    {
        $this->assertAlive();
        $gpu = $this->context->uploadTexture($rgba8, $width, $height);
        $id = $this->nextTextureId++;
        $this->textures[$id] = $gpu;

        return new TextureHandle($id, $width, $height);
    }

    public function releaseTexture(TextureHandle $texture): void
    {
        $this->assertAlive();
        $held = $this->textures[$texture->id] ?? null;
        if (is_null($held)) {
            throw new VulkanDrawingException('texture handle is not held by this executor');
        }

        unset($this->textures[$texture->id]);
        $context = $this->context;
        $this->retirement->retire(static fn () => $context->destroyTexture($held));
    }

    public function draw(
        Topology $topology,
        string $vertices,
        int $vertex_count,
        Transform $transform,
        ?TextureHandle $texture = null,
        int $instances = 1,
    ): void {
        $this->assertInFrame('draw');
        if ($vertex_count < 1) {
            return;
        }

        $offset = $this->stage($vertices);
        $this->bindDraw($topology, $offset, $transform, $texture);
        VK10::vkCmdDraw($this->commandBufferHandle, $vertex_count, max(1, $instances), 0, 0);
    }

    public function drawIndexed(
        Topology $topology,
        string $vertices,
        int $vertex_count,
        string $indices,
        int $index_count,
        Transform $transform,
        ?TextureHandle $texture = null,
        int $instances = 1,
    ): void {
        $this->assertInFrame('drawIndexed');
        if ($index_count < 1) {
            return;
        }

        $vertexOffset = $this->stage($vertices);
        $indexOffset = $this->stage($indices);
        $this->bindDraw($topology, $vertexOffset, $transform, $texture);
        VK10::vkCmdBindIndexBuffer(
            $this->commandBufferHandle,
            $this->stagingBuffer,
            $indexOffset,
            VkIndexType::UINT16,
        );
        VK10::vkCmdDrawIndexed($this->commandBufferHandle, $index_count, max(1, $instances), 0, 0, 0);
    }

    public function readPixels(): string
    {
        $this->assertInFrame('readPixels');
        $this->endPass();

        $width = $this->pixelWidth;
        $height = $this->pixelHeight;
        $bytes = $width * $height * 4;
        $this->ensureReadback($bytes);

        $image = $this->swapchain->images[$this->frame->image_index];
        $this->context->imageBarrier(
            $this->commandBufferHandle,
            $image,
            VkImageLayout::PRESENT_SRC_KHR,
            VkImageLayout::TRANSFER_SRC_OPTIMAL,
            VkPipelineStageFlagBits::COLOR_ATTACHMENT_OUTPUT_BIT->value,
            VkPipelineStageFlagBits::TRANSFER_BIT->value,
            VkAccessFlagBits::COLOR_ATTACHMENT_WRITE_BIT->value,
            VkAccessFlagBits::TRANSFER_READ_BIT->value,
        );

        (new VkBufferImageCopy(
            bufferOffset: 0,
            bufferRowLength: 0,
            bufferImageHeight: 0,
            imageSubresource: new VkImageSubresourceLayers(
                aspectMask: VkImageAspectFlagBits::COLOR_BIT->value,
                mipLevel: 0,
                baseArrayLayer: 0,
                layerCount: 1,
            ),
            imageOffset: new VkOffset3D(x: 0, y: 0, z: 0),
            imageExtent: new VkExtent3D(width: $width, height: $height, depth: 1),
        ))->packInto($this->copyRegion);
        VK10::vkCmdCopyImageToBuffer(
            $this->commandBufferHandle,
            $image,
            VkImageLayout::TRANSFER_SRC_OPTIMAL,
            $this->readbackBuffer,
            1,
            $this->copyRegion,
        );

        $this->submitRecorded(signalPresent: false);
        $this->waitFence();

        $pixels = (string) Bridge::read($this->readbackMapped, 0, $bytes);
        if ($this->swapchain->isBgra()) {
            $pixels = self::swizzleBgraToRgba($pixels);
        }

        $this->beginRecording();
        $this->beginPass($this->passes->load, null);
        $this->applyViewport($this->viewportX, $this->viewportY, $this->viewportWidth, $this->viewportHeight);
        $this->applyScissor($this->scissorX, $this->scissorY, $this->scissorWidth, $this->scissorHeight);

        return $pixels;
    }

    public function endFrame(): void
    {
        if (is_null($this->frame)) {
            return;
        }

        $this->endPass();
        $this->submitRecorded(signalPresent: true);

        Bridge::write($this->swapchainHandleBlock, 0, pack('P', $this->swapchain->handle));
        Bridge::write($this->imageIndexBlock, 0, pack('V', $this->frame->image_index));
        (new VkPresentInfoKHR(
            waitSemaphoreCount: 1,
            pWaitSemaphores: $this->renderFinishedHandles,
            swapchainCount: 1,
            pSwapchains: $this->swapchainHandleBlock,
            pImageIndices: $this->imageIndexBlock,
        ))->packInto($this->presentInfo);

        $code = VulkanDrawingException::code(
            KHRSwapchain::vkQueuePresentKHR($this->context->queue, $this->presentInfo),
        );
        if ($code === VkResult::ERROR_OUT_OF_DATE_KHR->value) {
            $this->rebuildPending = true;
        } elseif ($code === VkResult::SUBOPTIMAL_KHR->value) {
            // See beginFrame — retina mismatch is chronic until contentsScale sticks.
        } elseif ($code !== VkResult::SUCCESS->value) {
            throw VulkanDrawingException::result('vkQueuePresentKHR', $code);
        }

        $this->frame = null;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        $this->frame = null;

        VK10::vkDeviceWaitIdle($this->context->device);
        $this->retirement->reap();

        foreach ($this->textures as $texture) {
            $this->context->destroyTexture($texture);
        }
        $this->textures = [];

        $this->destroyMappedBuffer($this->stagingBuffer, $this->stagingMemory);
        $this->destroyMappedBuffer($this->readbackBuffer, $this->readbackMemory);
        $this->stagingBuffer = 0;
        $this->stagingMemory = 0;
        $this->stagingMapped = 0;
        $this->readbackBuffer = 0;
        $this->readbackMemory = 0;
        $this->readbackMapped = 0;

        $this->pipelines?->destroy($this->context->device);
        $this->passes?->destroy($this->context->device);
        $this->swapchain?->destroy($this->context);
        $this->pipelines = null;
        $this->passes = null;
        $this->swapchain = null;

        if ($this->fence !== 0) {
            VK10::vkDestroyFence($this->context->device, $this->fence, 0);
        }
        if ($this->imageAvailable !== 0) {
            VK10::vkDestroySemaphore($this->context->device, $this->imageAvailable, 0);
        }
        if ($this->renderFinished !== 0) {
            VK10::vkDestroySemaphore($this->context->device, $this->renderFinished, 0);
        }
        if ($this->commandPool !== 0) {
            VK10::vkDestroyCommandPool($this->context->device, $this->commandPool, 0);
        }
        if ($this->surface !== 0) {
            $this->host->destroySurface($this->context->instance, $this->surface);
            $this->surface = 0;
        }

        $this->scratch->release();
        $this->host->release();
    }

    private function allocateSync(): void
    {
        $poolInfo = $this->scratch->keep((new VkCommandPoolCreateInfo(
            flags: VkCommandPoolCreateFlagBits::RESET_COMMAND_BUFFER_BIT->value,
            queueFamilyIndex: $this->context->queueFamily,
        ))->pack());
        $this->commandPool = $this->scratch->create(
            'vkCreateCommandPool',
            fn (int $out) => VK10::vkCreateCommandPool($this->context->device, $poolInfo, 0, $out),
        );

        $alloc = $this->scratch->keep((new VkCommandBufferAllocateInfo(
            commandPool: $this->commandPool,
            level: VkCommandBufferLevel::PRIMARY,
            commandBufferCount: 1,
        ))->pack());
        $buffers = $this->scratch->alloc(Budget::HANDLE_BYTES->value);
        VulkanDrawingException::check(
            VK10::vkAllocateCommandBuffers($this->context->device, $alloc, $buffers),
            'vkAllocateCommandBuffers',
        );
        $this->commandBufferHandle = Blocks::handleAt($buffers);
        if ($this->commandBufferHandle === 0) {
            throw VulkanDrawingException::allocation();
        }

        $this->fence = $this->scratch->create(
            'vkCreateFence',
            fn (int $out) => VK10::vkCreateFence(
                $this->context->device,
                $this->scratch->keep((new VkFenceCreateInfo)->pack()),
                0,
                $out,
            ),
        );
        $semaphoreInfo = $this->scratch->keep((new VkSemaphoreCreateInfo)->pack());
        $this->imageAvailable = $this->scratch->create(
            'vkCreateSemaphore',
            fn (int $out) => VK10::vkCreateSemaphore($this->context->device, $semaphoreInfo, 0, $out),
        );
        $this->renderFinished = $this->scratch->create(
            'vkCreateSemaphore',
            fn (int $out) => VK10::vkCreateSemaphore($this->context->device, $semaphoreInfo, 0, $out),
        );
    }

    private function allocateScratch(): void
    {
        $this->fenceHandles = $this->scratch->handles([$this->fence]);
        $this->imageAvailableHandles = $this->scratch->handles([$this->imageAvailable]);
        $this->renderFinishedHandles = $this->scratch->handles([$this->renderFinished]);
        $this->commandBufferHandles = $this->scratch->handles([$this->commandBufferHandle]);
        $this->waitStage = $this->scratch->uint32s([VkPipelineStageFlagBits::COLOR_ATTACHMENT_OUTPUT_BIT->value]);
        $this->imageIndexBlock = $this->scratch->alloc(Budget::COUNT_BYTES->value);
        $this->swapchainHandleBlock = $this->scratch->alloc(Budget::HANDLE_BYTES->value);
        $this->pushConstants = $this->scratch->alloc(Budget::PUSH_CONSTANT_BYTES->value);
        $this->viewportBlock = $this->scratch->alloc(VkViewport::size());
        $this->scissorBlock = $this->scratch->alloc(VkRect2D::size());
        $this->clearValue = $this->scratch->alloc(VkClearValue::size());
        $this->renderPassBegin = $this->scratch->alloc(VkRenderPassBeginInfo::size());
        $this->submitInfo = $this->scratch->alloc(VkSubmitInfo::size());
        $this->presentInfo = $this->scratch->alloc(VkPresentInfoKHR::size());
        $this->beginInfo = $this->scratch->alloc(VkCommandBufferBeginInfo::size());
        $this->vertexBuffers = $this->scratch->alloc(Budget::HANDLE_BYTES->value);
        $this->vertexOffsets = $this->scratch->alloc(Budget::HANDLE_BYTES->value);
        $this->descriptorSets = $this->scratch->alloc(Budget::HANDLE_BYTES->value);
        $this->copyRegion = $this->scratch->alloc(VkBufferImageCopy::size());
    }

    private function allocateStaging(int $size): void
    {
        $host = VkMemoryPropertyFlagBits::HOST_VISIBLE_BIT->value
            | VkMemoryPropertyFlagBits::HOST_COHERENT_BIT->value;
        $usage = VkBufferUsageFlagBits::VERTEX_BUFFER_BIT->value
            | VkBufferUsageFlagBits::INDEX_BUFFER_BIT->value;
        [$buffer, $memory] = $this->context->createBuffer($size, $usage, $host);
        $this->stagingBuffer = $buffer;
        $this->stagingMemory = $memory;
        $this->stagingMapped = $this->context->mapMemory($memory);
        $this->stagingSize = $size;
    }

    private function rebuildTarget(): void
    {
        VulkanDrawingException::check(
            VK10::vkDeviceWaitIdle($this->context->device),
            'vkDeviceWaitIdle',
        );

        $this->host->setDrawableSize($this->pixelWidth, $this->pixelHeight);
        $choice = Swapchain::query($this->context, $this->surface, $this->pixelWidth, $this->pixelHeight);
        if ($choice->width < 1 || $choice->height < 1) {
            $this->rebuildPending = true;

            return;
        }

        $old = $this->swapchain;
        $created = Swapchain::create($this->context, $this->surface, $choice, $old?->handle ?? 0);
        $old?->destroy($this->context);

        $newFormat = self::formatInt($choice->format);
        $oldFormat = is_null($this->passes) ? -1 : self::formatInt($this->passes->format);
        if ($newFormat !== $oldFormat) {
            $this->pipelines?->destroy($this->context->device);
            $this->passes?->destroy($this->context->device);
            $this->passes = RenderPass::create($this->context, $choice->format);
            $this->pipelines = Pipelines::create($this->context, $this->passes->clear);
        }

        $created->attachFramebuffers($this->context, $this->passes->clear);
        $this->swapchain = $created;
        $this->pixelWidth = $choice->width;
        $this->pixelHeight = $choice->height;
        $this->rebuildPending = false;
        Bridge::write($this->swapchainHandleBlock, 0, pack('P', $created->handle));
    }

    private function beginRecording(): void
    {
        VulkanDrawingException::check(
            VK10::vkResetCommandBuffer($this->commandBufferHandle, 0),
            'vkResetCommandBuffer',
        );
        (new VkCommandBufferBeginInfo(
            flags: VkCommandBufferUsageFlagBits::ONE_TIME_SUBMIT_BIT->value,
        ))->packInto($this->beginInfo);
        VulkanDrawingException::check(
            VK10::vkBeginCommandBuffer($this->commandBufferHandle, $this->beginInfo),
            'vkBeginCommandBuffer',
        );
    }

    private function beginPass(int $pass, ?Color $clear): void
    {
        $framebuffer = $this->swapchain->framebuffers[$this->frame->image_index];
        $hasClear = ! is_null($clear);
        if ($hasClear) {
            (new VkClearValue(
                color: new VkClearColorValue(float32: [$clear->red, $clear->green, $clear->blue, $clear->alpha]),
            ))->packInto($this->clearValue);
        }

        (new VkRenderPassBeginInfo(
            renderPass: $pass,
            framebuffer: $framebuffer,
            renderArea: new VkRect2D(
                offset: new VkOffset2D(x: 0, y: 0),
                extent: new VkExtent2D(width: $this->pixelWidth, height: $this->pixelHeight),
            ),
            clearValueCount: $hasClear ? 1 : 0,
            pClearValues: $hasClear ? $this->clearValue : 0,
        ))->packInto($this->renderPassBegin);
        VK10::vkCmdBeginRenderPass(
            $this->commandBufferHandle,
            $this->renderPassBegin,
            VkSubpassContents::INLINE,
        );
        $this->frame->pass_open = true;
    }

    private function endPass(): void
    {
        if (is_null($this->frame) || ! $this->frame->pass_open) {
            return;
        }

        VK10::vkCmdEndRenderPass($this->commandBufferHandle);
        $this->frame->pass_open = false;
    }

    private function submitRecorded(bool $signalPresent): void
    {
        VulkanDrawingException::check(
            VK10::vkEndCommandBuffer($this->commandBufferHandle),
            'vkEndCommandBuffer',
        );

        $wait = ! $this->frame->acquire_waited;
        if ($wait) {
            $this->frame->acquire_waited = true;
        }

        (new VkSubmitInfo(
            waitSemaphoreCount: $wait ? 1 : 0,
            pWaitSemaphores: $wait ? $this->imageAvailableHandles : 0,
            pWaitDstStageMask: $wait ? $this->waitStage : 0,
            commandBufferCount: 1,
            pCommandBuffers: $this->commandBufferHandles,
            signalSemaphoreCount: $signalPresent ? 1 : 0,
            pSignalSemaphores: $signalPresent ? $this->renderFinishedHandles : 0,
        ))->packInto($this->submitInfo);
        VulkanDrawingException::check(
            VK10::vkQueueSubmit($this->context->queue, 1, $this->submitInfo, $this->fence),
            'vkQueueSubmit',
        );
        $this->fencePending = true;
    }

    private function waitFence(): void
    {
        if (! $this->fencePending) {
            return;
        }

        VulkanDrawingException::check(
            VK10::vkWaitForFences(
                $this->context->device,
                1,
                $this->fenceHandles,
                true,
                Timeout::FOREVER->value,
            ),
            'vkWaitForFences',
        );
        VulkanDrawingException::check(
            VK10::vkResetFences($this->context->device, 1, $this->fenceHandles),
            'vkResetFences',
        );
        $this->fencePending = false;
    }

    private function stage(string $bytes): int
    {
        if ($bytes === '') {
            return 0;
        }

        $plan = StagingPlan::place($this->frame->staging_cursor, strlen($bytes));
        if ($plan->next > $this->stagingSize) {
            $this->growStaging(StagingPlan::grownSize($this->stagingSize, $plan->next));
            $plan = StagingPlan::place(0, strlen($bytes));
        }

        Bridge::write($this->stagingMapped, $plan->offset, $bytes);
        $this->frame->staging_cursor = $plan->next;

        return $plan->offset;
    }

    private function growStaging(int $size): void
    {
        $oldBuffer = $this->stagingBuffer;
        $oldMemory = $this->stagingMemory;
        $device = $this->context->device;
        $this->retirement->retire(static function () use ($device, $oldBuffer, $oldMemory): void {
            VK10::vkUnmapMemory($device, $oldMemory);
            VK10::vkDestroyBuffer($device, $oldBuffer, 0);
            VK10::vkFreeMemory($device, $oldMemory, 0);
        });
        $this->allocateStaging($size);
    }

    private function ensureReadback(int $bytes): void
    {
        if ($this->readbackSize >= $bytes && $this->readbackBuffer !== 0) {
            return;
        }

        $oldBuffer = $this->readbackBuffer;
        $oldMemory = $this->readbackMemory;
        $device = $this->context->device;
        if ($oldBuffer !== 0) {
            $this->retirement->retire(static function () use ($device, $oldBuffer, $oldMemory): void {
                VK10::vkUnmapMemory($device, $oldMemory);
                VK10::vkDestroyBuffer($device, $oldBuffer, 0);
                VK10::vkFreeMemory($device, $oldMemory, 0);
            });
        }

        $host = VkMemoryPropertyFlagBits::HOST_VISIBLE_BIT->value
            | VkMemoryPropertyFlagBits::HOST_COHERENT_BIT->value;
        $size = max($bytes, $this->pixelWidth * $this->pixelHeight * 4);
        [$buffer, $memory] = $this->context->createBuffer(
            $size,
            VkBufferUsageFlagBits::TRANSFER_DST_BIT->value,
            $host,
        );
        $this->readbackBuffer = $buffer;
        $this->readbackMemory = $memory;
        $this->readbackMapped = $this->context->mapMemory($memory);
        $this->readbackSize = $size;
    }

    private function bindDraw(
        Topology $topology,
        int $vertexOffset,
        Transform $transform,
        ?TextureHandle $texture,
    ): void {
        $pipeline = $this->pipelines->get($topology);
        if ($pipeline === 0) {
            throw VulkanDrawingException::allocation();
        }

        VK10::vkCmdBindPipeline($this->commandBufferHandle, VkPipelineBindPoint::GRAPHICS, $pipeline);
        Bridge::write($this->vertexBuffers, 0, pack('P', $this->stagingBuffer));
        Bridge::write($this->vertexOffsets, 0, pack('P', $vertexOffset));
        VK10::vkCmdBindVertexBuffers(
            $this->commandBufferHandle,
            0,
            1,
            $this->vertexBuffers,
            $this->vertexOffsets,
        );

        $set = $this->context->placeholder()->set;
        if (! is_null($texture)) {
            $held = $this->textures[$texture->id] ?? null;
            if (is_null($held)) {
                throw new VulkanDrawingException('texture handle is not held by this executor');
            }
            $set = $held->set;
        }
        Bridge::write($this->descriptorSets, 0, pack('P', $set));
        VK10::vkCmdBindDescriptorSets(
            $this->commandBufferHandle,
            VkPipelineBindPoint::GRAPHICS,
            $this->context->pipelineLayout,
            0,
            1,
            $this->descriptorSets,
            0,
            0,
        );

        Bridge::write($this->pushConstants, 0, $transform->toPacked());
        VK10::vkCmdPushConstants(
            $this->commandBufferHandle,
            $this->context->pipelineLayout,
            VkShaderStageFlagBits::VERTEX_BIT->value,
            0,
            Budget::PUSH_CONSTANT_BYTES->value,
            $this->pushConstants,
        );
    }

    private function applyViewport(int $x, int $y, int $width, int $height): void
    {
        $this->viewportX = $x;
        $this->viewportY = $y;
        $this->viewportWidth = max(1, $width);
        $this->viewportHeight = max(1, $height);
        (new VkViewport(
            x: (float) $this->viewportX,
            y: (float) $this->viewportY,
            width: (float) $this->viewportWidth,
            height: (float) $this->viewportHeight,
            minDepth: 0.0,
            maxDepth: 1.0,
        ))->packInto($this->viewportBlock);
        VK10::vkCmdSetViewport($this->commandBufferHandle, 0, 1, $this->viewportBlock);
    }

    private function applyScissor(int $x, int $y, int $width, int $height): void
    {
        $x = max(0, min($x, $this->pixelWidth - 1));
        $y = max(0, min($y, $this->pixelHeight - 1));
        $width = max(1, min($width, $this->pixelWidth - $x));
        $height = max(1, min($height, $this->pixelHeight - $y));
        $this->scissorX = $x;
        $this->scissorY = $y;
        $this->scissorWidth = $width;
        $this->scissorHeight = $height;
        (new VkRect2D(
            offset: new VkOffset2D(x: $x, y: $y),
            extent: new VkExtent2D(width: $width, height: $height),
        ))->packInto($this->scissorBlock);
        VK10::vkCmdSetScissor($this->commandBufferHandle, 0, 1, $this->scissorBlock);
    }

    private function destroyMappedBuffer(int $buffer, int $memory): void
    {
        if ($buffer === 0) {
            return;
        }

        VK10::vkUnmapMemory($this->context->device, $memory);
        VK10::vkDestroyBuffer($this->context->device, $buffer, 0);
        VK10::vkFreeMemory($this->context->device, $memory, 0);
    }

    private static function formatInt(VkFormat|int $format): int
    {
        return $format instanceof VkFormat ? $format->value : (int) $format;
    }

    private static function swizzleBgraToRgba(string $bgra): string
    {
        $length = strlen($bgra);
        $rgba = $bgra;
        for ($i = 0; $i + 3 < $length; $i += 4) {
            $rgba[$i] = $bgra[$i + 2];
            $rgba[$i + 2] = $bgra[$i];
        }

        return $rgba;
    }

    private function assertAlive(): void
    {
        if ($this->released) {
            throw VulkanDrawingException::released();
        }
    }

    private function assertInFrame(string $operation): void
    {
        $this->assertAlive();
        if (is_null($this->frame)) {
            throw VulkanDrawingException::outOfFrame($operation);
        }
    }
}
