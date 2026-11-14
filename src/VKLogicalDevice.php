<?php

namespace Jovian\Engines\Vulkan;

use Closure;
use Surface\Contracts\Graphics\ActorStage;
use Surface\Contracts\Graphics\Backend;
use Surface\Contracts\Graphics\GraphicsException;
use Surface\Contracts\Graphics\Resource;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Graphics\GEFramebuffer;
use Surface\Graphics\GEPainter;
use Surface\Contracts\Rendering\Raycasting\Grid;
use Surface\Graphics\GERasterizer;
use Surface\Graphics\GERaycaster;
use Surface\Graphics\GraphicsEngine;
use NSView;
use Throwable;
use VkApplicationInfo;
use VkAttachmentDescription;
use VkAttachmentReference;
use VkBuffer;
use VkBufferCreateInfo;
use VkBufferImageCopy;
use VkBufferMemoryBarrier;
use VkCommandBuffer;
use VkCommandBufferAllocateInfo;
use VkCommandBufferBeginInfo;
use VkCommandPool;
use VkCommandPoolCreateInfo;
use VkComputePipelineCreateInfo;
use VkCopyImageToMemoryInfo;
use VkCopyMemoryToImageInfo;
use VkDescriptorBufferInfo;
use VkDescriptorImageInfo;
use VkDescriptorPool;
use VkDescriptorPoolCreateInfo;
use VkDescriptorPoolSize;
use VkDescriptorSet;
use VkDescriptorSetAllocateInfo;
use VkDescriptorSetLayout;
use VkDescriptorSetLayoutBinding;
use VkDescriptorSetLayoutCreateInfo;
use VkDevice;
use VkDeviceCreateInfo;
use VkDeviceMemory;
use VkDeviceQueueCreateInfo;
use VkExtensionProperties;
use VkFence;
use VkFenceCreateInfo;
use VkGraphicsPipelineCreateInfo;
use VkHostImageLayoutTransitionInfo;
use VkImage;
use VkImageCreateInfo;
use VkImageMemoryBarrier;
use VkImageToMemoryCopy;
use VkImageView;
use VkImageViewCreateInfo;
use VkInstance;
use VkInstanceCreateInfo;
use VkMemoryAllocateInfo;
use VkMemoryRequirements;
use VkMemoryToImageCopy;
use VkPhysicalDevice;
use VkPhysicalDeviceFeatures;
use VkPhysicalDeviceFeatures2;
use VkPhysicalDeviceHostImageCopyFeatures;
use VkPipeline;
use VkPipelineColorBlendAttachmentState;
use VkPipelineColorBlendStateCreateInfo;
use VkPipelineDepthStencilStateCreateInfo;
use VkPipelineDynamicStateCreateInfo;
use VkPipelineInputAssemblyStateCreateInfo;
use VkPipelineLayout;
use VkPipelineLayoutCreateInfo;
use VkPipelineMultisampleStateCreateInfo;
use VkPipelineRasterizationStateCreateInfo;
use VkPipelineShaderStageCreateInfo;
use VkPipelineVertexInputStateCreateInfo;
use VkPipelineViewportStateCreateInfo;
use VkPushConstantRange;
use VkQueue;
use VkSemaphore;
use VkRenderPass;
use VkRenderPassCreateInfo;
use VkSampler;
use VkSamplerCreateInfo;
use VkShaderModule;
use VkShaderModuleCreateInfo;
use VkSubmitInfo;
use VkSubpassDependency;
use VkSubpassDescription;
use VkVertexInputAttributeDescription;
use VkVertexInputBindingDescription;
use VkWriteDescriptorSet;
use WeakMap;

/**
 * The vulkan engine: one Vulkan instance, the first GPU it lists, one logical device and one
 * queue, and what they make. Every framebuffer and rasterizer it hands out works on that device.
 *
 * It draws on the GPU only. Drawing is recorded into a command buffer and handed to the queue
 * with a fence, and is not waited for: the queue runs submissions in order. At most three are in
 * flight; the CPU waits only where it reads an image's bytes or writes one a submission may
 * still use.
 *
 * It needs Vulkan 1.4 with host image copies: bytes go to and from images that way.
 */
class VKLogicalDevice extends GraphicsEngine
{
    /** The engine's SPIR-V, compiled from the GLSL beside it (glslangValidator -V -g). */
    protected const string SHADERS = __DIR__.'/../resources/shaders/engine';

    /** Command buffers: one recording and at most three with the GPU. */
    protected const int RING = 4;

    /** Push constants: the vertex stage's frame (size, origin), then the fragment stage's block. 128 bytes in all, what every device gives. */
    public const int PUSH_FRAME = 16;

    public const int PUSH_PAINT = 112;

    /** Push constants of a compute pipeline: one block, from offset 0. */
    public const int PUSH_COMPUTE = 128;

    public const int COLOR = VK_FORMAT_R8G8B8A8_UNORM;

    protected const int FREE = 0;

    protected const int RECORDING = 1;

    protected const int SUBMITTED = 2;

    protected VkInstance $instance;

    protected VkPhysicalDevice $physical;

    protected ?VkDevice $device = null;

    protected VkQueue $queue;

    protected int $family;

    protected VkCommandPool $pool;

    /** Whether a frame can be shown through a swapchain over a CAMetalLayer: the instance and the GPU have the extensions. */
    protected bool $presents = false;

    /** @var WeakMap<object, VulkanSurface> what was added to each view a stage lent, by the view */
    protected WeakMap $surfaces;

    /** @var list<\VkMemoryType> */
    protected array $memoryTypes;

    protected int $stencilFormat = 0;

    protected string $gpu;

    /** @var list<array{commands: VkCommandBuffer, fence: VkFence, state: int, after: list<Closure>}> */
    protected array $slots = [];

    /** @var list<int> slots with the GPU, oldest first */
    protected array $submitted = [];

    /** @var array<string, VkShaderModule> */
    protected array $modules = [];

    /** @var array<string, VkRenderPass> */
    protected array $passes = [];

    /** @var array<string, VkPipeline> */
    protected array $pipelines = [];

    /** @var array<int, list<array{VkBuffer, VkDeviceMemory}>> vertex buffers free to use, by size class: the buffer and its memory */
    protected array $vertexPool = [];

    /** One combined image sampler at binding 0. */
    protected VkDescriptorSetLayout $setLayout;

    /** Three such sets (set 0, 1, 2) and the push constants. */
    protected VkPipelineLayout $layout;

    /** Nearest, clamped: fragments read texels by their coordinates. */
    protected VkSampler $sampler;

    /** Three storage buffers at bindings 0 to 2, read by the fragment stage: what a raycast pass reads. */
    protected VkDescriptorSetLayout $storageLayout;

    /** A storage set (set 0), a sampler set (set 1), and the same push constants as $layout. */
    protected VkPipelineLayout $raycastLayout;

    /** One storage image at binding 0, written by the compute stage. */
    protected VkDescriptorSetLayout $imageLayout;

    /** A storage set (set 0), a sampler set (set 1), a storage image set (set 2), and the compute push constants. */
    protected VkPipelineLayout $computeLayout;

    /** The GPU's largest image side, and the most bytes a storage buffer binding may span. */
    protected int $maxSide = 0;

    protected int $maxStorage = 0;

    /**
     * @throws VulkanException When there is no Vulkan loader, no GPU, or the GPU lacks what the engine needs.
     * @throws GraphicsException When asked for the CPU backend.
     */
    public function __construct(Backend $backend = Backend::GPU, protected readonly ?Closure $recorder = null)
    {
        if ($backend !== Backend::GPU) {
            throw GraphicsException::backendNotOffered('vulkan', $backend, [Backend::GPU]);
        }
        parent::__construct($backend);

        try {
            $this->open();
        } catch (Throwable $failure) {
            $this->close();

            throw $failure;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function name(): string
    {
        return 'vulkan';
    }

    /** The GPU it draws on, by the name its driver gives it. */
    public function modules(): array
    {
        return ['gpu' => $this->gpu];
    }

    public function supports(Resource $resource): bool
    {
        return in_array($resource, [Resource::FRAMEBUFFER, Resource::RASTERIZER, Resource::RAYCASTER, Resource::PAINTER], true);
    }

    /**
     * A raycaster over $grid: its cells are held on the GPU, in a storage buffer.
     *
     * @throws VulkanException When the grid's cells are more than a storage buffer binding may span.
     */
    public function raycaster(Grid $grid): GERaycaster
    {
        return new VulkanRaycaster($this, new VulkanCaster($this, $grid));
    }

    /**
     * An RGBA8 image on the GPU, with a four-sample twin that antialiased shapes are drawn into.
     *
     * @throws GraphicsException When the size is below 1x1, or $format is not RGBA8: the only format it holds.
     */
    public function framebuffer(int $width, int $height, ?FormatSpec $format = null): GEFramebuffer
    {
        if ($width < 1 || $height < 1) {
            throw GraphicsException::badSize($width, $height);
        }
        if (! is_null($format) && ! $format->equals(FormatSpec::rgba8())) {
            throw VulkanException::because('a framebuffer holds RGBA8 only.');
        }

        return new VulkanFramebuffer($this, $width, $height);
    }

    /**
     * A framebuffer of the stage's size. Shown through a swapchain over the view the stage lends, where it lends
     * an NSView and the layer can be made; otherwise as pixels through the stage.
     *
     * @throws GraphicsException When the stage has no size yet.
     */
    public function attach(ActorStage $stage, ?FormatSpec $format = null): GEFramebuffer
    {
        [$width, $height] = $stage->pixelSize();
        if ($width < 1 || $height < 1) {
            throw GraphicsException::stageHasNoSize();
        }

        /** @var VulkanFramebuffer $framebuffer */
        $framebuffer = $this->framebuffer($width, $height, $format)->onto($stage);

        // A stage that lends an NSView is shown in through a swapchain, when ext-appkit's view and ext-metal's
        // CAMetalLayer are there to make the layer MoltenVK presents in; otherwise as pixels, through the stage.
        $view = $stage->nativeSurface();
        if ($this->presents && $view instanceof NSView && class_exists(\CAMetalLayer::class, false)) {
            $surface = $this->surfaces[$view] ?? null;
            if (is_null($surface)) {
                $surface = VulkanSurface::over($this, $view);
                if (! is_null($surface)) {
                    $this->surfaces[$view] = $surface;
                }
            }
            if (! is_null($surface)) {
                $framebuffer->showIn($surface);
            }
        }

        return $framebuffer;
    }

    public function rasterizer(Edges $edges = Edges::ANTIALIASED): GERasterizer
    {
        return new VulkanRasterizer($this, $edges);
    }

    /** @throws GraphicsException When $onto is another engine's framebuffer. */
    public function painter(GEFramebuffer $onto): GEPainter
    {
        if ($onto->engine() !== $this || ! $onto instanceof VulkanFramebuffer) {
            throw GraphicsException::foreignResource('vulkan', $onto->engine()->name());
        }

        return new VulkanGEPainter($this, $onto, new VulkanPainter($this, $onto, recorder: is_null($this->recorder) ? null : ($this->recorder)()));
    }

    /**
     * Whether the device is still open: what it made may only be destroyed while it is. At the end of a
     * script PHP may destroy the device before what it made.
     *
     * @internal
     */
    public function isOpen(): bool
    {
        return ! is_null($this->device);
    }

    /** Waits for the GPU to finish what it was handed, and takes away what it added to the views stages lent it. */
    public function release(): void
    {
        $this->sync();
        $this->dropSurfaces();
    }

    protected function dropSurfaces(): void
    {
        if (! isset($this->surfaces)) {
            return;
        }
        foreach ($this->surfaces as $view => $surface) {
            $surface->release();
        }
        $this->surfaces = new WeakMap;
    }

    // Submissions

    /**
     * A command buffer to record into, begun. With every other one still with the GPU, the oldest
     * is waited for first: that is what keeps the CPU from queueing frames faster than the GPU
     * finishes them.
     *
     * @internal
     *
     * @throws VulkanException
     */
    public function commands(): VkCommandBuffer
    {
        $slot = null;
        foreach ($this->slots as $index => $entry) {
            if ($entry['state'] === self::FREE) {
                $slot = $index;
                break;
            }
        }
        if (is_null($slot)) {
            if ($this->submitted === []) {
                throw VulkanException::because('more than '.self::RING.' command buffers are being recorded at once.');
            }
            $slot = array_shift($this->submitted);
            $this->finish($slot);
        }

        $commands = $this->slots[$slot]['commands'];
        self::ok(vkResetCommandBuffer($commands, 0), 'vkResetCommandBuffer');
        $begin = new VkCommandBufferBeginInfo;
        $begin->flags = VK_COMMAND_BUFFER_USAGE_ONE_TIME_SUBMIT_BIT;
        self::ok(vkBeginCommandBuffer($commands, $begin), 'vkBeginCommandBuffer');
        $this->slots[$slot]['state'] = self::RECORDING;

        return $commands;
    }

    /**
     * Hands $commands to the GPU and returns: the CPU goes on while the GPU draws. Anything
     * submitted later sees what these drew.
     *
     * @internal
     *
     * @throws VulkanException
     */
    public function submit(VkCommandBuffer $commands, ?VkSemaphore $wait = null, ?VkSemaphore $signal = null): void
    {
        $slot = $this->slotOf($commands);
        self::ok(vkEndCommandBuffer($commands), 'vkEndCommandBuffer');
        $submit = new VkSubmitInfo;
        $submit->commandBufferCount = 1;
        $submit->pCommandBuffers = [$commands];
        // a frame on its way to a window: its transfers wait for the swapchain's image, and showing waits for them
        if (! is_null($wait)) {
            $submit->waitSemaphoreCount = 1;
            $submit->pWaitSemaphores = [$wait];
            $submit->pWaitDstStageMask = [VK_PIPELINE_STAGE_TRANSFER_BIT];
        }
        if (! is_null($signal)) {
            $submit->signalSemaphoreCount = 1;
            $submit->pSignalSemaphores = [$signal];
        }
        self::ok(vkQueueSubmit($this->queue, 1, [$submit], $this->slots[$slot]['fence']), 'vkQueueSubmit');
        $this->slots[$slot]['state'] = self::SUBMITTED;
        $this->submitted[] = $slot;
    }

    /**
     * Waits until the GPU has finished everything submitted. Called before the CPU reads an
     * image's bytes or writes one a submission may still be using; nothing else waits.
     *
     * @internal
     *
     * @throws VulkanException
     */
    public function sync(): void
    {
        while ($this->submitted !== []) {
            $this->finish(array_shift($this->submitted));
        }
    }

    /**
     * Runs $release once the GPU has finished $commands: what they use must live until then.
     *
     * @internal
     */
    public function after(VkCommandBuffer $commands, Closure $release): void
    {
        $this->slots[$this->slotOf($commands)]['after'][] = $release;
    }

    /** @throws VulkanException */
    protected function finish(int $slot): void
    {
        self::ok(vkWaitForFences($this->device, 1, [$this->slots[$slot]['fence']], true, PHP_INT_MAX), 'vkWaitForFences');
        self::ok(vkResetFences($this->device, 1, [$this->slots[$slot]['fence']]), 'vkResetFences');
        [$after, $this->slots[$slot]['after']] = [$this->slots[$slot]['after'], []];
        $this->slots[$slot]['state'] = self::FREE;
        foreach ($after as $release) {
            $release();
        }
    }

    protected function slotOf(VkCommandBuffer $commands): int
    {
        foreach ($this->slots as $index => $entry) {
            if ($entry['commands'] === $commands) {
                return $index;
            }
        }

        throw VulkanException::because('a command buffer that is not the engine\'s.');
    }

    // What the device makes

    /** @internal */
    public function instance(): VkInstance
    {
        return $this->instance;
    }

    /** @internal */
    public function physical(): VkPhysicalDevice
    {
        return $this->physical;
    }

    /** @internal */
    public function queue(): VkQueue
    {
        return $this->queue;
    }

    /** @internal the queue family everything is submitted on */
    public function family(): int
    {
        return $this->family;
    }

    /** @internal */
    public function device(): VkDevice
    {
        return $this->device;
    }

    /** @internal */
    public function stencilFormat(): int
    {
        return $this->stencilFormat;
    }

    /** @internal */
    public function layout(): VkPipelineLayout
    {
        return $this->layout;
    }

    /**
     * An image and its memory. A transient one only lives inside a render pass (a stencil; a
     * layer's four-sample colour): its memory is lazily allocated where the GPU has that, so it
     * may take none.
     *
     * @internal
     *
     * @return array{VkImage, VkDeviceMemory}
     *
     * @throws VulkanException
     */
    public function image(int $format, int $width, int $height, int $samples, int $usage, bool $transient = false): array
    {
        $info = new VkImageCreateInfo;
        $info->imageType = VK_IMAGE_TYPE_2D;
        $info->format = $format;
        $info->extent->width = $width;
        $info->extent->height = $height;
        $info->extent->depth = 1;
        $info->mipLevels = 1;
        $info->arrayLayers = 1;
        $info->samples = $samples;
        $info->tiling = VK_IMAGE_TILING_OPTIMAL;
        $info->usage = $usage | ($transient ? VK_IMAGE_USAGE_TRANSIENT_ATTACHMENT_BIT : 0);
        self::ok(vkCreateImage($this->device, $info, 0, $image), "vkCreateImage ({$width}x{$height})");
        try {
            vkGetImageMemoryRequirements($this->device, $image, $requirements);
            $memory = $this->allocate($requirements, 0, $transient ? VK_MEMORY_PROPERTY_LAZILY_ALLOCATED_BIT : VK_MEMORY_PROPERTY_DEVICE_LOCAL_BIT);
            self::ok(vkBindImageMemory($this->device, $image, $memory, 0), 'vkBindImageMemory');
        } catch (Throwable $failure) {
            vkDestroyImage($this->device, $image, 0);

            throw $failure;
        }

        return [$image, $memory];
    }

    /** @internal @throws VulkanException */
    public function view(VkImage $image, int $format, int $aspect): VkImageView
    {
        $info = new VkImageViewCreateInfo;
        $info->image = $image;
        $info->viewType = VK_IMAGE_VIEW_TYPE_2D;
        $info->format = $format;
        $info->subresourceRange->aspectMask = $aspect;
        $info->subresourceRange->levelCount = 1;
        $info->subresourceRange->layerCount = 1;
        self::ok(vkCreateImageView($this->device, $info, 0, $view), 'vkCreateImageView');

        return $view;
    }

    /**
     * $bytes as a vertex buffer for $commands. The bytes are uploaded by the command buffer itself
     * (vkCmdUpdateBuffer), the way ext-vulkan moves host data into a buffer. The buffer goes back
     * to a pool once the GPU has finished $commands. Call it before a render pass begins.
     *
     * @internal
     *
     * @throws VulkanException
     */
    public function vertices(VkCommandBuffer $commands, string $bytes): VkBuffer
    {
        return $this->staged($commands, $bytes, VK_BUFFER_USAGE_VERTEX_BUFFER_BIT, VK_ACCESS_VERTEX_ATTRIBUTE_READ_BIT, VK_PIPELINE_STAGE_VERTEX_INPUT_BIT);
    }

    /** @internal */
    public function raycastLayout(): VkPipelineLayout
    {
        return $this->raycastLayout;
    }

    /** @internal what a compute pipeline binds: a storage set (0), a sampler set (1), a storage image set (2) */
    public function computeLayout(): VkPipelineLayout
    {
        return $this->computeLayout;
    }

    /**
     * The compute pipeline of resources/shaders/engine/{$shader}.comp.spv, made once.
     *
     * @internal
     *
     * @throws VulkanException
     */
    public function computePipeline(string $shader): VkPipeline
    {
        $key = "compute.{$shader}";
        if (isset($this->pipelines[$key])) {
            return $this->pipelines[$key];
        }
        $info = new VkComputePipelineCreateInfo;
        $info->stage->stage = VK_SHADER_STAGE_COMPUTE_BIT;
        $info->stage->module = $this->module("{$shader}.comp");
        $info->stage->pName = 'main';
        $info->layout = $this->computeLayout;
        $info->basePipelineIndex = -1;
        self::ok(vkCreateComputePipelines($this->device, 0, 1, [$info], 0, $pipelines), "vkCreateComputePipelines ({$shader})");

        return $this->pipelines[$key] = $pipelines[0];
    }

    /**
     * A set that points binding 0 at $view as a storage image in the GENERAL layout, and its pool.
     *
     * @internal
     *
     * @return array{VkDescriptorPool, VkDescriptorSet}
     *
     * @throws VulkanException
     */
    public function imageSet(VkImageView $view): array
    {
        $size = new VkDescriptorPoolSize;
        $size->type = VK_DESCRIPTOR_TYPE_STORAGE_IMAGE;
        $size->descriptorCount = 1;
        $info = new VkDescriptorPoolCreateInfo;
        $info->maxSets = 1;
        $info->poolSizeCount = 1;
        $info->pPoolSizes = [$size];
        self::ok(vkCreateDescriptorPool($this->device, $info, 0, $pool), 'vkCreateDescriptorPool');
        $allocate = new VkDescriptorSetAllocateInfo;
        $allocate->descriptorPool = $pool;
        $allocate->descriptorSetCount = 1;
        $allocate->pSetLayouts = [$this->imageLayout];
        self::ok(vkAllocateDescriptorSets($this->device, $allocate, $sets), 'vkAllocateDescriptorSets');

        $image = new VkDescriptorImageInfo;
        $image->imageView = $view;
        $image->imageLayout = VK_IMAGE_LAYOUT_GENERAL;
        $write = new VkWriteDescriptorSet;
        $write->dstSet = $sets[0];
        $write->dstBinding = 0;
        $write->descriptorType = VK_DESCRIPTOR_TYPE_STORAGE_IMAGE;
        $write->descriptorCount = 1;
        $write->pImageInfo = [$image];
        vkUpdateDescriptorSets($this->device, 1, [$write], 0, []);

        return [$pool, $sets[0]];
    }

    /** @internal the GPU's largest image side */
    public function maxSide(): int
    {
        return $this->maxSide;
    }

    /** @internal the most bytes a storage buffer binding may span */
    public function maxStorage(): int
    {
        return $this->maxStorage;
    }

    /**
     * $bytes as a storage buffer that is kept: written once by $commands, readable by the fragment stage from then on.
     * The caller destroys it (forget()) once nothing submitted uses it. Call it before a render pass begins.
     *
     * @internal
     *
     * @return array{VkBuffer, VkDeviceMemory}
     *
     * @throws VulkanException
     */
    public function kept(VkCommandBuffer $commands, string $bytes): array
    {
        $bytes = self::words($bytes);
        $info = new VkBufferCreateInfo;
        $info->size = strlen($bytes);
        $info->usage = VK_BUFFER_USAGE_STORAGE_BUFFER_BIT | VK_BUFFER_USAGE_TRANSFER_DST_BIT;
        self::ok(vkCreateBuffer($this->device, $info, 0, $buffer), 'vkCreateBuffer');
        try {
            vkGetBufferMemoryRequirements($this->device, $buffer, $requirements);
            $memory = $this->allocate($requirements, 0, VK_MEMORY_PROPERTY_DEVICE_LOCAL_BIT);
            self::ok(vkBindBufferMemory($this->device, $buffer, $memory, 0), 'vkBindBufferMemory');
        } catch (Throwable $failure) {
            vkDestroyBuffer($this->device, $buffer, 0);

            throw $failure;
        }

        for ($at = 0; $at < strlen($bytes); $at += 65536) {
            $chunk = substr($bytes, $at, 65536);
            vkCmdUpdateBuffer($commands, $buffer, $at, strlen($chunk), $chunk);
        }
        $barrier = new VkBufferMemoryBarrier;
        $barrier->srcAccessMask = VK_ACCESS_TRANSFER_WRITE_BIT;
        $barrier->dstAccessMask = VK_ACCESS_SHADER_READ_BIT;
        $barrier->srcQueueFamilyIndex = VK_QUEUE_FAMILY_IGNORED;
        $barrier->dstQueueFamilyIndex = VK_QUEUE_FAMILY_IGNORED;
        $barrier->buffer = $buffer;
        $barrier->offset = 0;
        $barrier->size = strlen($bytes);
        vkCmdPipelineBarrier($commands, VK_PIPELINE_STAGE_TRANSFER_BIT, VK_PIPELINE_STAGE_FRAGMENT_SHADER_BIT, 0, 0, [], 1, [$barrier], 0, []);

        return [$buffer, $memory];
    }

    /** @internal Destroys a kept() buffer: only once nothing submitted uses it. */
    public function forget(array $kept): void
    {
        if ($this->isOpen()) {
            vkDestroyBuffer($this->device, $kept[0], 0);
            vkFreeMemory($this->device, $kept[1], 0);
        }
    }

    /**
     * A storage set, its three bindings at $buffers, in order, and the pool it came from. It is kept: the caller
     * destroys the pool once nothing submitted uses the set.
     *
     * @internal
     *
     * @param array{VkBuffer, VkBuffer, VkBuffer} $buffers
     * @return array{VkDescriptorPool, VkDescriptorSet}
     *
     * @throws VulkanException
     */
    public function storageSet(array $buffers): array
    {
        $size = new VkDescriptorPoolSize;
        $size->type = VK_DESCRIPTOR_TYPE_STORAGE_BUFFER;
        $size->descriptorCount = 3;
        $info = new VkDescriptorPoolCreateInfo;
        $info->maxSets = 1;
        $info->poolSizeCount = 1;
        $info->pPoolSizes = [$size];
        self::ok(vkCreateDescriptorPool($this->device, $info, 0, $pool), 'vkCreateDescriptorPool');
        $allocate = new VkDescriptorSetAllocateInfo;
        $allocate->descriptorPool = $pool;
        $allocate->descriptorSetCount = 1;
        $allocate->pSetLayouts = [$this->storageLayout];
        self::ok(vkAllocateDescriptorSets($this->device, $allocate, $sets), 'vkAllocateDescriptorSets');

        $writes = [];
        foreach (array_values($buffers) as $binding => $buffer) {
            $range = new VkDescriptorBufferInfo;
            $range->buffer = $buffer;
            $range->offset = 0;
            $range->range = VK_WHOLE_SIZE;
            $write = new VkWriteDescriptorSet;
            $write->dstSet = $sets[0];
            $write->dstBinding = $binding;
            $write->descriptorType = VK_DESCRIPTOR_TYPE_STORAGE_BUFFER;
            $write->descriptorCount = 1;
            $write->pBufferInfo = [$range];
            $writes[] = $write;
        }
        vkUpdateDescriptorSets($this->device, count($writes), $writes, 0, []);

        return [$pool, $sets[0]];
    }

    /** $bytes padded with zeros to whole words, at least one: buffers are written and bound a word at a time. */
    protected static function words(string $bytes): string
    {
        return str_pad($bytes, max(4, (strlen($bytes) + 3) & ~3), "\0");
    }

    /**
     * $bytes in a pooled buffer of $usage, written by $commands (vkCmdUpdateBuffer) and made visible to $access at
     * $stage. The buffer goes back to its pool once the GPU has finished $commands.
     *
     * @throws VulkanException
     */
    protected function staged(VkCommandBuffer $commands, string $bytes, int $usage, int $access, int $stage): VkBuffer
    {
        $size = strlen($bytes);
        $class = max(4096, 1 << (int) ceil(log(max(1, $size), 2)));
        $key = "{$usage}.{$class}";
        $entry = empty($this->vertexPool[$key]) ? null : array_pop($this->vertexPool[$key]);
        if (is_null($entry)) {
            $info = new VkBufferCreateInfo;
            $info->size = $class;
            $info->usage = $usage | VK_BUFFER_USAGE_TRANSFER_DST_BIT;
            self::ok(vkCreateBuffer($this->device, $info, 0, $buffer), 'vkCreateBuffer');
            try {
                vkGetBufferMemoryRequirements($this->device, $buffer, $requirements);
                $memory = $this->allocate($requirements, 0, VK_MEMORY_PROPERTY_DEVICE_LOCAL_BIT);
                self::ok(vkBindBufferMemory($this->device, $buffer, $memory, 0), 'vkBindBufferMemory');
            } catch (Throwable $failure) {
                vkDestroyBuffer($this->device, $buffer, 0);

                throw $failure;
            }
            $entry = [$buffer, $memory];
        }

        // vkCmdUpdateBuffer takes at most 65536 bytes a call, a multiple of 4
        for ($at = 0; $at < $size; $at += 65536) {
            $chunk = substr($bytes, $at, 65536);
            vkCmdUpdateBuffer($commands, $entry[0], $at, strlen($chunk), $chunk);
        }
        $barrier = new VkBufferMemoryBarrier;
        $barrier->srcAccessMask = VK_ACCESS_TRANSFER_WRITE_BIT;
        $barrier->dstAccessMask = $access;
        $barrier->srcQueueFamilyIndex = VK_QUEUE_FAMILY_IGNORED;
        $barrier->dstQueueFamilyIndex = VK_QUEUE_FAMILY_IGNORED;
        $barrier->buffer = $entry[0];
        $barrier->offset = 0;
        $barrier->size = $class;
        vkCmdPipelineBarrier($commands, VK_PIPELINE_STAGE_TRANSFER_BIT, $stage, 0, 0, [], 1, [$barrier], 0, []);

        $this->after($commands, function () use ($key, $entry): void {
            $this->vertexPool[$key][] = $entry;
        });

        return $entry[0];
    }

    /**
     * $bytes into $image, an R32_UINT image a row of 1024 words wide, by the command buffer itself: the bytes go
     * into a pooled buffer with vkCmdUpdateBuffer, then the buffer into the image with vkCmdCopyBufferToImage.
     * Nothing waits; whatever $commands draws after it reads the image written. The image's rows hold at least
     * $bytes; what is past them is zero. Call it before a render pass begins.
     *
     * @internal
     *
     * @throws VulkanException
     */
    public function data(VkCommandBuffer $commands, string $bytes, VkImage $image, int $rows): void
    {
        $bytes = str_pad($bytes, $rows * 4096, "\0");
        $buffer = $this->staged($commands, $bytes, VK_BUFFER_USAGE_TRANSFER_SRC_BIT, VK_ACCESS_TRANSFER_READ_BIT, VK_PIPELINE_STAGE_TRANSFER_BIT);

        // the image's old contents are not needed: from UNDEFINED, whole, to GENERAL
        $barrier = new VkImageMemoryBarrier;
        $barrier->srcAccessMask = 0;
        $barrier->dstAccessMask = VK_ACCESS_TRANSFER_WRITE_BIT;
        $barrier->oldLayout = VK_IMAGE_LAYOUT_UNDEFINED;
        $barrier->newLayout = VK_IMAGE_LAYOUT_GENERAL;
        $barrier->srcQueueFamilyIndex = VK_QUEUE_FAMILY_IGNORED;
        $barrier->dstQueueFamilyIndex = VK_QUEUE_FAMILY_IGNORED;
        $barrier->image = $image;
        $barrier->subresourceRange->aspectMask = VK_IMAGE_ASPECT_COLOR_BIT;
        $barrier->subresourceRange->levelCount = 1;
        $barrier->subresourceRange->layerCount = 1;
        vkCmdPipelineBarrier($commands, VK_PIPELINE_STAGE_TOP_OF_PIPE_BIT, VK_PIPELINE_STAGE_TRANSFER_BIT, 0, 0, [], 0, [], 1, [$barrier]);

        $copy = new VkBufferImageCopy;
        $copy->bufferOffset = 0;
        $copy->bufferRowLength = 0;
        $copy->bufferImageHeight = 0;
        $copy->imageSubresource->aspectMask = VK_IMAGE_ASPECT_COLOR_BIT;
        $copy->imageSubresource->layerCount = 1;
        [$copy->imageExtent->width, $copy->imageExtent->height, $copy->imageExtent->depth] = [1024, $rows, 1];
        vkCmdCopyBufferToImage($commands, $buffer, $image, VK_IMAGE_LAYOUT_GENERAL, 1, [$copy]);

        // the copy is finished and visible before any fragment reads the image
        $read = new VkImageMemoryBarrier;
        $read->srcAccessMask = VK_ACCESS_TRANSFER_WRITE_BIT;
        $read->dstAccessMask = VK_ACCESS_SHADER_READ_BIT;
        $read->oldLayout = VK_IMAGE_LAYOUT_GENERAL;
        $read->newLayout = VK_IMAGE_LAYOUT_GENERAL;
        $read->srcQueueFamilyIndex = VK_QUEUE_FAMILY_IGNORED;
        $read->dstQueueFamilyIndex = VK_QUEUE_FAMILY_IGNORED;
        $read->image = $image;
        $read->subresourceRange->aspectMask = VK_IMAGE_ASPECT_COLOR_BIT;
        $read->subresourceRange->levelCount = 1;
        $read->subresourceRange->layerCount = 1;
        vkCmdPipelineBarrier($commands, VK_PIPELINE_STAGE_TRANSFER_BIT, VK_PIPELINE_STAGE_FRAGMENT_SHADER_BIT, 0, 0, [], 0, [], 1, [$read]);
    }

    /**
     * A pool of $count descriptor sets, each one combined image sampler, and the sets.
     *
     * @internal
     *
     * @return array{VkDescriptorPool, list<VkDescriptorSet>}
     *
     * @throws VulkanException
     */
    public function samplerSets(int $count): array
    {
        $size = new VkDescriptorPoolSize;
        $size->type = VK_DESCRIPTOR_TYPE_COMBINED_IMAGE_SAMPLER;
        $size->descriptorCount = $count;
        $info = new VkDescriptorPoolCreateInfo;
        $info->maxSets = $count;
        $info->poolSizeCount = 1;
        $info->pPoolSizes = [$size];
        self::ok(vkCreateDescriptorPool($this->device, $info, 0, $pool), 'vkCreateDescriptorPool');

        $allocate = new VkDescriptorSetAllocateInfo;
        $allocate->descriptorPool = $pool;
        $allocate->descriptorSetCount = $count;
        $allocate->pSetLayouts = array_fill(0, $count, $this->setLayout);
        self::ok(vkAllocateDescriptorSets($this->device, $allocate, $sets), 'vkAllocateDescriptorSets');

        return [$pool, $sets];
    }

    /**
     * Points $set at $view: read in the GENERAL layout, nearest, clamped.
     *
     * @internal
     */
    public function point(VkDescriptorSet $set, VkImageView $view): void
    {
        $image = new VkDescriptorImageInfo;
        $image->sampler = $this->sampler;
        $image->imageView = $view;
        $image->imageLayout = VK_IMAGE_LAYOUT_GENERAL;
        $write = new VkWriteDescriptorSet;
        $write->dstSet = $set;
        $write->dstBinding = 0;
        $write->descriptorType = VK_DESCRIPTOR_TYPE_COMBINED_IMAGE_SAMPLER;
        $write->descriptorCount = 1;
        $write->pImageInfo = [$image];
        vkUpdateDescriptorSets($this->device, 1, [$write], 0, []);
    }

    /**
     * The render pass of one shape of attachments, colour loaded as $load says:
     *
     *  - aa: four-sample colour, four-sample stencil, and the single-sample image it resolves into
     *  - samples: four-sample colour alone (filling the twin from the image)
     *  - hard: single-sample colour and stencil
     *  - plain: single-sample colour alone
     *
     * A stencil is cleared to 0 and not kept. Single-sample colour is in the GENERAL layout
     * throughout; four-sample colour in COLOR_ATTACHMENT_OPTIMAL once it has been drawn.
     *
     * @internal
     *
     * @throws VulkanException
     */
    public function pass(string $shape, int $load, int $format = self::COLOR): VkRenderPass
    {
        $key = "{$shape}.{$load}.{$format}";
        if (isset($this->passes[$key])) {
            return $this->passes[$key];
        }

        $attachment = function (int $format, int $samples, int $load, int $store, int $initial, int $final): VkAttachmentDescription {
            $a = new VkAttachmentDescription;
            $a->format = $format;
            $a->samples = $samples;
            $a->loadOp = $load;
            $a->storeOp = $store;
            $a->stencilLoadOp = $load === VK_ATTACHMENT_LOAD_OP_CLEAR ? VK_ATTACHMENT_LOAD_OP_CLEAR : VK_ATTACHMENT_LOAD_OP_DONT_CARE;
            $a->stencilStoreOp = VK_ATTACHMENT_STORE_OP_DONT_CARE;
            $a->initialLayout = $initial;
            $a->finalLayout = $final;

            return $a;
        };
        $reference = function (int $index, int $layout): VkAttachmentReference {
            $r = new VkAttachmentReference;
            $r->attachment = $index;
            $r->layout = $layout;

            return $r;
        };
        $stencil = fn (int $samples): VkAttachmentDescription => $attachment($this->stencilFormat, $samples, VK_ATTACHMENT_LOAD_OP_CLEAR, VK_ATTACHMENT_STORE_OP_DONT_CARE, VK_IMAGE_LAYOUT_UNDEFINED, VK_IMAGE_LAYOUT_DEPTH_STENCIL_ATTACHMENT_OPTIMAL);
        $samples = fn (int $store = VK_ATTACHMENT_STORE_OP_STORE): VkAttachmentDescription => $attachment($format, VK_SAMPLE_COUNT_4_BIT, $load, $store, $load === VK_ATTACHMENT_LOAD_OP_LOAD ? VK_IMAGE_LAYOUT_COLOR_ATTACHMENT_OPTIMAL : VK_IMAGE_LAYOUT_UNDEFINED, VK_IMAGE_LAYOUT_COLOR_ATTACHMENT_OPTIMAL);
        $single = fn (int $load): VkAttachmentDescription => $attachment($format, VK_SAMPLE_COUNT_1_BIT, $load, VK_ATTACHMENT_STORE_OP_STORE, VK_IMAGE_LAYOUT_GENERAL, VK_IMAGE_LAYOUT_GENERAL);

        $subpass = new VkSubpassDescription;
        $subpass->pipelineBindPoint = VK_PIPELINE_BIND_POINT_GRAPHICS;
        $subpass->colorAttachmentCount = 1;
        switch ($shape) {
            case 'aa':
                $attachments = [$samples(), $stencil(VK_SAMPLE_COUNT_4_BIT), $single(VK_ATTACHMENT_LOAD_OP_DONT_CARE)];
                $subpass->pColorAttachments = [$reference(0, VK_IMAGE_LAYOUT_COLOR_ATTACHMENT_OPTIMAL)];
                $subpass->pDepthStencilAttachment = $reference(1, VK_IMAGE_LAYOUT_DEPTH_STENCIL_ATTACHMENT_OPTIMAL);
                $subpass->pResolveAttachments = [$reference(2, VK_IMAGE_LAYOUT_GENERAL)];
                break;
            case 'sheet':
                // a layer's: its samples are cleared, resolved, and never kept, so they may live only inside the pass
                $attachments = [$samples(VK_ATTACHMENT_STORE_OP_DONT_CARE), $stencil(VK_SAMPLE_COUNT_4_BIT), $single(VK_ATTACHMENT_LOAD_OP_DONT_CARE)];
                $subpass->pColorAttachments = [$reference(0, VK_IMAGE_LAYOUT_COLOR_ATTACHMENT_OPTIMAL)];
                $subpass->pDepthStencilAttachment = $reference(1, VK_IMAGE_LAYOUT_DEPTH_STENCIL_ATTACHMENT_OPTIMAL);
                $subpass->pResolveAttachments = [$reference(2, VK_IMAGE_LAYOUT_GENERAL)];
                break;
            case 'samples':
                $attachments = [$samples()];
                $subpass->pColorAttachments = [$reference(0, VK_IMAGE_LAYOUT_COLOR_ATTACHMENT_OPTIMAL)];
                break;
            case 'hard':
                $attachments = [$single($load), $stencil(VK_SAMPLE_COUNT_1_BIT)];
                $subpass->pColorAttachments = [$reference(0, VK_IMAGE_LAYOUT_GENERAL)];
                $subpass->pDepthStencilAttachment = $reference(1, VK_IMAGE_LAYOUT_DEPTH_STENCIL_ATTACHMENT_OPTIMAL);
                break;
            case 'plain':
                $attachments = [$single($load)];
                $subpass->pColorAttachments = [$reference(0, VK_IMAGE_LAYOUT_GENERAL)];
                break;
            default:
                throw VulkanException::because("no render pass of shape '{$shape}'.");
        }

        $info = new VkRenderPassCreateInfo;
        $info->attachmentCount = count($attachments);
        $info->pAttachments = $attachments;
        $info->subpassCount = 1;
        $info->pSubpasses = [$subpass];
        // What came before (a pass, a blit, a host copy) is finished and visible before this pass reads or writes,
        // and this pass's writes are finished and visible to whatever comes after: without these, a pass that
        // samples a framebuffer may read it before the pass that drew it has written it.
        $stages = VK_PIPELINE_STAGE_COLOR_ATTACHMENT_OUTPUT_BIT | VK_PIPELINE_STAGE_EARLY_FRAGMENT_TESTS_BIT | VK_PIPELINE_STAGE_LATE_FRAGMENT_TESTS_BIT | VK_PIPELINE_STAGE_FRAGMENT_SHADER_BIT;
        $access = VK_ACCESS_COLOR_ATTACHMENT_READ_BIT | VK_ACCESS_COLOR_ATTACHMENT_WRITE_BIT | VK_ACCESS_DEPTH_STENCIL_ATTACHMENT_READ_BIT | VK_ACCESS_DEPTH_STENCIL_ATTACHMENT_WRITE_BIT | VK_ACCESS_SHADER_READ_BIT;
        $in = new VkSubpassDependency;
        [$in->srcSubpass, $in->dstSubpass] = [VK_SUBPASS_EXTERNAL, 0];
        [$in->srcStageMask, $in->dstStageMask] = [VK_PIPELINE_STAGE_ALL_COMMANDS_BIT, $stages];
        [$in->srcAccessMask, $in->dstAccessMask] = [VK_ACCESS_MEMORY_WRITE_BIT, $access];
        $out = new VkSubpassDependency;
        [$out->srcSubpass, $out->dstSubpass] = [0, VK_SUBPASS_EXTERNAL];
        [$out->srcStageMask, $out->dstStageMask] = [$stages, VK_PIPELINE_STAGE_ALL_COMMANDS_BIT];
        [$out->srcAccessMask, $out->dstAccessMask] = [VK_ACCESS_COLOR_ATTACHMENT_WRITE_BIT | VK_ACCESS_DEPTH_STENCIL_ATTACHMENT_WRITE_BIT, VK_ACCESS_MEMORY_READ_BIT | VK_ACCESS_MEMORY_WRITE_BIT];
        $info->dependencyCount = 2;
        $info->pDependencies = [$in, $out];
        self::ok(vkCreateRenderPass($this->device, $info, 0, $pass), 'vkCreateRenderPass');

        return $this->passes[$key] = $pass;
    }

    /**
     * The pipeline of one vertex and fragment shader for a pass of $shape.
     *
     * $vertex is 'place' (points in surface pixels, one vec2 a vertex) or 'cover' (one triangle
     * over the viewport, no vertices). With $blend the fragment's premultiplied colour goes over
     * what is there (source + destination x (1 - source alpha)); without, it replaces it.
     *
     * $stencil: null, the stencil untouched. winding: front faces count up, back faces down
     * (non-zero), and no colour is written. invert: every face flips it (even-odd), no colour.
     * cover: draw where it is not zero, and zero it.
     *
     * @internal
     *
     * @throws VulkanException
     */
    public function pipeline(string $vertex, string $fragment, string $shape, bool $blend = true, ?string $stencil = null, int $format = self::COLOR, bool $raycast = false): VkPipeline
    {
        $key = "{$vertex}.{$fragment}.{$shape}.".(int) $blend.'.'.($stencil ?? 'none').".{$format}.".(int) $raycast;
        if (isset($this->pipelines[$key])) {
            return $this->pipelines[$key];
        }

        $stage = function (int $bit, VkShaderModule $module): VkPipelineShaderStageCreateInfo {
            $s = new VkPipelineShaderStageCreateInfo;
            $s->stage = $bit;
            $s->module = $module;
            $s->pName = 'main';

            return $s;
        };

        $vertexInput = new VkPipelineVertexInputStateCreateInfo;
        if ($vertex === 'place') {
            $binding = new VkVertexInputBindingDescription;
            $binding->binding = 0;
            $binding->stride = 8;
            $binding->inputRate = VK_VERTEX_INPUT_RATE_VERTEX;
            $attribute = new VkVertexInputAttributeDescription;
            $attribute->location = 0;
            $attribute->binding = 0;
            $attribute->format = VK_FORMAT_R32G32_SFLOAT;
            $vertexInput->vertexBindingDescriptionCount = 1;
            $vertexInput->pVertexBindingDescriptions = [$binding];
            $vertexInput->vertexAttributeDescriptionCount = 1;
            $vertexInput->pVertexAttributeDescriptions = [$attribute];
        }
        $assembly = new VkPipelineInputAssemblyStateCreateInfo;
        $assembly->topology = VK_PRIMITIVE_TOPOLOGY_TRIANGLE_LIST;
        $viewport = new VkPipelineViewportStateCreateInfo;
        $viewport->viewportCount = 1;
        $viewport->scissorCount = 1;
        $raster = new VkPipelineRasterizationStateCreateInfo;
        $raster->polygonMode = VK_POLYGON_MODE_FILL;
        $raster->cullMode = VK_CULL_MODE_NONE;
        $raster->frontFace = VK_FRONT_FACE_COUNTER_CLOCKWISE;
        $raster->lineWidth = 1.0;
        $multisample = new VkPipelineMultisampleStateCreateInfo;
        $multisample->rasterizationSamples = in_array($shape, ['aa', 'samples'], true) ? VK_SAMPLE_COUNT_4_BIT : VK_SAMPLE_COUNT_1_BIT;

        $depthStencil = new VkPipelineDepthStencilStateCreateInfo;
        $depthStencil->stencilTestEnable = ! is_null($stencil);
        if (! is_null($stencil)) {
            [$front, $back, $compare] = match ($stencil) {
                'winding' => [VK_STENCIL_OP_INCREMENT_AND_WRAP, VK_STENCIL_OP_DECREMENT_AND_WRAP, VK_COMPARE_OP_ALWAYS],
                'invert' => [VK_STENCIL_OP_INVERT, VK_STENCIL_OP_INVERT, VK_COMPARE_OP_ALWAYS],
                'cover' => [VK_STENCIL_OP_ZERO, VK_STENCIL_OP_ZERO, VK_COMPARE_OP_NOT_EQUAL],
            };
            foreach ([[$depthStencil->front, $front], [$depthStencil->back, $back]] as [$face, $pass]) {
                $face->failOp = VK_STENCIL_OP_KEEP;
                $face->passOp = $pass;
                $face->depthFailOp = VK_STENCIL_OP_KEEP;
                $face->compareOp = $compare;
                $face->compareMask = 0xFF;
                $face->writeMask = 0xFF;
                $face->reference = 0;
            }
        }

        $attachment = new VkPipelineColorBlendAttachmentState;
        $marks = in_array($stencil, ['winding', 'invert'], true);
        $attachment->colorWriteMask = $marks ? 0 : VK_COLOR_COMPONENT_R_BIT | VK_COLOR_COMPONENT_G_BIT | VK_COLOR_COMPONENT_B_BIT | VK_COLOR_COMPONENT_A_BIT;
        if ($blend && ! $marks) {
            $attachment->blendEnable = true;
            $attachment->srcColorBlendFactor = VK_BLEND_FACTOR_ONE;
            $attachment->dstColorBlendFactor = VK_BLEND_FACTOR_ONE_MINUS_SRC_ALPHA;
            $attachment->colorBlendOp = VK_BLEND_OP_ADD;
            $attachment->srcAlphaBlendFactor = VK_BLEND_FACTOR_ONE;
            $attachment->dstAlphaBlendFactor = VK_BLEND_FACTOR_ONE_MINUS_SRC_ALPHA;
            $attachment->alphaBlendOp = VK_BLEND_OP_ADD;
        }
        $blending = new VkPipelineColorBlendStateCreateInfo;
        $blending->attachmentCount = 1;
        $blending->pAttachments = [$attachment];
        $dynamic = new VkPipelineDynamicStateCreateInfo;
        $dynamic->dynamicStateCount = 2;
        $dynamic->pDynamicStates = [VK_DYNAMIC_STATE_VIEWPORT, VK_DYNAMIC_STATE_SCISSOR];

        $info = new VkGraphicsPipelineCreateInfo;
        $info->stageCount = 2;
        $info->pStages = [$stage(VK_SHADER_STAGE_VERTEX_BIT, $this->module("{$vertex}.vert")), $stage(VK_SHADER_STAGE_FRAGMENT_BIT, $this->module("{$fragment}.frag"))];
        $info->pVertexInputState = $vertexInput;
        $info->pInputAssemblyState = $assembly;
        $info->pViewportState = $viewport;
        $info->pRasterizationState = $raster;
        $info->pMultisampleState = $multisample;
        $info->pDepthStencilState = $depthStencil;
        $info->pColorBlendState = $blending;
        $info->pDynamicState = $dynamic;
        $info->layout = $raycast ? $this->raycastLayout : $this->layout;
        $info->renderPass = $this->pass($shape, VK_ATTACHMENT_LOAD_OP_LOAD, $format);
        self::ok(vkCreateGraphicsPipelines($this->device, 0, 1, [$info], 0, $pipelines), "vkCreateGraphicsPipelines ({$vertex}, {$fragment})");

        return $this->pipelines[$key] = $pipelines[0];
    }

    // Bytes to and from images, by host image copy. The image is in the GENERAL layout and the GPU is not using it.

    /** @internal @throws VulkanException */
    public function hostGeneral(VkImage $image): void
    {
        $transition = new VkHostImageLayoutTransitionInfo;
        $transition->image = $image;
        $transition->oldLayout = VK_IMAGE_LAYOUT_UNDEFINED;
        $transition->newLayout = VK_IMAGE_LAYOUT_GENERAL;
        $transition->subresourceRange->aspectMask = VK_IMAGE_ASPECT_COLOR_BIT;
        $transition->subresourceRange->levelCount = 1;
        $transition->subresourceRange->layerCount = 1;
        self::ok(vkTransitionImageLayout($this->device, 1, [$transition]), 'vkTransitionImageLayout');
    }

    /** @internal @throws VulkanException */
    public function hostWrite(VkImage $image, string $texels, Region $region): void
    {
        $copy = new VkMemoryToImageCopy;
        $copy->pHostPointer = $texels;
        $copy->imageSubresource->aspectMask = VK_IMAGE_ASPECT_COLOR_BIT;
        $copy->imageSubresource->layerCount = 1;
        [$copy->imageOffset->x, $copy->imageOffset->y] = [$region->x, $region->y];
        [$copy->imageExtent->width, $copy->imageExtent->height, $copy->imageExtent->depth] = [$region->width, $region->height, 1];
        $info = new VkCopyMemoryToImageInfo;
        $info->dstImage = $image;
        $info->dstImageLayout = VK_IMAGE_LAYOUT_GENERAL;
        $info->regionCount = 1;
        $info->pRegions = [$copy];
        self::ok(vkCopyMemoryToImage($this->device, $info), 'vkCopyMemoryToImage');
    }

    /** @internal @throws VulkanException */
    public function hostRead(VkImage $image, Region $region, int $bytesPerPixel = 4): string
    {
        $copy = new VkImageToMemoryCopy;
        $copy->pHostPointer = str_repeat("\0", $region->width * $region->height * $bytesPerPixel);
        $copy->imageSubresource->aspectMask = VK_IMAGE_ASPECT_COLOR_BIT;
        $copy->imageSubresource->layerCount = 1;
        [$copy->imageOffset->x, $copy->imageOffset->y] = [$region->x, $region->y];
        [$copy->imageExtent->width, $copy->imageExtent->height, $copy->imageExtent->depth] = [$region->width, $region->height, 1];
        $info = new VkCopyImageToMemoryInfo;
        $info->srcImage = $image;
        $info->srcImageLayout = VK_IMAGE_LAYOUT_GENERAL;
        $info->regionCount = 1;
        $info->pRegions = [$copy];
        self::ok(vkCopyImageToMemory($this->device, $info), 'vkCopyImageToMemory');

        return $copy->pHostPointer;
    }

    /** @internal @throws VulkanException */
    public static function ok(int $result, string $call): int
    {
        if ($result !== VK_SUCCESS) {
            throw VulkanException::because("{$call} failed with VkResult {$result}.");
        }

        return $result;
    }

    /** @throws VulkanException */
    protected function module(string $name): VkShaderModule
    {
        if (isset($this->modules[$name])) {
            return $this->modules[$name];
        }
        $info = new VkShaderModuleCreateInfo;
        $info->pCode = @file_get_contents(self::SHADERS."/{$name}.spv") ?: throw VulkanException::because("the shader {$name}.spv could not be read.");
        $info->codeSize = strlen($info->pCode);
        self::ok(vkCreateShaderModule($this->device, $info, 0, $module), "vkCreateShaderModule ({$name})");

        return $this->modules[$name] = $module;
    }

    /** @throws VulkanException */
    protected function allocate(VkMemoryRequirements $requirements, int $required, int $preferred = 0): VkDeviceMemory
    {
        $type = null;
        foreach ($this->memoryTypes as $index => $candidate) {
            if (($requirements->memoryTypeBits & (1 << $index)) === 0 || ($candidate->propertyFlags & $required) !== $required) {
                continue;
            }
            if (($candidate->propertyFlags & $preferred) === $preferred) {
                $type = $index;
                break;
            }
            $type ??= $index;
        }
        if (is_null($type)) {
            throw VulkanException::because('no memory type fits the request.');
        }

        $allocate = new VkMemoryAllocateInfo;
        $allocate->allocationSize = $requirements->size;
        $allocate->memoryTypeIndex = $type;
        self::ok(vkAllocateMemory($this->device, $allocate, 0, $memory), 'vkAllocateMemory');

        return $memory;
    }

    /** @throws VulkanException */
    protected function open(): void
    {
        if (! function_exists('vkCreateInstance')) {
            throw VulkanException::because('ext-vulkan is not loaded.');
        }

        vkEnumerateInstanceExtensionProperties(null, $count, null);
        vkEnumerateInstanceExtensionProperties(null, $count, $available);
        $listed = array_map(fn (VkExtensionProperties $e): string => $e->extensionName, $available ?? []);
        $wanted = [VK_KHR_SURFACE_EXTENSION_NAME, VK_KHR_GET_PHYSICAL_DEVICE_PROPERTIES_2_EXTENSION_NAME, PHP_OS_FAMILY === 'Darwin' ? 'VK_EXT_metal_surface' : null];
        $application = new VkApplicationInfo;
        $application->pApplicationName = 'venusian-vulkan';
        $application->apiVersion = VK_API_VERSION_1_4;
        $info = new VkInstanceCreateInfo;
        $info->pApplicationInfo = $application;
        $enabled = array_values(array_filter($wanted, fn (?string $name): bool => ! is_null($name) && in_array($name, $listed, true)));
        // MoltenVK is a portability driver: the loader lists it only when asked to
        if (in_array(VK_KHR_PORTABILITY_ENUMERATION_EXTENSION_NAME, $listed, true)) {
            $enabled[] = VK_KHR_PORTABILITY_ENUMERATION_EXTENSION_NAME;
            $info->flags |= VK_INSTANCE_CREATE_ENUMERATE_PORTABILITY_BIT_KHR;
        }
        $info->ppEnabledExtensionNames = $enabled;
        $info->enabledExtensionCount = count($enabled);
        self::ok(vkCreateInstance($info, 0, $instance), 'vkCreateInstance');
        $this->instance = $instance;
        $this->surfaces = new WeakMap;
        $this->presents = in_array(VK_KHR_SURFACE_EXTENSION_NAME, $enabled, true) && in_array('VK_EXT_metal_surface', $enabled, true);

        self::ok(vkEnumeratePhysicalDevices($this->instance, $count, null), 'vkEnumeratePhysicalDevices');
        self::ok(vkEnumeratePhysicalDevices($this->instance, $count, $devices), 'vkEnumeratePhysicalDevices');
        $properties = null;
        foreach ($devices ?? [] as $candidate) {
            vkGetPhysicalDeviceProperties($candidate, $properties);
            if ($properties->deviceType !== VK_PHYSICAL_DEVICE_TYPE_CPU) {
                $this->physical = $candidate;
                break;
            }
        }
        if (! isset($this->physical)) {
            throw VulkanException::because('no GPU device (only a CPU device, or none).');
        }
        $this->gpu = $properties->deviceName;
        $limits = $properties->limits;
        if ($limits->maxPushConstantsSize < self::PUSH_FRAME + self::PUSH_PAINT) {
            throw VulkanException::because("{$this->gpu} allows {$limits->maxPushConstantsSize} bytes of push constants; the engine needs ".(self::PUSH_FRAME + self::PUSH_PAINT).'.');
        }
        if (($limits->framebufferColorSampleCounts & VK_SAMPLE_COUNT_4_BIT) === 0 || ($limits->framebufferStencilSampleCounts & VK_SAMPLE_COUNT_4_BIT) === 0) {
            throw VulkanException::because("{$this->gpu} has no four-sample colour and stencil attachments.");
        }
        $this->maxSide = $limits->maxImageDimension2D;
        $this->maxStorage = $limits->maxStorageBufferRange;
        vkGetPhysicalDeviceMemoryProperties($this->physical, $memory);
        $this->memoryTypes = array_values($memory->memoryTypes);

        vkGetPhysicalDeviceQueueFamilyProperties($this->physical, $count, null);
        vkGetPhysicalDeviceQueueFamilyProperties($this->physical, $count, $families);
        foreach ($families ?? [] as $index => $family) {
            if (($family->queueFlags & VK_QUEUE_GRAPHICS_BIT) !== 0) {
                $this->family = $index;
                break;
            }
        }
        if (! isset($this->family)) {
            throw VulkanException::because("{$this->gpu} has no graphics queue.");
        }

        vkEnumerateDeviceExtensionProperties($this->physical, null, $count, null);
        vkEnumerateDeviceExtensionProperties($this->physical, null, $count, $available);
        $listed = array_map(fn (VkExtensionProperties $e): string => $e->extensionName, $available ?? []);
        $extensions = array_values(array_filter([VK_KHR_SWAPCHAIN_EXTENSION_NAME, VK_KHR_PORTABILITY_SUBSET_EXTENSION_NAME], fn (string $name): bool => in_array($name, $listed, true)));
        $this->presents = $this->presents && in_array(VK_KHR_SWAPCHAIN_EXTENSION_NAME, $extensions, true);

        $hostCopy = new VkPhysicalDeviceHostImageCopyFeatures;
        $probe = new VkPhysicalDeviceFeatures2;
        $probe->pNext = $hostCopy;
        vkGetPhysicalDeviceFeatures2($this->physical, $probe);
        if ($properties->apiVersion < VK_API_VERSION_1_4 || ! $hostCopy->hostImageCopy) {
            throw VulkanException::because("{$this->gpu} needs Vulkan 1.4 with the hostImageCopy feature: bytes go to and from images by host image copy.");
        }
        if (! $probe->features->sampleRateShading) {
            throw VulkanException::because("{$this->gpu} lacks sampleRateShading: curved edges are worked out once a sample.");
        }
        $enableHost = new VkPhysicalDeviceHostImageCopyFeatures;
        $enableHost->hostImageCopy = true;
        $enabled = new VkPhysicalDeviceFeatures;
        $enabled->sampleRateShading = true;
        $queueInfo = new VkDeviceQueueCreateInfo;
        $queueInfo->queueFamilyIndex = $this->family;
        $queueInfo->queueCount = 1;
        $queueInfo->pQueuePriorities = [1.0];
        $create = new VkDeviceCreateInfo;
        $create->pNext = $enableHost;
        $create->queueCreateInfoCount = 1;
        $create->pQueueCreateInfos = [$queueInfo];
        $create->pEnabledFeatures = $enabled;
        $create->ppEnabledExtensionNames = $extensions;
        $create->enabledExtensionCount = count($extensions);
        self::ok(vkCreateDevice($this->physical, $create, 0, $device), 'vkCreateDevice');
        $this->device = $device;
        vkGetDeviceQueue($this->device, $this->family, 0, $queue);
        $this->queue = $queue;

        foreach ([VK_FORMAT_S8_UINT, VK_FORMAT_D24_UNORM_S8_UINT, VK_FORMAT_D32_SFLOAT_S8_UINT] as $format) {
            vkGetPhysicalDeviceFormatProperties($this->physical, $format, $formatProperties);
            if (($formatProperties->optimalTilingFeatures & VK_FORMAT_FEATURE_DEPTH_STENCIL_ATTACHMENT_BIT) !== 0) {
                $this->stencilFormat = $format;
                break;
            }
        }
        if ($this->stencilFormat === 0) {
            throw VulkanException::because("{$this->gpu} has no stencil attachment format.");
        }

        $poolInfo = new VkCommandPoolCreateInfo;
        $poolInfo->flags = VK_COMMAND_POOL_CREATE_RESET_COMMAND_BUFFER_BIT;
        $poolInfo->queueFamilyIndex = $this->family;
        self::ok(vkCreateCommandPool($this->device, $poolInfo, 0, $pool), 'vkCreateCommandPool');
        $this->pool = $pool;
        $allocate = new VkCommandBufferAllocateInfo;
        $allocate->commandPool = $this->pool;
        $allocate->level = VK_COMMAND_BUFFER_LEVEL_PRIMARY;
        $allocate->commandBufferCount = self::RING;
        self::ok(vkAllocateCommandBuffers($this->device, $allocate, $buffers), 'vkAllocateCommandBuffers');
        foreach ($buffers as $commands) {
            self::ok(vkCreateFence($this->device, new VkFenceCreateInfo, 0, $fence), 'vkCreateFence');
            $this->slots[] = ['commands' => $commands, 'fence' => $fence, 'state' => self::FREE, 'after' => []];
        }

        $binding = new VkDescriptorSetLayoutBinding;
        $binding->binding = 0;
        $binding->descriptorType = VK_DESCRIPTOR_TYPE_COMBINED_IMAGE_SAMPLER;
        $binding->descriptorCount = 1;
        $binding->stageFlags = VK_SHADER_STAGE_FRAGMENT_BIT | VK_SHADER_STAGE_COMPUTE_BIT;
        $setInfo = new VkDescriptorSetLayoutCreateInfo;
        $setInfo->bindingCount = 1;
        $setInfo->pBindings = [$binding];
        self::ok(vkCreateDescriptorSetLayout($this->device, $setInfo, 0, $setLayout), 'vkCreateDescriptorSetLayout');
        $this->setLayout = $setLayout;

        $frame = new VkPushConstantRange;
        $frame->stageFlags = VK_SHADER_STAGE_VERTEX_BIT;
        $frame->offset = 0;
        $frame->size = self::PUSH_FRAME;
        $paint = new VkPushConstantRange;
        $paint->stageFlags = VK_SHADER_STAGE_FRAGMENT_BIT;
        $paint->offset = self::PUSH_FRAME;
        $paint->size = self::PUSH_PAINT;
        $layoutInfo = new VkPipelineLayoutCreateInfo;
        $layoutInfo->setLayoutCount = 3;
        $layoutInfo->pSetLayouts = [$this->setLayout, $this->setLayout, $this->setLayout];
        $layoutInfo->pushConstantRangeCount = 2;
        $layoutInfo->pPushConstantRanges = [$frame, $paint];
        self::ok(vkCreatePipelineLayout($this->device, $layoutInfo, 0, $layout), 'vkCreatePipelineLayout');
        $this->layout = $layout;

        $storage = [];
        for ($i = 0; $i < 3; $i++) {
            $storage[$i] = new VkDescriptorSetLayoutBinding;
            $storage[$i]->binding = $i;
            $storage[$i]->descriptorType = VK_DESCRIPTOR_TYPE_STORAGE_BUFFER;
            $storage[$i]->descriptorCount = 1;
            $storage[$i]->stageFlags = VK_SHADER_STAGE_FRAGMENT_BIT | VK_SHADER_STAGE_COMPUTE_BIT;
        }
        $storageInfo = new VkDescriptorSetLayoutCreateInfo;
        $storageInfo->bindingCount = 3;
        $storageInfo->pBindings = $storage;
        self::ok(vkCreateDescriptorSetLayout($this->device, $storageInfo, 0, $storageLayout), 'vkCreateDescriptorSetLayout');
        $this->storageLayout = $storageLayout;
        $raycastInfo = new VkPipelineLayoutCreateInfo;
        $raycastInfo->setLayoutCount = 2;
        $raycastInfo->pSetLayouts = [$this->storageLayout, $this->setLayout];
        $raycastInfo->pushConstantRangeCount = 2;
        $raycastInfo->pPushConstantRanges = [$frame, $paint];
        self::ok(vkCreatePipelineLayout($this->device, $raycastInfo, 0, $raycastLayout), 'vkCreatePipelineLayout');
        $this->raycastLayout = $raycastLayout;

        $imageBinding = new VkDescriptorSetLayoutBinding;
        $imageBinding->binding = 0;
        $imageBinding->descriptorType = VK_DESCRIPTOR_TYPE_STORAGE_IMAGE;
        $imageBinding->descriptorCount = 1;
        $imageBinding->stageFlags = VK_SHADER_STAGE_COMPUTE_BIT;
        $imageInfo = new VkDescriptorSetLayoutCreateInfo;
        $imageInfo->bindingCount = 1;
        $imageInfo->pBindings = [$imageBinding];
        self::ok(vkCreateDescriptorSetLayout($this->device, $imageInfo, 0, $imageLayout), 'vkCreateDescriptorSetLayout');
        $this->imageLayout = $imageLayout;
        $compute = new VkPushConstantRange;
        $compute->stageFlags = VK_SHADER_STAGE_COMPUTE_BIT;
        $compute->offset = 0;
        $compute->size = self::PUSH_COMPUTE;
        $computeInfo = new VkPipelineLayoutCreateInfo;
        $computeInfo->setLayoutCount = 3;
        $computeInfo->pSetLayouts = [$this->storageLayout, $this->setLayout, $this->imageLayout];
        $computeInfo->pushConstantRangeCount = 1;
        $computeInfo->pPushConstantRanges = [$compute];
        self::ok(vkCreatePipelineLayout($this->device, $computeInfo, 0, $computeLayout), 'vkCreatePipelineLayout');
        $this->computeLayout = $computeLayout;

        $samplerInfo = new VkSamplerCreateInfo;
        $samplerInfo->magFilter = VK_FILTER_NEAREST;
        $samplerInfo->minFilter = VK_FILTER_NEAREST;
        $samplerInfo->mipmapMode = VK_SAMPLER_MIPMAP_MODE_NEAREST;
        $samplerInfo->addressModeU = $samplerInfo->addressModeV = $samplerInfo->addressModeW = VK_SAMPLER_ADDRESS_MODE_CLAMP_TO_EDGE;
        self::ok(vkCreateSampler($this->device, $samplerInfo, 0, $sampler), 'vkCreateSampler');
        $this->sampler = $sampler;
    }

    /** Everything the device made, in reverse. It tolerates an open() that stopped half way. */
    protected function close(): void
    {
        if (! is_null($this->device)) {
            vkDeviceWaitIdle($this->device);
            $this->dropSurfaces();
            foreach ($this->slots as $slot) {
                foreach ($slot['after'] as $release) {
                    $release();
                }
                vkDestroyFence($this->device, $slot['fence'], 0);
            }
            foreach ($this->vertexPool as $entries) {
                foreach ($entries as [$buffer, $memory]) {
                    vkDestroyBuffer($this->device, $buffer, 0);
                    vkFreeMemory($this->device, $memory, 0);
                }
            }
            foreach ($this->pipelines as $pipeline) {
                vkDestroyPipeline($this->device, $pipeline, 0);
            }
            foreach ($this->passes as $pass) {
                vkDestroyRenderPass($this->device, $pass, 0);
            }
            foreach ($this->modules as $module) {
                vkDestroyShaderModule($this->device, $module, 0);
            }
            if (isset($this->sampler)) {
                vkDestroySampler($this->device, $this->sampler, 0);
            }
            if (isset($this->layout)) {
                vkDestroyPipelineLayout($this->device, $this->layout, 0);
            }
            if (isset($this->raycastLayout)) {
                vkDestroyPipelineLayout($this->device, $this->raycastLayout, 0);
            }
            if (isset($this->computeLayout)) {
                vkDestroyPipelineLayout($this->device, $this->computeLayout, 0);
            }
            if (isset($this->imageLayout)) {
                vkDestroyDescriptorSetLayout($this->device, $this->imageLayout, 0);
            }
            if (isset($this->storageLayout)) {
                vkDestroyDescriptorSetLayout($this->device, $this->storageLayout, 0);
            }
            if (isset($this->setLayout)) {
                vkDestroyDescriptorSetLayout($this->device, $this->setLayout, 0);
            }
            if (isset($this->pool)) {
                vkDestroyCommandPool($this->device, $this->pool, 0);
            }
            vkDestroyDevice($this->device, 0);
            $this->device = null;
            [$this->slots, $this->submitted, $this->vertexPool, $this->pipelines, $this->passes, $this->modules] = [[], [], [], [], [], []];
        }
        if (isset($this->instance)) {
            vkDestroyInstance($this->instance, 0);
            unset($this->instance);
        }
    }
}
