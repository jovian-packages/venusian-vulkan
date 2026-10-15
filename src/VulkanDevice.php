<?php

namespace Jovian\Engines\Vulkan;

use Closure;
use Surface\Contracts\Drawing\ColorSpace;
use Surface\Contracts\Drawing\TargetFormat;
use Surface\Drawing\Gpu\TargetsFormats;
use Surface\Framebuffers\HdrImage;
use VkHdrMetadataEXT;
use Surface\Contracts\Drawing\PresentTiming;
use Surface\Contracts\Drawing\VSync;
use Surface\Contracts\Windows\ScaleFilter;
use Surface\Drawing\Gpu\DamageHistory;
use Surface\Drawing\Gpu\QueuesFrames;
use Surface\Drawing\Gpu\ReportsPresents;
use Surface\Drawing\Gpu\SwitchesVsync;
use VkDescriptorPool;
use VkPhysicalDeviceFeatures2;
use VkPhysicalDevicePresentIdFeaturesKHR;
use VkPhysicalDevicePresentWaitFeaturesKHR;
use VkPresentIdKHR;
use VkPresentRegionKHR;
use VkPresentRegionsKHR;
use VkRectLayerKHR;
use Surface\Contracts\Drawing\DrawingException;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Framebuffers\GLFramebuffer;
use Surface\Contracts\Framebuffers\Region;
use Surface\Drawing\Gpu\DrawList;
use Surface\Contracts\Framebuffers\Filter;
use Surface\Contracts\Rasterize\FillRule;
use Surface\Drawing\Gpu\GpuDevice;
use Surface\Drawing\Gpu\Op;


use CAMetalLayer;
use CGSize;
use VkApplicationInfo;
use VkAttachmentDescription;
use VkAttachmentReference;
use VkBuffer;
use VkBufferCreateInfo;
use VkBufferImageCopy;
use VkClearColorValue;
use VkClearDepthStencilValue;
use VkClearValue;
use VkCommandBuffer;
use VkCommandBufferAllocateInfo;
use VkCommandBufferBeginInfo;
use VkCommandPool;
use VkCommandPoolCreateInfo;
use VkDescriptorImageInfo;
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
use VkExportMemoryAllocateInfo;
use VkExtensionProperties;
use VkExtent2D;
use VkExternalMemoryImageCreateInfo;
use VkFence;
use VkFenceCreateInfo;
use VkFramebuffer;
use VkFramebufferCreateInfo;
use VkGraphicsPipelineCreateInfo;
use VkImage;
use VkImageBlit;
use VkImageCreateInfo;
use VkImageMemoryBarrier;
use VkImageSubresource;
use VkImageView;
use VkImageViewCreateInfo;
use VkInstance;
use VkInstanceCreateInfo;
use VkMappedMemoryRange;
use VkMemoryAllocateInfo;
use VkMemoryBarrier;
use VkMemoryDedicatedAllocateInfo;
use VkMemoryGetFdInfoKHR;
use VkMemoryRequirements;
use VkMetalSurfaceCreateInfoEXT;
use VkOffset3D;
use VkPhysicalDevice;
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
use VkPresentInfoKHR;
use VkPushConstantRange;
use VkQueue;
use VkRect2D;
use VkRenderPass;
use VkRenderPassBeginInfo;
use VkRenderPassCreateInfo;
use VkSampler;
use VkSamplerCreateInfo;
use VkSemaphore;
use VkSemaphoreCreateInfo;
use VkShaderModule;
use VkShaderModuleCreateInfo;
use VkSubmitInfo;
use VkSubpassDependency;
use VkSubpassDescription;
use VkSurfaceFormatKHR;
use VkSurfaceKHR;
use VkSwapchainCreateInfoKHR;
use VkSwapchainKHR;
use VkVertexInputAttributeDescription;
use VkVertexInputBindingDescription;
use VkViewport;
use VkWriteDescriptorSet;
use WeakReference;

/**
 * The vulkan engine's device: one instance, the first GPU that is not the CPU
 * device, one logical device with a graphics queue, and a persistent target.
 *
 * The target is an RGBA8 image ($resolved) kept in VK_IMAGE_LAYOUT_GENERAL for
 * its whole life: drawn into or resolved into, copied out of, uploaded into,
 * sampled and blitted from, all in the one layout, so no barrier changes its
 * layout after the first. With four samples a 4× colour image and a 4×
 * depth-stencil image sit behind a render pass that resolves into it; with one
 * sample the image is the colour attachment and the stencil is single-sample.
 *
 * Up to setFramesInFlight() frames run at once (1 unless set): each has its
 * command buffer, fence and transients (vertex buffer, source images,
 * descriptor pool) in a ring, and a draw waits only for the frame whose slot
 * it takes. Presents ring the same way, each with its acquire semaphore and
 * descriptor set. One queue orders them all, so every frame sees the one
 * before it. Readback and upload wait for every frame; they go through
 * host-visible staging buffers, flushed and invalidated at the device's
 * nonCoherentAtomSize whether the memory is coherent or not.
 *
 * Presenting: the show pass draws the target into the swapchain image, the
 * placed rect (presentRect() at the swapchain's size) sampled with the
 * surface's filter, the bars black, encoded for the swapchain; the damage
 * goes with it as VkPresentRegionsKHR where the device has incremental
 * present. The window's vsync picks the present mode; a DMABUF export is
 * paced by the toolkit. With present id and present wait, each present
 * carries its frame number, lastPresented() asks which were shown, and a
 * FIFO present waits for nothing: while setFramesInFlight() presents are
 * unshown (a hidden Wayland window gets no frame callbacks) present()
 * answers false instead of blocking in vkQueuePresentKHR.
 *
 * HDR: every HDR target (extended linear, HDR10) is an R16G16B16A16_SFLOAT
 * image of linear light in sRGB primaries, SDR white at 1.0. Both extended
 * spaces show in an R16G16B16A16_SFLOAT EXTENDED_SRGB_LINEAR swapchain (it
 * carries P3 as values outside 0..1), the show pass multiplying by the
 * surface's SDR white level; HDR10 shows in an A2B10G10R10 (or A2R10G10B10)
 * HDR10_ST2084 swapchain, the show pass encoding BT.2020 PQ, with HDR10
 * metadata where the device has VK_EXT_hdr_metadata. Display P3 is an RGBA8
 * target in a DISPLAY_P3_NONLINEAR swapchain. All need
 * VK_EXT_swapchain_colorspace on the instance.
 *
 * Image rows are top-down end to end, as Vulkan's image row 0 is the top: the
 * vertex shader puts pixel y = 0 at clip −1 and nothing flips anywhere.
 */
class VulkanDevice implements GpuDevice, SwitchesVsync, QueuesFrames, ReportsPresents, TargetsFormats
{
    public const string SHADERS = __DIR__.'/../resources/shaders';

    /** Push constants: the vertex stage's size (16 bytes), then the fragment stage's Paint (96). */
    public const int PUSH_FRAME = 16;

    public const int PUSH_PAINT = 96;

    protected const int SOLID = 0;

    protected const int FILL = 1;

    protected const int OVAL = 2;

    protected const int IMAGE = 3;

    protected const int COPY = 4;

    private const int WRITE_ALL = VK_COLOR_COMPONENT_R_BIT | VK_COLOR_COMPONENT_G_BIT | VK_COLOR_COMPONENT_B_BIT | VK_COLOR_COMPONENT_A_BIT;

    private VkInstance $instance;

    private VkPhysicalDevice $physical;

    private VkDevice $device;

    private VkQueue $queue;

    private int $family = 0;

    /** @var list<string> Device extensions enabled. */
    private array $extensions = [];

    private int $stencilFormat = 0;

    private int $largest = 0;

    private int $colorSamples = 0;

    private int $stencilSamples = 0;

    private int $atom = 1;

    /** @var list<object{propertyFlags: int, heapIndex: int}> */
    private array $memoryTypes = [];

    /** True when the host-visible type the last staging buffer got is coherent. */
    private bool $coherent = true;

    private VkCommandPool $pool;

    private VkCommandBuffer $onceCommands;

    /** The most frames and presents a ring holds. */
    private const int RING = 3;

    /** Bytes of the show pass's push constants after the vertex stage's 16. */
    private const int PUSH_SHOW = 48;

    /** Frames and presents allowed in flight: 1 to RING. */
    private int $inFlight = 1;

    /** @var list<VkCommandBuffer> */
    private array $frameRing = [];

    /** @var list<VkFence> */
    private array $frameFences = [];

    /** @var list<bool> */
    private array $framePending = [false, false, false];

    /** @var list<list<Closure(): void>> Each slot's transients, destroyed once its fence has signalled. */
    private array $retiredRing = [[], [], []];

    private int $frameSlot = 0;

    /** @var list<VkCommandBuffer> */
    private array $presentRing = [];

    /** @var list<VkFence> Made with the surface, gone with it. */
    private array $presentFences = [];

    /** @var list<bool> */
    private array $presentPending = [false, false, false];

    /** @var list<VkSemaphore> One acquire semaphore a present slot: reused only once that slot's submit has run. */
    private array $acquiredRing = [];

    /** One descriptor set a present slot, the target's view written into it at each present. */
    private ?VkDescriptorPool $showPool = null;

    /** @var list<VkDescriptorSet> */
    private array $showSets = [];

    private int $presentSlot = 0;

    /** The present mode the window's vsync picked; null for the device's own (MAILBOX where offered, else FIFO). */
    private ?int $presentMode = null;

    /** The present mode the swapchain was built with. */
    private int $modeInUse = VK_PRESENT_MODE_FIFO_KHR;

    /** @var list<int> The adopted surface's present modes. */
    private array $presentModes = [];

    /** Whether the device runs present id and present wait. */
    private bool $presentWaits = false;

    /** Presents submitted: the frame number of the newest. */
    private int $submitted = 0;

    /** The first frame number of the current swapchain: present ids are asked only of the swapchain that carried them. */
    private int $swapchainFirst = 1;

    private ?PresentTiming $shown = null;

    private ?DamageHistory $history = null;

    /** The swapchain's image format. */
    private int $swapFormat = VK_FORMAT_B8G8R8A8_UNORM;

    /** @var list<VkImageView> */
    private array $swapViews = [];

    /** @var list<VkFramebuffer> */
    private array $swapFramebuffers = [];

    /** @var array<int, VkRenderPass> The show pass, by swapchain format. */
    private array $showPasses = [];

    /** @var array<int, VkPipeline> The show pipeline, by swapchain format. */
    private array $showPipelines = [];

    private VkShaderModule $showVertex;

    private VkShaderModule $showFragment;

    private VkShaderModule $vertexModule;

    private VkShaderModule $fragmentModule;

    private VkDescriptorSetLayout $setLayout;

    private VkPipelineLayout $layout;

    private VkSampler $sampler;

    private VkImage $blankImage;

    private VkDeviceMemory $blankMemory;

    private VkImageView $blankView;

    /** @var array<int, array{VkImage, VkImageView, VkDeviceMemory}> The resolved image of every live framebuffer, by object id. */
    private array $owned = [];

    private ?WeakReference $framebuffer = null;

    private ?VkImage $resolved = null;

    private ?VkImageView $resolvedView = null;

    /** @var array{int, int} */
    private array $extent = [0, 0];

    private int $samples = 1;

    private bool $stale = false;

    /** @var array<string, mixed> The 4× colour and the stencil image, with their views and memory. */
    private array $attachments = [];

    /** @var array<int, VkRenderPass> By colour load op. */
    private array $passes = [];

    /** @var array<int, VkFramebuffer> By colour load op. */
    private array $framebuffers = [];

    /** @var array<string, VkPipeline> By kind. */
    private array $pipelines = [];

    /** The swapchain's surface: lent (a VkSurfaceKHR a toolkit made) or made here over a CAMetalLayer. */
    private ?VkSurfaceKHR $vkSurface = null;

    private bool $ownsSurface = false;

    private ?VkSwapchainKHR $swapchain = null;

    /** @var list<VkImage> */
    private array $swapImages = [];

    /** @var array{int, int} */
    private array $swapExtent = [0, 0];

    /** @var list<VkSemaphore> One per swapchain image: reused only when its image is acquired again, so the presentation engine is done with it. */
    private array $rendered = [];

    /** True when the last acquire or present said the swapchain no longer fits its surface: the next present rebuilds it. */
    private bool $outdated = false;

    /** Whether the instance runs VK_EXT_swapchain_colorspace. */
    private bool $colorspaces = false;

    private TargetFormat $format = TargetFormat::Rgba8;

    private ColorSpace $space = ColorSpace::Srgb;

    /** The target image's format: half floats for every HDR colour space. */
    private int $colorFormat = VK_FORMAT_R8G8B8A8_UNORM;

    /** True while a DMABUF surface is adopted: every target gets a linear export image beside it. */
    private bool $exporting = false;

    /** Export images a target gets: the importer holds one a present wrote while the next is written. */
    private const int EXPORT_POOL = 3;

    /** @var list<array{image: VkImage, memory: VkDeviceMemory, size: int, dmabuf: array{fd: int, width: int, height: int, stride: int, offset: int, fourcc: int, modifier: int}, held: bool}> The target's export images. */
    private array $exports = [];

    /** The export the last present wrote; null before one. */
    private ?int $shownExport = null;

    /** @var array<int, true> Descriptors of exports let go of while the importer held them: closed when it hands them back. */
    private array $heldFds = [];

    /** The surface adopted, while it is lent: a device let go first hands it back. */
    private ?LentSurface $lent = null;

    /** DRM_FORMAT_ABGR8888: bytes R, G, B, A in memory, as R8G8B8A8_UNORM lays them. */
    public const int DRM_FORMAT_ABGR8888 = 0x34324241;

    /** DRM_FORMAT_MOD_LINEAR. */
    public const int DRM_FORMAT_MOD_LINEAR = 0;

    private bool $released = false;

    /**
     * @throws DrawingException When no GPU device can be opened.
     */
    public function __construct()
    {
        vkEnumerateInstanceExtensionProperties(null, $available);
        $listed = array_map(fn (VkExtensionProperties $e): string => $e->extensionName, $available ?? []);
        // Every platform surface a canvas's toolkit may make against this instance: Metal on macOS;
        // Wayland, xcb and Xlib elsewhere (Qt picks by the session it runs in).
        $wanted = [VK_KHR_SURFACE_EXTENSION_NAME, VK_KHR_GET_PHYSICAL_DEVICE_PROPERTIES_2_EXTENSION_NAME, VK_EXT_SWAPCHAIN_COLOR_SPACE_EXTENSION_NAME];
        $wanted = [...$wanted, ...(PHP_OS_FAMILY === 'Darwin'
            ? [VK_EXT_METAL_SURFACE_EXTENSION_NAME]
            : [VK_KHR_WAYLAND_SURFACE_EXTENSION_NAME, VK_KHR_XCB_SURFACE_EXTENSION_NAME, VK_KHR_XLIB_SURFACE_EXTENSION_NAME])];
        $app = new VkApplicationInfo;
        $app->pApplicationName = 'venusian-vulkan';
        $app->apiVersion = VK_API_VERSION_1_1;
        $info = new VkInstanceCreateInfo;
        $info->pApplicationInfo = $app;
        $enabled = array_values(array_filter($wanted, fn (?string $name): bool => ! is_null($name) && in_array($name, $listed, true)));
        if (in_array(VK_KHR_PORTABILITY_ENUMERATION_EXTENSION_NAME, $listed, true)) {
            $enabled[] = VK_KHR_PORTABILITY_ENUMERATION_EXTENSION_NAME;
            $info->flags |= VK_INSTANCE_CREATE_ENUMERATE_PORTABILITY_BIT_KHR;
        }
        $info->enabledExtensionNames = $enabled;
        $this->colorspaces = in_array(VK_EXT_SWAPCHAIN_COLOR_SPACE_EXTENSION_NAME, $enabled, true);
        self::ok(vkCreateInstance($info, null, $instance), 'vkCreateInstance');
        $this->instance = $instance;

        try {
            $this->openDevice();
        } catch (\Throwable $failure) {
            $this->destroyAll();

            throw $failure;
        }
    }

    public function __destruct()
    {
        $this->release();
    }

    public function name(): string
    {
        return 'vulkan';
    }

    public function surfaces(): array
    {
        return [SurfaceKind::VULKAN_SURFACE, SurfaceKind::METAL_LAYER, ...($this->missingExport() === [] ? [SurfaceKind::DMABUF] : [])];
    }

    public function handles(): array
    {
        return ['instance' => $this->instance->pointer()];
    }

    public function instance(): VkInstance
    {
        return $this->instance;
    }

    public function physical(): VkPhysicalDevice
    {
        return $this->physical;
    }

    public function device(): VkDevice
    {
        return $this->device;
    }

    public function queue(): VkQueue
    {
        return $this->queue;
    }

    public function family(): int
    {
        return $this->family;
    }

    public function stencilFormat(): int
    {
        return $this->stencilFormat;
    }

    /** True when the host-visible memory type the last staging buffer was given is HOST_COHERENT. */
    public function coherent(): bool
    {
        return $this->coherent;
    }

    /** @return list<string> The device extensions enabled. */
    public function extensions(): array
    {
        return $this->extensions;
    }

    /** 4 when colour and stencil both render at 4 samples, else 1. */
    public function maxSamples(): int
    {
        $four = VK_SAMPLE_COUNT_4_BIT;

        return ($this->colorSamples & $four) !== 0 && ($this->stencilSamples & $four) !== 0 ? 4 : 1;
    }

    /** The widest and tallest image this device makes (maxImageDimension2D). */
    public function maxImageDimension2D(): int
    {
        return $this->largest;
    }

    private function make(int $width, int $height, int $samples, TargetFormat $format, ColorSpace $space): GLFramebuffer
    {
        if ($samples !== 1 && $samples !== 4) {
            throw new DrawingException("vulkan draws with 1 or 4 samples, got {$samples}.");
        }
        if ($width < 1 || $height < 1) {
            throw new DrawingException("vulkan needs a target of at least 1 × 1, got {$width} × {$height}.");
        }
        $this->live();
        $most = $this->maxSamples();
        if ($samples === 4 && $most < 4) {
            throw new DrawingException("vulkan: this device renders at most {$most} ".($most === 1 ? 'sample' : 'samples').'; anti-aliased edges need 4.');
        }
        $this->fits($width, $height);

        $this->finish();
        $this->releaseTarget();
        // A new colour space needs a swapchain in its format: rebuilt before the next present.
        if ($space !== $this->space && ! is_null($this->swapchain)) {
            $this->outdated = true;
        }
        $this->format = $format;
        $this->space = $space;
        $this->colorFormat = $space->isHdr() ? VK_FORMAT_R16G16B16A16_SFLOAT : $this->colorFormat;
        $this->samples = $samples;
        $this->stale = false;
        $this->extent = [$width, $height];

        $usage = VK_IMAGE_USAGE_COLOR_ATTACHMENT_BIT | VK_IMAGE_USAGE_TRANSFER_SRC_BIT | VK_IMAGE_USAGE_TRANSFER_DST_BIT | VK_IMAGE_USAGE_SAMPLED_BIT;
        [$image, $memory] = $this->image($this->colorFormat, $width, $height, VK_SAMPLE_COUNT_1_BIT, $usage);
        $view = $this->view($image, $this->colorFormat, VK_IMAGE_ASPECT_COLOR_BIT);
        $this->resolved = $image;
        $this->resolvedView = $view;
        $framebuffer = new VulkanFramebuffer($this, $image, $view, $memory, $width, $height, $space->isHdr());
        $this->owned[spl_object_id($framebuffer)] = [$image, $view, $memory];

        try {
            $this->once(fn (VkCommandBuffer $commands) => vkCmdPipelineBarrier(
                $commands, VK_PIPELINE_STAGE_TOP_OF_PIPE_BIT, VK_PIPELINE_STAGE_ALL_COMMANDS_BIT, 0, [], [],
                [self::barrier($image, VK_IMAGE_LAYOUT_UNDEFINED, VK_IMAGE_LAYOUT_GENERAL, 0, VK_ACCESS_MEMORY_READ_BIT | VK_ACCESS_MEMORY_WRITE_BIT)],
            ));

            $this->attachments = [];
            $samplesBit = $samples === 4 ? VK_SAMPLE_COUNT_4_BIT : VK_SAMPLE_COUNT_1_BIT;
            if ($samples === 4) {
                [$colorImage, $colorMemory] = $this->image($this->colorFormat, $width, $height, $samplesBit, VK_IMAGE_USAGE_COLOR_ATTACHMENT_BIT);
                $this->attachments['color'] = [$colorImage, $this->view($colorImage, $this->colorFormat, VK_IMAGE_ASPECT_COLOR_BIT), $colorMemory];
            }
            [$stencilImage, $stencilMemory] = $this->image($this->stencilFormat, $width, $height, $samplesBit, VK_IMAGE_USAGE_DEPTH_STENCIL_ATTACHMENT_BIT);
            $this->attachments['stencil'] = [$stencilImage, $this->view($stencilImage, $this->stencilFormat, VK_IMAGE_ASPECT_DEPTH_BIT | VK_IMAGE_ASPECT_STENCIL_BIT), $stencilMemory];

            // Defined contents from the start: transparent black (at 4× the clear reaches $resolved through the resolve).
            $this->once(function (VkCommandBuffer $commands): void {
                $this->beginPass($commands, VK_ATTACHMENT_LOAD_OP_CLEAR);
                vkCmdEndRenderPass($commands);
            });
        } catch (\Throwable $failure) {
            $this->releaseTarget();
            $this->forget($framebuffer);

            throw $failure;
        }
        $this->framebuffer = WeakReference::create($framebuffer);
        if ($this->exporting) {
            $this->makeExports();
        }

        return $framebuffer;
    }

    public function target(int $width, int $height, int $samples): GLFramebuffer
    {
        return $this->make($width, $height, $samples, TargetFormat::Rgba8, ColorSpace::Srgb);
    }

    public function swapchainColorSpaces(): bool
    {
        return $this->colorspaces;
    }

    /** Whether the device runs VK_EXT_hdr_metadata. */
    public function hdrMetadata(): bool
    {
        return in_array(VK_EXT_HDR_METADATA_EXTENSION_NAME, $this->extensions, true);
    }

    public function targetFormats(): array
    {
        if (! $this->colorspaces) {
            return [];
        }
        $formats = [];
        vkGetPhysicalDeviceFormatProperties($this->physical, VK_FORMAT_R16G16B16A16_SFLOAT, $properties);
        $needs = VK_FORMAT_FEATURE_COLOR_ATTACHMENT_BLEND_BIT | VK_FORMAT_FEATURE_SAMPLED_IMAGE_BIT | VK_FORMAT_FEATURE_TRANSFER_SRC_BIT | VK_FORMAT_FEATURE_TRANSFER_DST_BIT;
        if (($properties->optimalTilingFeatures & $needs) === $needs) {
            $formats[] = [TargetFormat::Rgba16Float, ColorSpace::ExtendedLinearSrgb];
            $formats[] = [TargetFormat::Rgba16Float, ColorSpace::ExtendedLinearDisplayP3];
            $formats[] = [TargetFormat::Rgb10A2, ColorSpace::Hdr10Pq];
        }
        $formats[] = [TargetFormat::Rgba8, ColorSpace::DisplayP3];

        return $formats;
    }

    public function targetAs(int $width, int $height, int $samples, TargetFormat $format, ColorSpace $space): GLFramebuffer
    {
        $this->live();
        if ($this->exporting) {
            throw new DrawingException('vulkan: a dmabuf export carries RGBA8 sRGB only.');
        }
        if (! in_array([$format, $space], $this->targetFormats(), true)) {
            throw new DrawingException("vulkan makes no {$format->value} {$space->value} target.");
        }
        if (! is_null($this->vkSurface) && is_null($this->swapFormatFor($space, $this->surfaceFormats()))) {
            $offers = implode(', ', array_map(fn (array $pair): string => "({$pair[0]}, {$pair[1]})", $this->surfaceFormats()));

            throw new DrawingException("vulkan: this surface shows no {$space->value} swapchain; it offers (format, colour space): {$offers}.");
        }

        return $this->make($width, $height, $samples, $format, $space);
    }

    /** @return list<array{int, int}> The adopted surface's (format, colour space) pairs. */
    public function surfaceFormats(): array
    {
        if (is_null($this->vkSurface)) {
            return [];
        }
        self::ok(vkGetPhysicalDeviceSurfaceFormatsKHR($this->physical, $this->vkSurface, $formats), 'vkGetPhysicalDeviceSurfaceFormatsKHR');

        return array_map(fn (VkSurfaceFormatKHR $f): array => [$f->format, $f->colorSpace], $formats ?? []);
    }

    /**
     * The swapchain's (format, colour space) for $space among $offered:
     * half floats in extended-linear sRGB for both extended spaces, 10-bit
     * HDR10 ST 2084 for HDR10, 8-bit Display P3 for P3; for sRGB the
     * B8G8R8A8 / R8G8B8A8 UNORM first, then any non-sRGB format, then an sRGB
     * one (the show pass decodes for it), always in SRGB_NONLINEAR. Null
     * when none is offered.
     *
     * @param  list<array{int, int}>  $offered
     * @return array{int, int}|null
     */
    private function swapFormatFor(ColorSpace $space, array $offered): ?array
    {
        $wanted = match ($space) {
            ColorSpace::ExtendedLinearSrgb, ColorSpace::ExtendedLinearDisplayP3 => [[VK_FORMAT_R16G16B16A16_SFLOAT, VK_COLOR_SPACE_EXTENDED_SRGB_LINEAR_EXT]],
            ColorSpace::Hdr10Pq => [[VK_FORMAT_A2B10G10R10_UNORM_PACK32, VK_COLOR_SPACE_HDR10_ST2084_EXT], [VK_FORMAT_A2R10G10B10_UNORM_PACK32, VK_COLOR_SPACE_HDR10_ST2084_EXT]],
            ColorSpace::DisplayP3 => [[VK_FORMAT_B8G8R8A8_UNORM, VK_COLOR_SPACE_DISPLAY_P3_NONLINEAR_EXT], [VK_FORMAT_R8G8B8A8_UNORM, VK_COLOR_SPACE_DISPLAY_P3_NONLINEAR_EXT]],
            ColorSpace::Srgb => [],
        };
        foreach ($wanted as $pair) {
            if (in_array($pair, $offered, true)) {
                return $pair;
            }
        }
        if ($space !== ColorSpace::Srgb) {
            return null;
        }
        $srgb = [VK_FORMAT_B8G8R8A8_SRGB, VK_FORMAT_R8G8B8A8_SRGB, VK_FORMAT_A8B8G8R8_SRGB_PACK32];
        $rank = fn (array $pair): int => match (true) {
            $pair[0] === VK_FORMAT_B8G8R8A8_UNORM => 0,
            $pair[0] === VK_FORMAT_R8G8B8A8_UNORM => 1,
            ! in_array($pair[0], $srgb, true) => 2,
            default => 3,
        };
        $best = null;
        foreach ($offered as $pair) {
            if ($pair[1] === VK_COLOR_SPACE_SRGB_NONLINEAR_KHR && (is_null($best) || $rank($pair) < $rank($best))) {
                $best = $pair;
            }
        }

        return $best ?? $offered[0] ?? null;
    }

    /** HDR10 static metadata on the swapchain (Shared definitions), where the device takes it. */
    private function sendHdrMetadata(): void
    {
        if ($this->space !== ColorSpace::Hdr10Pq || ! $this->hdrMetadata() || is_null($this->swapchain)) {
            return;
        }
        [, , $nits] = $this->transform();
        $headroom = $this->lent?->hdr()?->headroom ?? 1.0;
        $peak = $headroom > 1.0 ? $nits * $headroom : 1000.0;
        $meta = new VkHdrMetadataEXT;
        [$meta->displayPrimaryRed->x, $meta->displayPrimaryRed->y] = [0.708, 0.292];
        [$meta->displayPrimaryGreen->x, $meta->displayPrimaryGreen->y] = [0.170, 0.797];
        [$meta->displayPrimaryBlue->x, $meta->displayPrimaryBlue->y] = [0.131, 0.046];
        [$meta->whitePoint->x, $meta->whitePoint->y] = [0.3127, 0.3290];
        $meta->maxLuminance = $peak;
        $meta->minLuminance = 0.005;
        $meta->maxContentLightLevel = $peak;
        $meta->maxFrameAverageLightLevel = $nits;
        vkSetHdrMetadataEXT($this->device, [$this->swapchain], [$meta]);
    }

    /** @internal VulkanFramebuffer's readback: $region of $image as a frame string, rows top-down. */
    public function read(VkImage $image, Region $region, int $bytes = 4): string
    {
        $this->live();
        $this->finish();
        $size = $region->width * $region->height * $bytes;
        [$buffer, $memory, $allocated] = $this->hostBuffer($size, VK_BUFFER_USAGE_TRANSFER_DST_BIT);
        try {
            $this->once(function (VkCommandBuffer $commands) use ($image, $buffer, $region): void {
                vkCmdPipelineBarrier(
                    $commands, VK_PIPELINE_STAGE_ALL_COMMANDS_BIT, VK_PIPELINE_STAGE_TRANSFER_BIT, 0, [], [],
                    [self::barrier($image, VK_IMAGE_LAYOUT_GENERAL, VK_IMAGE_LAYOUT_GENERAL, VK_ACCESS_MEMORY_WRITE_BIT, VK_ACCESS_TRANSFER_READ_BIT)],
                );
                vkCmdCopyImageToBuffer($commands, $image, VK_IMAGE_LAYOUT_GENERAL, $buffer, [self::copyOf($region)]);
                // The copy's writes made available and visible to the host before it maps the buffer.
                $toHost = new VkMemoryBarrier;
                $toHost->srcAccessMask = VK_ACCESS_TRANSFER_WRITE_BIT;
                $toHost->dstAccessMask = VK_ACCESS_HOST_READ_BIT;
                vkCmdPipelineBarrier($commands, VK_PIPELINE_STAGE_TRANSFER_BIT, VK_PIPELINE_STAGE_HOST_BIT, 0, [$toHost], [], []);
            });
            self::ok(vkMapMemory($this->device, $memory, 0, $allocated, 0, $address), 'vkMapMemory');
            try {
                self::ok(vkInvalidateMappedMemoryRanges($this->device, [$this->range($memory, $size, $allocated)]), 'vkInvalidateMappedMemoryRanges');

                return vk_read_mapped($address, $size);
            } finally {
                vkUnmapMemory($this->device, $memory);
            }
        } finally {
            vkDestroyBuffer($this->device, $buffer, null);
            vkFreeMemory($this->device, $memory, null);
        }
    }

    /** @internal VulkanFramebuffer's upload: $rgba8 (rows top-down) over $region of $image. */
    public function write(VkImage $image, string $texels, Region $region): void
    {
        $this->live();
        $this->finish();
        [$buffer, $memory, $allocated] = $this->stage($texels, VK_BUFFER_USAGE_TRANSFER_SRC_BIT);
        try {
            $this->once(function (VkCommandBuffer $commands) use ($image, $buffer, $region): void {
                vkCmdPipelineBarrier(
                    $commands, VK_PIPELINE_STAGE_ALL_COMMANDS_BIT, VK_PIPELINE_STAGE_TRANSFER_BIT, 0, [], [],
                    [self::barrier($image, VK_IMAGE_LAYOUT_GENERAL, VK_IMAGE_LAYOUT_GENERAL, VK_ACCESS_MEMORY_WRITE_BIT, VK_ACCESS_TRANSFER_WRITE_BIT)],
                );
                vkCmdCopyBufferToImage($commands, $buffer, $image, VK_IMAGE_LAYOUT_GENERAL, [self::copyOf($region)]);
                vkCmdPipelineBarrier(
                    $commands, VK_PIPELINE_STAGE_TRANSFER_BIT, VK_PIPELINE_STAGE_ALL_COMMANDS_BIT, 0, [], [],
                    [self::barrier($image, VK_IMAGE_LAYOUT_GENERAL, VK_IMAGE_LAYOUT_GENERAL, VK_ACCESS_TRANSFER_WRITE_BIT, VK_ACCESS_MEMORY_READ_BIT | VK_ACCESS_MEMORY_WRITE_BIT)],
                );
            });
        } finally {
            vkDestroyBuffer($this->device, $buffer, null);
            vkFreeMemory($this->device, $memory, null);
        }
        if ($image === $this->resolved && $this->samples === 4) {
            $this->stale = true;
        }
    }

    /** @internal VulkanFramebuffer lets go of its image here, when the PHP object goes. */
    public function forget(VulkanFramebuffer $framebuffer): void
    {
        $id = spl_object_id($framebuffer);
        if ($this->released || ! isset($this->owned[$id])) {
            return;
        }
        $this->finish();
        $this->presented();
        [$image, $view, $memory] = $this->owned[$id];
        unset($this->owned[$id]);
        if ($image === $this->resolved) {
            $this->releaseTarget();
        }
        vkDestroyImageView($this->device, $view, null);
        vkDestroyImage($this->device, $image, null);
        vkFreeMemory($this->device, $memory, null);
    }

    /** Wait for every frame in flight, then free what they used. */
    public function finish(): void
    {
        if ($this->released) {
            return;
        }
        foreach (array_keys($this->frameFences) as $slot) {
            $this->settle($slot);
        }
    }

    /** Wait for the frame in $slot, then free what it used. */
    private function settle(int $slot): void
    {
        if ($this->framePending[$slot]) {
            self::ok(vkWaitForFences($this->device, [$this->frameFences[$slot]], true, PHP_INT_MAX), 'vkWaitForFences');
            self::ok(vkResetFences($this->device, [$this->frameFences[$slot]]), 'vkResetFences');
            $this->framePending[$slot] = false;
        }
        $retired = $this->retiredRing[$slot];
        $this->retiredRing[$slot] = [];
        foreach (array_reverse($retired) as $destroy) {
            $destroy();
        }
    }

    /** Wait for every present's pass: they sample the target, which must outlive them. */
    private function presented(): void
    {
        foreach ($this->presentPending as $slot => $pending) {
            if ($pending) {
                self::ok(vkWaitForFences($this->device, [$this->presentFences[$slot]], true, PHP_INT_MAX), 'vkWaitForFences');
                self::ok(vkResetFences($this->device, [$this->presentFences[$slot]]), 'vkResetFences');
                $this->presentPending[$slot] = false;
            }
        }
    }

    public function setFramesInFlight(int $frames): void
    {
        if ($frames < 1 || $frames > self::RING) {
            throw new DrawingException('vulkan keeps 1 to '.self::RING." frames in flight, got {$frames}.");
        }
        $this->finish();
        $this->presented();
        $this->inFlight = $frames;
        $this->frameSlot = $this->presentSlot = 0;
        // The swapchain gets an image more than the presents in flight: rebuilt before the next present.
        $this->outdated = true;
    }

    /** Whether the device runs VK_KHR_present_id and VK_KHR_present_wait. */
    public function presentWaits(): bool
    {
        return $this->presentWaits;
    }

    public function draw(DrawList $list): void
    {
        $this->live();
        ($this->framebuffer?->get()) ?? throw new DrawingException('vulkan: target() comes before draw().');
        [$width, $height] = $this->extent;
        $slot = $this->frameSlot;
        $this->settle($slot);

        // The list's vertices, then one quad over the whole target for CLEAR and the restore.
        $whole = $list->vertexCount();
        $vertices = $list->vertices.self::quad(0.0, 0.0, (float) $width, (float) $height);
        /** @var list<Closure(): void> $transients This frame's objects, freed once its fence has signalled (at once when it never ran). */
        $transients = [];
        try {
            [$vertexBuffer, $vertexMemory] = $this->stage($vertices, VK_BUFFER_USAGE_VERTEX_BUFFER_BIT);
            $transients[] = function () use ($vertexBuffer, $vertexMemory): void {
                vkDestroyBuffer($this->device, $vertexBuffer, null);
                vkFreeMemory($this->device, $vertexMemory, null);
            };

            // Sources: a framebuffer of this device is sampled through its own view, the target
            // itself through a copy made before the frame (it is this frame's attachment);
            // anything else is uploaded.
            /** @var array<int, array{VkImageView, int, int}> $sources View, width, height by the list's texture number. */
            $sources = [];
            /** @var list<array{VkBuffer, VkImage, int, int}> $copies */
            $copies = [];
            /** @var array<int, bool> $floats */
            $floats = [];
            /** @var list<array{VkImage, int, int}> $snapshots */
            $snapshots = [];
            foreach ($list->operations as $operation) {
                if ($operation[0] !== Op::UPLOAD) {
                    continue;
                }
                $source = $operation[2];
                if ($source instanceof VulkanFramebuffer && $source->device() === $this && $source->image() !== $this->resolved) {
                    $sources[$operation[1]] = [$source->view(), $source->width(), $source->height()];
                    $floats[$operation[1]] = $source->hdr();

                    continue;
                }
                if ($source instanceof VulkanFramebuffer && $source->device() === $this) {
                    [$w, $h] = [$source->width(), $source->height()];
                    [$image, $memory] = $this->image($this->colorFormat, $w, $h, VK_SAMPLE_COUNT_1_BIT, VK_IMAGE_USAGE_SAMPLED_BIT | VK_IMAGE_USAGE_TRANSFER_DST_BIT);
                    $transients[] = function () use ($image, $memory): void {
                        vkDestroyImage($this->device, $image, null);
                        vkFreeMemory($this->device, $memory, null);
                    };
                    $view = $this->view($image, $this->colorFormat, VK_IMAGE_ASPECT_COLOR_BIT);
                    $transients[] = fn () => vkDestroyImageView($this->device, $view, null);
                    $sources[$operation[1]] = [$view, $w, $h];
                    $floats[$operation[1]] = $this->space->isHdr();
                    $snapshots[] = [$image, $w, $h];

                    continue;
                }
                $w = $source->viewportWidth();
                $h = $source->viewportHeight();
                $this->fits($w, $h);
                $float = $source instanceof HdrImage && $this->space->isHdr();
                $format = $float ? VK_FORMAT_R16G16B16A16_SFLOAT : VK_FORMAT_R8G8B8A8_UNORM;
                [$image, $memory] = $this->image($format, $w, $h, VK_SAMPLE_COUNT_1_BIT, VK_IMAGE_USAGE_SAMPLED_BIT | VK_IMAGE_USAGE_TRANSFER_DST_BIT);
                $transients[] = function () use ($image, $memory): void {
                    vkDestroyImage($this->device, $image, null);
                    vkFreeMemory($this->device, $memory, null);
                };
                $view = $this->view($image, $format, VK_IMAGE_ASPECT_COLOR_BIT);
                $transients[] = fn () => vkDestroyImageView($this->device, $view, null);
                [$staging, $stagingMemory] = $this->stage($float ? $source->rgba16f() : $source->toRgba8(), VK_BUFFER_USAGE_TRANSFER_SRC_BIT);
                $transients[] = function () use ($staging, $stagingMemory): void {
                    vkDestroyBuffer($this->device, $staging, null);
                    vkFreeMemory($this->device, $stagingMemory, null);
                };
                $sources[$operation[1]] = [$view, $w, $h];
                $floats[$operation[1]] = $float;
                $copies[] = [$staging, $image, $w, $h];
            }

            // One pool a frame: set 0 for the blank image, one per source, one for the restore.
            $count = 1 + count($sources) + ($this->stale ? 1 : 0);
            $size = new VkDescriptorPoolSize;
            $size->type = VK_DESCRIPTOR_TYPE_COMBINED_IMAGE_SAMPLER;
            $size->descriptorCount = $count;
            $poolInfo = new VkDescriptorPoolCreateInfo;
            $poolInfo->maxSets = $count;
            $poolInfo->pPoolSizes = [$size];
            self::ok(vkCreateDescriptorPool($this->device, $poolInfo, null, $descriptorPool), 'vkCreateDescriptorPool');
            $transients[] = fn () => vkDestroyDescriptorPool($this->device, $descriptorPool, null);
            $allocate = new VkDescriptorSetAllocateInfo;
            $allocate->descriptorPool = $descriptorPool;
            $allocate->pSetLayouts = array_fill(0, $count, $this->setLayout);
            self::ok(vkAllocateDescriptorSets($this->device, $allocate, $sets), 'vkAllocateDescriptorSets');
            $blankSet = array_shift($sets);
            $writes = [$this->descriptor($blankSet, $this->blankView)];
            $setOf = [];
            foreach ($sources as $texture => [$view]) {
                $setOf[$texture] = $set = array_shift($sets);
                $writes[] = $this->descriptor($set, $view);
            }
            $restoreSet = null;
            if ($this->stale) {
                $restoreSet = array_shift($sets);
                $writes[] = $this->descriptor($restoreSet, $this->resolvedView);
            }
            vkUpdateDescriptorSets($this->device, $writes, []);

            $commands = $this->frameRing[$slot];
            self::ok(vkResetCommandBuffer($commands, 0), 'vkResetCommandBuffer');
            $begin = new VkCommandBufferBeginInfo;
            $begin->flags = VK_COMMAND_BUFFER_USAGE_ONE_TIME_SUBMIT_BIT;
            self::ok(vkBeginCommandBuffer($commands, $begin), 'vkBeginCommandBuffer');

            foreach ($copies as [$staging, $image, $w, $h]) {
                vkCmdPipelineBarrier($commands, VK_PIPELINE_STAGE_TOP_OF_PIPE_BIT, VK_PIPELINE_STAGE_TRANSFER_BIT, 0, [], [],
                    [self::barrier($image, VK_IMAGE_LAYOUT_UNDEFINED, VK_IMAGE_LAYOUT_GENERAL, 0, VK_ACCESS_TRANSFER_WRITE_BIT)]);
                vkCmdCopyBufferToImage($commands, $staging, $image, VK_IMAGE_LAYOUT_GENERAL, [self::copyOf(new Region(0, 0, $w, $h))]);
                vkCmdPipelineBarrier($commands, VK_PIPELINE_STAGE_TRANSFER_BIT, VK_PIPELINE_STAGE_FRAGMENT_SHADER_BIT, 0, [], [],
                    [self::barrier($image, VK_IMAGE_LAYOUT_GENERAL, VK_IMAGE_LAYOUT_GENERAL, VK_ACCESS_TRANSFER_WRITE_BIT, VK_ACCESS_SHADER_READ_BIT)]);
            }

            foreach ($snapshots as [$image, $w, $h]) {
                vkCmdPipelineBarrier($commands, VK_PIPELINE_STAGE_TOP_OF_PIPE_BIT, VK_PIPELINE_STAGE_TRANSFER_BIT, 0, [], [],
                    [self::barrier($image, VK_IMAGE_LAYOUT_UNDEFINED, VK_IMAGE_LAYOUT_GENERAL, 0, VK_ACCESS_TRANSFER_WRITE_BIT)]);
                $blit = new VkImageBlit;
                $blit->srcSubresource->aspectMask = $blit->dstSubresource->aspectMask = VK_IMAGE_ASPECT_COLOR_BIT;
                $blit->srcSubresource->layerCount = $blit->dstSubresource->layerCount = 1;
                $blit->srcOffsets = $blit->dstOffsets = [self::offset(0, 0, 0), self::offset($w, $h, 1)];
                vkCmdBlitImage($commands, $this->resolved, VK_IMAGE_LAYOUT_GENERAL, $image, VK_IMAGE_LAYOUT_GENERAL, [$blit], VK_FILTER_NEAREST);
                vkCmdPipelineBarrier($commands, VK_PIPELINE_STAGE_TRANSFER_BIT, VK_PIPELINE_STAGE_FRAGMENT_SHADER_BIT, 0, [], [],
                    [self::barrier($image, VK_IMAGE_LAYOUT_GENERAL, VK_IMAGE_LAYOUT_GENERAL, VK_ACCESS_TRANSFER_WRITE_BIT, VK_ACCESS_SHADER_READ_BIT)]);
            }

            if (! is_null($restoreSet)) {
                // The target's last frame, drawn back into the 4× colour after an upload.
                $this->beginPass($commands, VK_ATTACHMENT_LOAD_OP_DONT_CARE);
                $this->prepare($commands, $vertexBuffer, $width, $height);
                vkCmdBindDescriptorSets($commands, VK_PIPELINE_BIND_POINT_GRAPHICS, $this->layout, 0, [$restoreSet], []);
                $this->paint($commands, 'solid', self::COPY, 0);
                vkCmdDraw($commands, 6, 1, $whole, 0);
                vkCmdEndRenderPass($commands);
            }

            $this->beginPass($commands, VK_ATTACHMENT_LOAD_OP_LOAD);
            $this->prepare($commands, $vertexBuffer, $width, $height);
            vkCmdBindDescriptorSets($commands, VK_PIPELINE_BIND_POINT_GRAPHICS, $this->layout, 0, [$blankSet], []);
            $scissor = [0, 0, $width, $height];

            foreach ($list->operations as $operation) {
                switch ($operation[0]) {
                    case Op::CLEAR:
                        vkCmdSetScissor($commands, 0, [self::rect(0, 0, $width, $height)]);
                        $this->paint($commands, 'solid', self::SOLID, $operation[1]);
                        vkCmdDraw($commands, 6, 1, $whole, 0);
                        vkCmdSetScissor($commands, 0, [self::rect(...$scissor)]);
                        break;

                    case Op::SCISSOR:
                        $scissor = [$operation[1]->x, $operation[1]->y, $operation[1]->width, $operation[1]->height];
                        vkCmdSetScissor($commands, 0, [self::rect(...$scissor)]);
                        break;

                    case Op::SOLID:
                        $this->paint($commands, 'solid', self::SOLID, $operation[2]);
                        vkCmdDraw($commands, 6, 1, $operation[1], 0);
                        break;

                    case Op::STENCIL_FILL:
                        // SOLID never discards: every fragment of the path reaches the stencil.
                        $this->paint($commands, $operation[3] === FillRule::NON_ZERO ? 'winding' : 'invert', self::SOLID, 0);
                        vkCmdDraw($commands, $operation[2], 1, $operation[1], 0);
                        break;

                    case Op::COVER:
                        $this->paint($commands, 'cover', self::FILL, $operation[2]);
                        vkCmdDraw($commands, 6, 1, $operation[1], 0);
                        break;

                    case Op::RECTS:
                        $this->paint($commands, 'fill', self::FILL, $operation[3]);
                        vkCmdDraw($commands, $operation[2], 1, $operation[1], 0);
                        break;

                    case Op::ELLIPSE:
                        [, $first, $cx, $cy, $rx, $ry, $rgba] = $operation;
                        $this->paint($commands, 'fill', self::OVAL, $rgba, shape: [$cx, $cy, $rx, $ry], band: [0.0, 0.0, (float) $this->samples, 0.0]);
                        vkCmdDraw($commands, 6, 1, $first, 0);
                        break;

                    case Op::RING:
                        [, $first, $cx, $cy, $rx, $ry, $stroke, $rgba] = $operation;
                        $this->paint($commands, 'fill', self::OVAL, $rgba, shape: [$cx, $cy, $rx, $ry], band: [$stroke, 1.0, (float) $this->samples, 0.0]);
                        vkCmdDraw($commands, 6, 1, $first, 0);
                        break;

                    case Op::UPLOAD:
                        break;

                    case Op::IMAGE:
                        [, $first, $texture, $inverse, $opacity, $filter] = $operation;
                        [, $w, $h] = $sources[$texture];
                        vkCmdBindDescriptorSets($commands, VK_PIPELINE_BIND_POINT_GRAPHICS, $this->layout, 0, [$setOf[$texture]], []);
                        $this->paint($commands, 'fill', self::IMAGE, 0, opacity: $opacity, linear: $filter === Filter::LINEAR ? 1 : 0,
                            abcd: [$inverse->a, $inverse->b, $inverse->c, $inverse->d], efwh: [$inverse->e, $inverse->f, (float) $w, (float) $h], floatSource: $floats[$texture]);
                        vkCmdDraw($commands, 6, 1, $first, 0);
                        break;
                }
            }

            vkCmdEndRenderPass($commands);
            self::ok(vkEndCommandBuffer($commands), 'vkEndCommandBuffer');
            $submit = new VkSubmitInfo;
            $submit->pCommandBuffers = [$commands];
            self::ok(vkQueueSubmit($this->queue, [$submit], $this->frameFences[$slot]), 'vkQueueSubmit');
            $this->framePending[$slot] = true;
            // Only a submitted restore puts the samples back: a frame that failed leaves them stale.
            if (! is_null($restoreSet)) {
                $this->stale = false;
            }
        } catch (\Throwable $failure) {
            // Nothing ran: the frame's objects go now.
            foreach (array_reverse($transients) as $destroy) {
                $destroy();
            }

            throw $failure;
        }
        $this->retiredRing[$slot] = $transients;
        $this->frameSlot = ($slot + 1) % $this->inFlight;
    }

    /** Whole-target viewport and scissor, the vertex buffer, and the vertex stage's size. */
    private function prepare(VkCommandBuffer $commands, VkBuffer $vertexBuffer, int $width, int $height): void
    {
        $viewport = new VkViewport;
        [$viewport->x, $viewport->y, $viewport->width, $viewport->height, $viewport->minDepth, $viewport->maxDepth] = [0.0, 0.0, (float) $width, (float) $height, 0.0, 1.0];
        vkCmdSetViewport($commands, 0, [$viewport]);
        vkCmdSetScissor($commands, 0, [self::rect(0, 0, $width, $height)]);
        vkCmdBindVertexBuffers($commands, 0, [$vertexBuffer], [0]);
        vkCmdPushConstants($commands, $this->layout, VK_SHADER_STAGE_VERTEX_BIT, 0, self::PUSH_FRAME, pack('g4', (float) $width, (float) $height, 0.0, 0.0));
    }


    /**
     * The pipelines, by kind, for the current sample count. All share the two
     * shaders, RGBA8 colour, the device's stencil format, the sample count and
     * dynamic viewport and scissor (the render passes are compatible, so one
     * pipeline serves them all):
     *
     *   solid    blending off, colour written
     *   fill     source-over blending, colour written, stencil untouched
     *   cover    as fill, drawn where the stencil is not zero, zeroing it
     *   winding  no colour; front faces count the stencil up, back faces down (NON_ZERO)
     *   invert   no colour; every face inverts the stencil (EVEN_ODD)
     */
    private function pipeline(string $kind): VkPipeline
    {
        if (isset($this->pipelines[$kind])) {
            return $this->pipelines[$kind];
        }
        $stage = function (int $bit, VkShaderModule $module): VkPipelineShaderStageCreateInfo {
            $s = new VkPipelineShaderStageCreateInfo;
            $s->stage = $bit;
            $s->module = $module;
            $s->pName = 'main';

            return $s;
        };
        $binding = new VkVertexInputBindingDescription;
        $binding->stride = 8;
        $binding->inputRate = VK_VERTEX_INPUT_RATE_VERTEX;
        $attribute = new VkVertexInputAttributeDescription;
        $attribute->format = VK_FORMAT_R32G32_SFLOAT;
        $vertexInput = new VkPipelineVertexInputStateCreateInfo;
        $vertexInput->pVertexBindingDescriptions = [$binding];
        $vertexInput->pVertexAttributeDescriptions = [$attribute];
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
        $multisample->rasterizationSamples = $this->samples === 4 ? VK_SAMPLE_COUNT_4_BIT : VK_SAMPLE_COUNT_1_BIT;

        $depthStencil = new VkPipelineDepthStencilStateCreateInfo;
        $stencil = in_array($kind, ['winding', 'invert', 'cover'], true);
        $depthStencil->stencilTestEnable = $stencil ? VK_TRUE : VK_FALSE;
        if ($stencil) {
            [$front, $back, $compare] = match ($kind) {
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
        $attachment->colorWriteMask = in_array($kind, ['winding', 'invert'], true) ? 0 : self::WRITE_ALL;
        if (in_array($kind, ['fill', 'cover'], true)) {
            $attachment->blendEnable = VK_TRUE;
            $attachment->srcColorBlendFactor = VK_BLEND_FACTOR_SRC_ALPHA;
            $attachment->dstColorBlendFactor = VK_BLEND_FACTOR_ONE_MINUS_SRC_ALPHA;
            $attachment->colorBlendOp = VK_BLEND_OP_ADD;
            $attachment->srcAlphaBlendFactor = VK_BLEND_FACTOR_ONE;
            $attachment->dstAlphaBlendFactor = VK_BLEND_FACTOR_ONE_MINUS_SRC_ALPHA;
            $attachment->alphaBlendOp = VK_BLEND_OP_ADD;
        }
        $blend = new VkPipelineColorBlendStateCreateInfo;
        $blend->pAttachments = [$attachment];
        $dynamic = new VkPipelineDynamicStateCreateInfo;
        $dynamic->pDynamicStates = [VK_DYNAMIC_STATE_VIEWPORT, VK_DYNAMIC_STATE_SCISSOR];

        $info = new VkGraphicsPipelineCreateInfo;
        $info->pStages = [$stage(VK_SHADER_STAGE_VERTEX_BIT, $this->vertexModule), $stage(VK_SHADER_STAGE_FRAGMENT_BIT, $this->fragmentModule)];
        $info->pVertexInputState = $vertexInput;
        $info->pInputAssemblyState = $assembly;
        $info->pViewportState = $viewport;
        $info->pRasterizationState = $raster;
        $info->pMultisampleState = $multisample;
        $info->pDepthStencilState = $depthStencil;
        $info->pColorBlendState = $blend;
        $info->pDynamicState = $dynamic;
        $info->layout = $this->layout;
        $info->renderPass = $this->renderPass(VK_ATTACHMENT_LOAD_OP_LOAD);
        self::ok(vkCreateGraphicsPipelines($this->device, null, [$info], null, $pipelines), 'vkCreateGraphicsPipelines');

        return $this->pipelines[$kind] = $pipelines[0];
    }

    /** A combined-image-sampler write of $view (read in GENERAL, nearest, clamped) into $set. */
    private function descriptor(VkDescriptorSet $set, VkImageView $view): VkWriteDescriptorSet
    {
        $image = new VkDescriptorImageInfo;
        $image->sampler = $this->sampler;
        $image->imageView = $view;
        $image->imageLayout = VK_IMAGE_LAYOUT_GENERAL;
        $write = new VkWriteDescriptorSet;
        $write->dstSet = $set;
        $write->descriptorType = VK_DESCRIPTOR_TYPE_COMBINED_IMAGE_SAMPLER;
        $write->pImageInfo = [$image];

        return $write;
    }

    private static function rect(int $x, int $y, int $width, int $height): VkRect2D
    {
        $rect = new VkRect2D;
        $rect->offset->x = $x;
        $rect->offset->y = $y;
        $rect->extent->width = $width;
        $rect->extent->height = $height;

        return $rect;
    }

    /** The pipeline of this kind and the Paint push constants, with the target's flags: HDR, half-float source. */
    private function paint(VkCommandBuffer $commands, string $kind, int $mode, int $rgba, int $opacity = 255, int $linear = 0, array $shape = [0.0, 0.0, 0.0, 0.0], array $band = [0.0, 0.0, 0.0, 0.0], array $abcd = [0.0, 0.0, 0.0, 0.0], array $efwh = [0.0, 0.0, 0.0, 0.0], bool $floatSource = false): void
    {
        vkCmdBindPipeline($commands, VK_PIPELINE_BIND_POINT_GRAPHICS, $this->pipeline($kind));
        $flags = ($this->space->isHdr() ? 1 : 0) | ($floatSource ? 2 : 0);
        vkCmdPushConstants($commands, $this->layout, VK_SHADER_STAGE_FRAGMENT_BIT, self::PUSH_FRAME, self::PUSH_PAINT, self::paintBytes($mode, $rgba, $opacity, $linear, $flags, $shape, $band, $abcd, $efwh));
    }

    /**
     * The Paint block: mode, opacity, linear, flags; the colour; then four vec4s,
     * as the fragment shader declares them (std430 after the vertex stage's 16 bytes).
     *
     * @param  array{float, float, float, float}  $shape
     * @param  array{float, float, float, float}  $band
     * @param  array{float, float, float, float}  $abcd
     * @param  array{float, float, float, float}  $efwh
     */
    private static function paintBytes(int $mode, int $rgba, int $opacity, int $linear, int $flags, array $shape, array $band, array $abcd, array $efwh): string
    {
        return pack('V4', $mode, $opacity, $linear, $flags)
            .pack('V4', ($rgba >> 24) & 0xFF, ($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF)
            .pack('g4', ...$shape).pack('g4', ...$band).pack('g4', ...$abcd).pack('g4', ...$efwh);
    }

    /** Six vertices: x0,y0 x1,y0 x1,y1 x0,y0 x1,y1 x0,y1, as Lowering lays a quad. */
    private static function quad(float $x0, float $y0, float $x1, float $y1): string
    {
        return pack('g12', $x0, $y0, $x1, $y0, $x1, $y1, $x0, $y0, $x1, $y1, $x0, $y1);
    }

    /**
     * Take the surface a canvas lent: a VkSurfaceKHR made against this device's
     * instance, or a CAMetalLayer this device turns into one. The swapchain is
     * built at once and let go when the canvas releases the surface.
     */
    public function adopt(LentSurface $surface): void
    {
        $this->live();
        if (! is_null($this->vkSurface) || $this->exporting) {
            throw new DrawingException('vulkan: this device already has a surface.');
        }
        switch ($surface->kind) {
            case SurfaceKind::VULKAN_SURFACE:
                $vkSurface = VkSurfaceKHR::fromPointer($surface->handle('surface'));
                $owned = false;
                break;

            case SurfaceKind::METAL_LAYER:
                if (! function_exists('vkCreateMetalSurfaceEXT')) {
                    throw new DrawingException('vulkan: this build of ext-vulkan has no Metal surfaces.');
                }
                if (class_exists(CAMetalLayer::class) && class_exists(CGSize::class)) {
                    // A layer with no view has no size: the surface's size is the swapchain's.
                    [$w, $h] = $surface->size();
                    CAMetalLayer::fromPointer($surface->handle('layer'))->setDrawableSize(new CGSize((float) $w, (float) $h));
                }
                $info = new VkMetalSurfaceCreateInfoEXT;
                $info->pLayer = $surface->handle('layer');
                self::ok(vkCreateMetalSurfaceEXT($this->instance, $info, null, $vkSurface), 'vkCreateMetalSurfaceEXT');
                $owned = true;
                break;

            case SurfaceKind::DMABUF:
                $this->adoptExport($surface);

                return;

            default:
                throw new DrawingException("vulkan does not present into a {$surface->kind->value} surface.");
        }
        $this->vkSurface = $vkSurface;
        $this->ownsSurface = $owned;
        $this->lent = $surface;
        try {
            vkGetPhysicalDeviceSurfaceSupportKHR($this->physical, $this->family, $this->vkSurface, $supported);
            if ($supported !== true) {
                throw new DrawingException('vulkan: the graphics queue cannot present into this surface.');
            }
            $this->makePresentSync();
            $this->history = new DamageHistory(self::RING + 1);
            $this->swapchain($surface);
        } catch (\Throwable $failure) {
            $this->dropSurface();

            throw $failure;
        }
        $surface->onRelease(fn () => $this->dropSurface());
    }

    /** A fence, an acquire semaphore and a show descriptor set for each present slot. */
    private function makePresentSync(): void
    {
        for ($i = count($this->presentFences); $i < self::RING; $i++) {
            self::ok(vkCreateFence($this->device, new VkFenceCreateInfo, null, $fence), 'vkCreateFence');
            $this->presentFences[] = $fence;
            self::ok(vkCreateSemaphore($this->device, new VkSemaphoreCreateInfo, null, $acquired), 'vkCreateSemaphore');
            $this->acquiredRing[] = $acquired;
        }
        if (is_null($this->showPool)) {
            $size = new VkDescriptorPoolSize;
            $size->type = VK_DESCRIPTOR_TYPE_COMBINED_IMAGE_SAMPLER;
            $size->descriptorCount = self::RING;
            $poolInfo = new VkDescriptorPoolCreateInfo;
            $poolInfo->maxSets = self::RING;
            $poolInfo->pPoolSizes = [$size];
            self::ok(vkCreateDescriptorPool($this->device, $poolInfo, null, $pool), 'vkCreateDescriptorPool');
            $this->showPool = $pool;
            $allocate = new VkDescriptorSetAllocateInfo;
            $allocate->descriptorPool = $pool;
            $allocate->pSetLayouts = array_fill(0, self::RING, $this->setLayout);
            self::ok(vkAllocateDescriptorSets($this->device, $allocate, $sets), 'vkAllocateDescriptorSets');
            $this->showSets = array_values($sets);
        }
        $this->presentPending = [false, false, false];
        $this->presentSlot = 0;
    }

    /**
     * Draw the target into the surface's next swapchain image with the show
     * pass (presentRect() at the swapchain's size, the surface's filter, the
     * bars black, encoded for the swapchain) and present it with its damage
     * and frame number. Never blocks: false when the slot's last present has
     * not finished, when a FIFO swapchain is a frame behind the display, when
     * no image is free, or when the swapchain no longer fit and was rebuilt.
     */
    public function present(LentSurface $surface): bool
    {
        $this->live();
        if ($surface->released()) {
            return false;
        }
        if ($surface->kind === SurfaceKind::DMABUF) {
            return $this->presentExport();
        }
        if (is_null($this->vkSurface)) {
            throw new DrawingException('vulkan: adopt() a surface before present().');
        }
        $framebuffer = ($this->framebuffer?->get()) ?? throw new DrawingException('vulkan: target() comes before present().');

        $slot = $this->presentSlot;
        if ($this->presentPending[$slot]) {
            $status = vkGetFenceStatus($this->device, $this->presentFences[$slot]);
            if ($status === VK_NOT_READY) {
                return false;
            }
            self::ok($status, 'vkGetFenceStatus');
            self::ok(vkResetFences($this->device, [$this->presentFences[$slot]]), 'vkResetFences');
            $this->presentPending[$slot] = false;
        }
        // A swapchain that no longer fits, or none while the surface has no area (a minimised window): rebuilt, or false until it can be.
        if (($this->outdated || is_null($this->swapchain)) && ! $this->swapchain($surface)) {
            return false;
        }
        if ($this->displayBehind()) {
            return false;
        }

        $acquired = $this->acquiredRing[$slot];
        $result = vkAcquireNextImageKHR($this->device, $this->swapchain, 0, $acquired, null, $index);
        if ($result === VK_NOT_READY || $result === VK_TIMEOUT) {
            return false;
        }
        if ($result === VK_ERROR_OUT_OF_DATE_KHR) {
            // Nothing was acquired: the semaphore is unsignalled and the swapchain is rebuilt at the surface's size.
            $this->swapchain($surface);

            return false;
        }
        if ($result !== VK_SUCCESS && $result !== VK_SUBOPTIMAL_KHR) {
            self::ok($result, 'vkAcquireNextImageKHR');
        }
        // SUBOPTIMAL acquired an image like SUCCESS did: it is presented, and the swapchain rebuilt before the next.
        $this->outdated = $result === VK_SUBOPTIMAL_KHR;

        [$width, $height] = $this->extent;
        [$across, $down] = $this->swapExtent;
        [$filter, $fit] = $surface->scaling();
        $placed = $fit->rect($across, $down, $width, $height);
        [$transform, $white, $nits] = $this->transform();
        $set = $this->showSets[$slot];
        vkUpdateDescriptorSets($this->device, [$this->descriptor($set, $this->resolvedView)], []);

        $commands = $this->presentRing[$slot];
        self::ok(vkResetCommandBuffer($commands, 0), 'vkResetCommandBuffer');
        $begin = new VkCommandBufferBeginInfo;
        $begin->flags = VK_COMMAND_BUFFER_USAGE_ONE_TIME_SUBMIT_BIT;
        self::ok(vkBeginCommandBuffer($commands, $begin), 'vkBeginCommandBuffer');
        $pass = new VkRenderPassBeginInfo;
        $pass->renderPass = $this->showPass($this->swapFormat);
        $pass->framebuffer = $this->swapFramebuffers[$index];
        $pass->renderArea->extent->width = $across;
        $pass->renderArea->extent->height = $down;
        vkCmdBeginRenderPass($commands, $pass, VK_SUBPASS_CONTENTS_INLINE);
        $viewport = new VkViewport;
        [$viewport->x, $viewport->y, $viewport->width, $viewport->height, $viewport->minDepth, $viewport->maxDepth] = [0.0, 0.0, (float) $across, (float) $down, 0.0, 1.0];
        vkCmdSetViewport($commands, 0, [$viewport]);
        vkCmdSetScissor($commands, 0, [self::rect(0, 0, $across, $down)]);
        vkCmdBindPipeline($commands, VK_PIPELINE_BIND_POINT_GRAPHICS, $this->showPipeline($this->swapFormat));
        vkCmdBindDescriptorSets($commands, VK_PIPELINE_BIND_POINT_GRAPHICS, $this->layout, 0, [$set], []);
        $show = pack('g4', (float) $placed->x, (float) $placed->y, (float) $placed->width, (float) $placed->height)
            .pack('g4', (float) $width, (float) $height, $white, $nits)
            .pack('V4', $filter === ScaleFilter::Linear ? 1 : 0, $transform, 0, 0);
        vkCmdPushConstants($commands, $this->layout, VK_SHADER_STAGE_FRAGMENT_BIT, self::PUSH_FRAME, self::PUSH_SHOW, $show);
        vkCmdDraw($commands, 3, 1, 0, 0);
        vkCmdEndRenderPass($commands);
        self::ok(vkEndCommandBuffer($commands), 'vkEndCommandBuffer');

        $submit = new VkSubmitInfo;
        $submit->pWaitSemaphores = [$acquired];
        $submit->pWaitDstStageMask = [VK_PIPELINE_STAGE_COLOR_ATTACHMENT_OUTPUT_BIT];
        $submit->pCommandBuffers = [$commands];
        $submit->pSignalSemaphores = [$this->rendered[$index]];
        $result = vkQueueSubmit($this->queue, [$submit], $this->presentFences[$slot]);
        if ($result !== VK_SUCCESS) {
            // The acquire's semaphore stays signalled and its image acquired: both go with a rebuilt swapchain.
            $this->outdated = true;
            vkDeviceWaitIdle($this->device);
            vkDestroySemaphore($this->device, $acquired, null);
            self::ok(vkCreateSemaphore($this->device, new VkSemaphoreCreateInfo, null, $fresh), 'vkCreateSemaphore');
            $this->acquiredRing[$slot] = $fresh;
            self::ok($result, 'vkQueueSubmit');
        }
        $this->presentPending[$slot] = true;
        $this->presentSlot = ($slot + 1) % $this->inFlight;

        $present = new VkPresentInfoKHR;
        $present->pWaitSemaphores = [$this->rendered[$index]];
        $present->pSwapchains = [$this->swapchain];
        $present->pImageIndices = [$index];
        $this->submitted++;
        $chain = [];
        if (in_array(VK_KHR_INCREMENTAL_PRESENT_EXTENSION_NAME, $this->extensions, true)) {
            $rects = [];
            foreach ($this->history->record($framebuffer->damage(), $width, $height, $placed, $across, $down) as $changed) {
                $rect = new VkRectLayerKHR;
                [$rect->offset->x, $rect->offset->y, $rect->extent->width, $rect->extent->height] = [$changed->x, $changed->y, $changed->width, $changed->height];
                $rects[] = $rect;
            }
            $region = new VkPresentRegionKHR;
            $region->pRectangles = $rects;
            $regions = new VkPresentRegionsKHR;
            $regions->pRegions = [$region];
            $chain[] = $regions;
        }
        if ($this->presentWaits) {
            $id = new VkPresentIdKHR;
            $id->pPresentIds = [$this->submitted];
            $chain[] = $id;
        }
        for ($i = count($chain) - 1; $i > 0; $i--) {
            $chain[$i - 1]->pNext = $chain[$i];
        }
        $present->pNext = $chain[0] ?? null;
        $result = vkQueuePresentKHR($this->queue, $present);
        if ($result === VK_ERROR_OUT_OF_DATE_KHR || $result === VK_SUBOPTIMAL_KHR) {
            $this->outdated = true;
        } else {
            self::ok($result, 'vkQueuePresentKHR');
        }

        return true;
    }

    public function vsyncModes(): array
    {
        if ($this->exporting) {
            return [VSync::On];
        }
        $named = [VK_PRESENT_MODE_FIFO_KHR => VSync::On, VK_PRESENT_MODE_FIFO_RELAXED_KHR => VSync::Adaptive, VK_PRESENT_MODE_MAILBOX_KHR => VSync::Mailbox, VK_PRESENT_MODE_IMMEDIATE_KHR => VSync::Off];
        $modes = [];
        foreach ($named as $mode => $vsync) {
            if (in_array($mode, $this->presentModes, true)) {
                $modes[] = $vsync;
            }
        }

        return $modes;
    }

    /** A DMABUF export is paced by the toolkit that shows it: nothing to switch. */
    public function applyVsync(VSync $vsync): void
    {
        if ($this->exporting) {
            return;
        }
        $mode = match ($vsync) {
            VSync::On => VK_PRESENT_MODE_FIFO_KHR,
            VSync::Adaptive => VK_PRESENT_MODE_FIFO_RELAXED_KHR,
            VSync::Mailbox => VK_PRESENT_MODE_MAILBOX_KHR,
            VSync::Off => VK_PRESENT_MODE_IMMEDIATE_KHR,
        };
        if (! in_array($mode, $this->presentModes, true)) {
            throw new DrawingException("vulkan: this surface does not present with vsync {$vsync->value}.");
        }
        if ($this->presentMode !== $mode) {
            $this->presentMode = $mode;
            $this->outdated = true;
        }
    }

    /** The present mode the swapchain is built with: the window's, or null for the device's own. */
    public function presentMode(): ?int
    {
        return $this->presentMode;
    }

    public function submitted(): int
    {
        return $this->submitted;
    }

    public function lastPresented(): ?PresentTiming
    {
        if (! $this->presentWaits || is_null($this->swapchain)) {
            return $this->shown;
        }
        $known = $this->shown?->frame ?? 0;
        for ($frame = $this->submitted; $frame > $known && $frame >= $this->swapchainFirst; $frame--) {
            $result = vkWaitForPresentKHR($this->device, $this->swapchain, $frame, 0);
            if ($result === VK_SUCCESS) {
                // Vulkan says which present was shown, not when.
                $this->shown = new PresentTiming($frame);
                break;
            }
            if ($result !== VK_TIMEOUT) {
                break;
            }
        }

        return $this->shown;
    }

    public function waitPresented(int $frame, int $timeoutNs): bool
    {
        if (! $this->presentWaits) {
            throw new DrawingException('vulkan: this device has no '.VK_KHR_PRESENT_WAIT_EXTENSION_NAME.': presents() is null for it.');
        }
        if ($frame > $this->submitted || is_null($this->swapchain)) {
            return false;
        }
        if ($frame < $this->swapchainFirst) {
            return true;                                               // carried by a swapchain the device waited idle before replacing
        }
        $result = vkWaitForPresentKHR($this->device, $this->swapchain, $frame, $timeoutNs);
        if ($result === VK_SUCCESS) {
            if (($this->shown?->frame ?? 0) < $frame) {
                $this->shown = new PresentTiming($frame);
            }

            return true;
        }

        return false;
    }

    /**
     * Whether a FIFO present would wait on the display: with present wait,
     * while setFramesInFlight() presents of this swapchain are unshown. A
     * hidden window's compositor sends no frame callbacks, so its FIFO
     * present would block until it shows again; the present is skipped instead.
     */
    private function displayBehind(): bool
    {
        if (! $this->presentWaits || ! in_array($this->modeInUse, [VK_PRESENT_MODE_FIFO_KHR, VK_PRESENT_MODE_FIFO_RELAXED_KHR], true)) {
            return false;
        }
        $shown = max($this->lastPresented()?->frame ?? 0, $this->swapchainFirst - 1);

        return $this->submitted - $shown >= $this->inFlight;
    }

    /**
     * @internal The show pass's encoding for the current target: the
     * transform code (0 as stored, 1 extended linear, 2 PQ, 3 decoded from
     * sRGB), the SDR white level, and the nits an HDR10 value of 1.0 shows at.
     *
     * @return array{int, float, float}
     */
    public function transform(): array
    {
        $level = $this->lent?->hdr()?->sdrWhiteLevel ?? 1.0;
        $white = $level > 0.0 ? $level : 1.0;

        return [$this->transformCode(), $white, max(203.0, 80.0 * $white)];
    }

    /** The transform code: extended linear × white, HDR10 as PQ, an sRGB-format swapchain decoded for, SDR as stored. */
    private function transformCode(): int
    {
        return match (true) {
            in_array($this->space, [ColorSpace::ExtendedLinearSrgb, ColorSpace::ExtendedLinearDisplayP3], true) => 1,
            $this->space === ColorSpace::Hdr10Pq => 2,
            in_array($this->swapFormat, [VK_FORMAT_B8G8R8A8_SRGB, VK_FORMAT_R8G8B8A8_SRGB, VK_FORMAT_A8B8G8R8_SRGB_PACK32], true) => 3,
            default => 0,
        };
    }


    /**
     * The device extensions a dmabuf export needs that this device lacks; [] when it has both.
     *
     * @return list<string>
     */
    private function missingExport(): array
    {
        return array_values(array_diff([VK_KHR_EXTERNAL_MEMORY_FD_EXTENSION_NAME, VK_EXT_EXTERNAL_MEMORY_DMA_BUF_EXTENSION_NAME], $this->extensions));
    }

    /**
     * A DMABUF surface: the device exports, the canvas imports. From now on every target gets a
     * pool of linear export images beside it; the canvas's handle (its display) is not the
     * device's to use.
     *
     * @throws DrawingException When the device lacks the export extensions.
     */
    private function adoptExport(LentSurface $surface): void
    {
        $missing = $this->missingExport();
        if ($missing !== []) {
            throw new DrawingException('vulkan: dmabuf presenting needs '.VK_KHR_EXTERNAL_MEMORY_FD_EXTENSION_NAME.' and '.VK_EXT_EXTERNAL_MEMORY_DMA_BUF_EXTENSION_NAME.'; this device lacks '.implode(' and ', $missing).'.');
        }
        $this->makePresentSync();
        $this->exporting = true;
        $this->lent = $surface;
        if ($this->resolved !== null) {
            $this->makeExports();
        }
        $surface->onRelease(fn () => $this->dropExport());
    }

    /** The surface is gone: no more export; descriptors an importer still holds stay open until it hands them back. */
    private function dropExport(): void
    {
        if ($this->released) {
            return;
        }
        $this->finish();
        $this->presented();
        $this->exporting = false;
        $this->lent = null;
        $this->dropExports();
    }

    /**
     * EXPORT_POOL linear R8G8B8A8_UNORM images the size of the target, each one's memory
     * allocated dedicated to it and exported as a dmabuf: the blit destinations of present(),
     * taken in turn while the importer holds the others. GENERAL once, as the target is.
     */
    private function makeExports(): void
    {
        for ($i = count($this->exports); $i < self::EXPORT_POOL; $i++) {
            $this->exports[] = $this->makeExport();
        }
    }

    /**
     * One export image.
     *
     * @return array{image: VkImage, memory: VkDeviceMemory, size: int, dmabuf: array{fd: int, width: int, height: int, stride: int, offset: int, fourcc: int, modifier: int}, held: bool}
     */
    private function makeExport(): array
    {
        [$width, $height] = $this->extent;
        $external = new VkExternalMemoryImageCreateInfo;
        $external->handleTypes = VK_EXTERNAL_MEMORY_HANDLE_TYPE_DMA_BUF_BIT_EXT;
        $info = new VkImageCreateInfo;
        $info->pNext = $external;
        $info->imageType = VK_IMAGE_TYPE_2D;
        $info->format = VK_FORMAT_R8G8B8A8_UNORM;
        $info->extent->width = $width;
        $info->extent->height = $height;
        $info->extent->depth = 1;
        $info->mipLevels = 1;
        $info->arrayLayers = 1;
        $info->samples = VK_SAMPLE_COUNT_1_BIT;
        $info->tiling = VK_IMAGE_TILING_LINEAR;
        $info->usage = VK_IMAGE_USAGE_TRANSFER_DST_BIT;
        self::ok(vkCreateImage($this->device, $info, null, $image), 'vkCreateImage');
        $memory = null;
        $fd = null;
        try {
            vkGetImageMemoryRequirements($this->device, $image, $requirements);
            // Dedicated: Vulkan 1.1 allows it for any image, and some drivers require it for an exported one.
            $dedicated = new VkMemoryDedicatedAllocateInfo;
            $dedicated->image = $image;
            $export = new VkExportMemoryAllocateInfo;
            $export->handleTypes = VK_EXTERNAL_MEMORY_HANDLE_TYPE_DMA_BUF_BIT_EXT;
            $export->pNext = $dedicated;
            $allocate = new VkMemoryAllocateInfo;
            $allocate->pNext = $export;
            $allocate->allocationSize = $requirements->size;
            $allocate->memoryTypeIndex = $this->memoryType($requirements->memoryTypeBits, 0, VK_MEMORY_PROPERTY_DEVICE_LOCAL_BIT | VK_MEMORY_PROPERTY_HOST_VISIBLE_BIT);
            self::ok(vkAllocateMemory($this->device, $allocate, null, $memory), 'vkAllocateMemory');
            self::ok(vkBindImageMemory($this->device, $image, $memory, 0), 'vkBindImageMemory');
            $fdInfo = new VkMemoryGetFdInfoKHR;
            $fdInfo->memory = $memory;
            $fdInfo->handleType = VK_EXTERNAL_MEMORY_HANDLE_TYPE_DMA_BUF_BIT_EXT;
            self::ok(vkGetMemoryFdKHR($this->device, $fdInfo, $fd), 'vkGetMemoryFdKHR');
            $subresource = new VkImageSubresource;
            $subresource->aspectMask = VK_IMAGE_ASPECT_COLOR_BIT;
            vkGetImageSubresourceLayout($this->device, $image, $subresource, $layout);
            $this->once(fn (VkCommandBuffer $commands) => vkCmdPipelineBarrier(
                $commands, VK_PIPELINE_STAGE_TOP_OF_PIPE_BIT, VK_PIPELINE_STAGE_TRANSFER_BIT, 0, [], [],
                [self::barrier($image, VK_IMAGE_LAYOUT_UNDEFINED, VK_IMAGE_LAYOUT_GENERAL, 0, VK_ACCESS_TRANSFER_WRITE_BIT)],
            ));
        } catch (\Throwable $failure) {
            vkDestroyImage($this->device, $image, null);
            is_null($memory) || vkFreeMemory($this->device, $memory, null);
            is_null($fd) || self::closeFd($fd);

            throw $failure;
        }

        return ['image' => $image, 'memory' => $memory, 'size' => $requirements->size, 'held' => false, 'dmabuf' => [
            'fd' => $fd,
            'width' => $width,
            'height' => $height,
            'stride' => $layout->rowPitch,
            'offset' => $layout->offset,
            'fourcc' => self::DRM_FORMAT_ABGR8888,
            'modifier' => self::DRM_FORMAT_MOD_LINEAR,
        ]];
    }

    /**
     * The export images and their memory go (callers finish() first). A descriptor the importer
     * still holds stays open: the buffer lives on behind it, and the importer may read it again
     * until it hands it back with releaseDmabuf(). The others close.
     */
    private function dropExports(): void
    {
        $exports = $this->exports;
        $this->exports = [];
        $this->shownExport = null;
        foreach ($exports as $export) {
            vkDestroyImage($this->device, $export['image'], null);
            vkFreeMemory($this->device, $export['memory'], null);
            if ($export['held']) {
                $this->heldFds[$export['dmabuf']['fd']] = true;
            } else {
                self::closeFd($export['dmabuf']['fd']);
            }
        }
    }

    /** close() in teardown: Linux closes the descriptor even when close() reports EINTR, so nothing is left to do about a failure. */
    private static function closeFd(int $fd): void
    {
        try {
            vk_close_fd($fd);
        } catch (\ValueError) {
        }
    }

    /** @internal VulkanFramebuffer's dmabuf(): the export the last present wrote, while $framebuffer is the target. */
    public function dmabufOf(VulkanFramebuffer $framebuffer): ?array
    {
        if ($this->released || is_null($this->shownExport) || $framebuffer->image() !== $this->resolved) {
            return null;
        }

        return $this->exports[$this->shownExport]['dmabuf'];
    }

    /**
     * @internal VulkanFramebuffer's releaseDmabuf(): the importer is done with the export it was
     * handed with $fd. One of the pool is free to be written again; one the target or the
     * surface has let go of closes. After release() too: an importer finishes on its own time.
     */
    public function releaseDmabuf(int $fd): void
    {
        if (isset($this->heldFds[$fd])) {
            unset($this->heldFds[$fd]);
            self::closeFd($fd);

            return;
        }
        foreach ($this->exports as $i => $export) {
            if ($export['dmabuf']['fd'] === $fd) {
                $this->exports[$i]['held'] = false;
            }
        }
    }

    /**
     * Blit the target into an export the importer does not hold and wait for it: the canvas
     * builds its texture over the descriptor right after, and the image is held for it until
     * the canvas hands it back. False, copying nothing, while every export is held.
     */
    private function presentExport(): bool
    {
        if (! $this->exporting) {
            throw new DrawingException('vulkan: adopt() a surface before present().');
        }
        ($this->framebuffer?->get()) ?? throw new DrawingException('vulkan: target() comes before present().');
        $free = null;
        foreach ($this->exports as $i => $export) {
            if (! $export['held']) {
                $free = $i;
                break;
            }
        }
        if (is_null($free)) {
            return false;
        }
        $this->presented();
        [$width, $height] = $this->extent;
        $image = $this->exports[$free]['image'];
        $commands = $this->presentRing[0];
        self::ok(vkResetCommandBuffer($commands, 0), 'vkResetCommandBuffer');
        $begin = new VkCommandBufferBeginInfo;
        $begin->flags = VK_COMMAND_BUFFER_USAGE_ONE_TIME_SUBMIT_BIT;
        self::ok(vkBeginCommandBuffer($commands, $begin), 'vkBeginCommandBuffer');
        vkCmdPipelineBarrier($commands, VK_PIPELINE_STAGE_TOP_OF_PIPE_BIT, VK_PIPELINE_STAGE_TRANSFER_BIT, 0, [], [],
            [self::barrier($image, VK_IMAGE_LAYOUT_GENERAL, VK_IMAGE_LAYOUT_GENERAL, VK_ACCESS_MEMORY_READ_BIT, VK_ACCESS_TRANSFER_WRITE_BIT)]);
        $blit = new VkImageBlit;
        $blit->srcSubresource->aspectMask = $blit->dstSubresource->aspectMask = VK_IMAGE_ASPECT_COLOR_BIT;
        $blit->srcSubresource->layerCount = $blit->dstSubresource->layerCount = 1;
        $blit->srcOffsets = $blit->dstOffsets = [self::offset(0, 0, 0), self::offset($width, $height, 1)];
        vkCmdBlitImage($commands, $this->resolved, VK_IMAGE_LAYOUT_GENERAL, $image, VK_IMAGE_LAYOUT_GENERAL, [$blit], VK_FILTER_NEAREST);
        // The blit's writes made available to whatever reads the memory after the fence: the importer, or the host.
        $out = new VkMemoryBarrier;
        $out->srcAccessMask = VK_ACCESS_TRANSFER_WRITE_BIT;
        $out->dstAccessMask = VK_ACCESS_HOST_READ_BIT | VK_ACCESS_MEMORY_READ_BIT;
        vkCmdPipelineBarrier($commands, VK_PIPELINE_STAGE_TRANSFER_BIT, VK_PIPELINE_STAGE_HOST_BIT | VK_PIPELINE_STAGE_BOTTOM_OF_PIPE_BIT, 0, [$out], [], []);
        self::ok(vkEndCommandBuffer($commands), 'vkEndCommandBuffer');
        $submit = new VkSubmitInfo;
        $submit->pCommandBuffers = [$commands];
        self::ok(vkQueueSubmit($this->queue, [$submit], $this->presentFences[0]), 'vkQueueSubmit');
        $this->presentPending[0] = true;
        $this->presented();
        $this->exports[$free]['held'] = true;
        $this->shownExport = $free;

        return true;
    }

    /**
     * @internal For tests: the pixels of the export the last present wrote, rows top-down and
     * tightly packed, read through a mapping of its memory at its row pitch.
     */
    public function exportBytes(): string
    {
        $export = $this->exports[$this->shownExport ?? -1] ?? throw new DrawingException('vulkan: nothing is exported.');
        ['memory' => $memory, 'size' => $size, 'dmabuf' => $dmabuf] = $export;
        $this->finish();
        $this->presented();
        self::ok(vkMapMemory($this->device, $memory, 0, $size, 0, $address), 'vkMapMemory');
        try {
            self::ok(vkInvalidateMappedMemoryRanges($this->device, [$this->range($memory, $size, $size)]), 'vkInvalidateMappedMemoryRanges');
            $rows = '';
            for ($y = 0; $y < $dmabuf['height']; $y++) {
                $rows .= vk_read_mapped($address + $dmabuf['offset'] + $y * $dmabuf['stride'], $dmabuf['width'] * 4);
            }

            return $rows;
        } finally {
            vkUnmapMemory($this->device, $memory);
        }
    }

    /** The swapchain's size in pixels. */
    public function swapchainExtent(): array
    {
        return $this->swapExtent;
    }

    /**
     * (Re)build the swapchain at the surface's current size: B8G8R8A8_UNORM, else
     * R8G8B8A8_UNORM, else the first format that is not sRGB (the show pass writes
     * the target's bytes; for an sRGB image it decodes them first); the window's present mode, or MAILBOX where offered else
     * FIFO; one image more than the minimum or than the frames in flight, within
     * the surface's bounds; colour-attachment usage for the show pass. False, with the old swapchain
     * kept and marked outdated, while the surface has no area.
     */
    private function swapchain(LentSurface $surface): bool
    {
        $vkSurface = $this->vkSurface;
        self::ok(vkDeviceWaitIdle($this->device), 'vkDeviceWaitIdle');
        self::ok(vkGetPhysicalDeviceSurfaceCapabilitiesKHR($this->physical, $vkSurface, $capabilities), 'vkGetPhysicalDeviceSurfaceCapabilitiesKHR');
        self::ok(vkGetPhysicalDeviceSurfaceFormatsKHR($this->physical, $vkSurface, $formats), 'vkGetPhysicalDeviceSurfaceFormatsKHR');
        self::ok(vkGetPhysicalDeviceSurfacePresentModesKHR($this->physical, $vkSurface, $modes), 'vkGetPhysicalDeviceSurfacePresentModesKHR');
        $this->presentModes = array_values($modes);
        if (($capabilities->supportedUsageFlags & VK_IMAGE_USAGE_COLOR_ATTACHMENT_BIT) === 0) {
            throw new DrawingException('vulkan: the surface cannot be drawn into (its images lack colour-attachment usage).');
        }
        $pair = $this->swapFormatFor($this->space, array_map(fn (VkSurfaceFormatKHR $f): array => [$f->format, $f->colorSpace], $formats))
            ?? throw new DrawingException("vulkan: this surface shows no {$this->space->value} swapchain.");
        $current = $capabilities->currentExtent;
        if ($current->width === 0xFFFFFFFF || $current->width === 0 || $current->height === 0) {
            [$wanted, $high] = $surface->size();
        } else {
            [$wanted, $high] = [$current->width, $current->height];
        }
        $extent = new VkExtent2D;
        $extent->width = max($capabilities->minImageExtent->width, min($capabilities->maxImageExtent->width, $wanted));
        $extent->height = max($capabilities->minImageExtent->height, min($capabilities->maxImageExtent->height, $high));
        if ($wanted === 0 || $high === 0 || $extent->width === 0 || $extent->height === 0) {
            $this->outdated = true;

            return false;
        }
        $count = max($capabilities->minImageCount + 1, $this->inFlight + 1);
        if ($capabilities->maxImageCount > 0) {
            $count = min($count, $capabilities->maxImageCount);
        }
        $alpha = VK_COMPOSITE_ALPHA_OPAQUE_BIT_KHR;
        if (($capabilities->supportedCompositeAlpha & $alpha) === 0) {
            foreach ([VK_COMPOSITE_ALPHA_INHERIT_BIT_KHR, VK_COMPOSITE_ALPHA_PRE_MULTIPLIED_BIT_KHR, VK_COMPOSITE_ALPHA_POST_MULTIPLIED_BIT_KHR] as $candidate) {
                if (($capabilities->supportedCompositeAlpha & $candidate) !== 0) {
                    $alpha = $candidate;
                    break;
                }
            }
        }

        $info = new VkSwapchainCreateInfoKHR;
        $info->surface = $vkSurface;
        $info->minImageCount = $count;
        [$info->imageFormat, $info->imageColorSpace] = $pair;
        $this->swapFormat = $pair[0];
        $info->imageExtent = $extent;
        $info->imageArrayLayers = 1;
        $info->imageUsage = VK_IMAGE_USAGE_COLOR_ATTACHMENT_BIT;
        $info->imageSharingMode = VK_SHARING_MODE_EXCLUSIVE;
        $info->preTransform = $capabilities->currentTransform;
        $info->compositeAlpha = $alpha;
        $info->presentMode = $this->modeInUse = $this->presentMode ?? (in_array(VK_PRESENT_MODE_MAILBOX_KHR, $modes, true) ? VK_PRESENT_MODE_MAILBOX_KHR : VK_PRESENT_MODE_FIFO_KHR);
        $info->clipped = VK_TRUE;
        $info->oldSwapchain = $this->swapchain;
        $result = vkCreateSwapchainKHR($this->device, $info, null, $swapchain);
        if ($result !== VK_SUCCESS) {
            // The old swapchain stays (retired or not, it is still destroyable) and the next present tries again.
            $this->outdated = true;
            self::ok($result, 'vkCreateSwapchainKHR');
        }
        if (! is_null($this->swapchain)) {
            vkDestroySwapchainKHR($this->device, $this->swapchain, null);
        }
        $this->swapchain = $swapchain;
        self::ok(vkGetSwapchainImagesKHR($this->device, $swapchain, $images), 'vkGetSwapchainImagesKHR');
        $this->swapImages = array_values($images);
        $this->swapExtent = [$extent->width, $extent->height];
        $this->dropSwapTargets();
        foreach ($this->swapImages as $image) {
            $view = $this->view($image, $this->swapFormat, VK_IMAGE_ASPECT_COLOR_BIT);
            $this->swapViews[] = $view;
            $framebufferInfo = new VkFramebufferCreateInfo;
            $framebufferInfo->renderPass = $this->showPass($this->swapFormat);
            $framebufferInfo->pAttachments = [$view];
            $framebufferInfo->width = $extent->width;
            $framebufferInfo->height = $extent->height;
            $framebufferInfo->layers = 1;
            self::ok(vkCreateFramebuffer($this->device, $framebufferInfo, null, $framebuffer), 'vkCreateFramebuffer');
            $this->swapFramebuffers[] = $framebuffer;
        }
        foreach ($this->rendered as $semaphore) {
            vkDestroySemaphore($this->device, $semaphore, null);
        }
        $this->rendered = [];
        foreach ($this->swapImages as $unused) {
            self::ok(vkCreateSemaphore($this->device, new VkSemaphoreCreateInfo, null, $semaphore), 'vkCreateSemaphore');
            $this->rendered[] = $semaphore;
        }
        // A new swapchain's images are all free; the old acquires died with the old one.
        foreach ($this->acquiredRing as $slot => $semaphore) {
            vkDestroySemaphore($this->device, $semaphore, null);
            self::ok(vkCreateSemaphore($this->device, new VkSemaphoreCreateInfo, null, $acquired), 'vkCreateSemaphore');
            $this->acquiredRing[$slot] = $acquired;
        }
        $this->swapchainFirst = $this->submitted + 1;
        $this->sendHdrMetadata();
        $this->outdated = false;

        return true;
    }

    /** The swapchain, its semaphores and fence, and the VkSurfaceKHR when this device made it: the canvas still owns a surface it lent. */
    private function dropSurface(): void
    {
        if ($this->released || is_null($this->vkSurface)) {
            return;
        }
        self::ok(vkDeviceWaitIdle($this->device), 'vkDeviceWaitIdle');
        $this->destroySwapchain();
        $this->lent = null;
    }

    private function destroySwapchain(): void
    {
        $this->dropSwapTargets();
        if (! is_null($this->swapchain)) {
            vkDestroySwapchainKHR($this->device, $this->swapchain, null);
        }
        foreach ($this->rendered as $semaphore) {
            vkDestroySemaphore($this->device, $semaphore, null);
        }
        foreach ($this->acquiredRing as $semaphore) {
            vkDestroySemaphore($this->device, $semaphore, null);
        }
        foreach ($this->presentFences as $fence) {
            vkDestroyFence($this->device, $fence, null);
        }
        if (! is_null($this->showPool)) {
            vkDestroyDescriptorPool($this->device, $this->showPool, null);
        }
        if ($this->ownsSurface && ! is_null($this->vkSurface)) {
            vkDestroySurfaceKHR($this->instance, $this->vkSurface, null);
        }
        $this->swapchain = $this->vkSurface = $this->showPool = null;
        $this->swapImages = $this->rendered = $this->acquiredRing = $this->presentFences = $this->presentModes = $this->showSets = [];
        $this->presentPending = [false, false, false];
        $this->history = null;
    }

    /** The swapchain images' views and show framebuffers. */
    private function dropSwapTargets(): void
    {
        foreach ($this->swapFramebuffers as $framebuffer) {
            vkDestroyFramebuffer($this->device, $framebuffer, null);
        }
        foreach ($this->swapViews as $view) {
            vkDestroyImageView($this->device, $view, null);
        }
        $this->swapFramebuffers = $this->swapViews = [];
    }

    /** The show pass for a swapchain of $format: one colour attachment, every pixel drawn (nothing loaded), left for presenting. */
    private function showPass(int $format): VkRenderPass
    {
        if (isset($this->showPasses[$format])) {
            return $this->showPasses[$format];
        }
        $attachment = new VkAttachmentDescription;
        $attachment->format = $format;
        $attachment->samples = VK_SAMPLE_COUNT_1_BIT;
        $attachment->loadOp = VK_ATTACHMENT_LOAD_OP_DONT_CARE;
        $attachment->storeOp = VK_ATTACHMENT_STORE_OP_STORE;
        $attachment->stencilLoadOp = VK_ATTACHMENT_LOAD_OP_DONT_CARE;
        $attachment->stencilStoreOp = VK_ATTACHMENT_STORE_OP_DONT_CARE;
        $attachment->initialLayout = VK_IMAGE_LAYOUT_UNDEFINED;
        $attachment->finalLayout = VK_IMAGE_LAYOUT_PRESENT_SRC_KHR;
        $reference = new VkAttachmentReference;
        $reference->attachment = 0;
        $reference->layout = VK_IMAGE_LAYOUT_COLOR_ATTACHMENT_OPTIMAL;
        $subpass = new VkSubpassDescription;
        $subpass->pipelineBindPoint = VK_PIPELINE_BIND_POINT_GRAPHICS;
        $subpass->pColorAttachments = [$reference];
        // The acquire semaphore is waited at colour output: the image's layout change waits for it too.
        $in = new VkSubpassDependency;
        $in->srcSubpass = VK_SUBPASS_EXTERNAL;
        $in->dstSubpass = 0;
        $in->srcStageMask = VK_PIPELINE_STAGE_COLOR_ATTACHMENT_OUTPUT_BIT;
        $in->dstStageMask = VK_PIPELINE_STAGE_COLOR_ATTACHMENT_OUTPUT_BIT;
        $in->srcAccessMask = 0;
        $in->dstAccessMask = VK_ACCESS_COLOR_ATTACHMENT_WRITE_BIT;
        $info = new VkRenderPassCreateInfo;
        $info->pAttachments = [$attachment];
        $info->pSubpasses = [$subpass];
        $info->pDependencies = [$in];
        self::ok(vkCreateRenderPass($this->device, $info, null, $pass), 'vkCreateRenderPass');

        return $this->showPasses[$format] = $pass;
    }

    /** The show pipeline for a swapchain of $format: one triangle, no vertex input, no blending, one sample. */
    private function showPipeline(int $format): VkPipeline
    {
        if (isset($this->showPipelines[$format])) {
            return $this->showPipelines[$format];
        }
        $stage = function (int $bit, VkShaderModule $module): VkPipelineShaderStageCreateInfo {
            $s = new VkPipelineShaderStageCreateInfo;
            $s->stage = $bit;
            $s->module = $module;
            $s->pName = 'main';

            return $s;
        };
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
        $multisample->rasterizationSamples = VK_SAMPLE_COUNT_1_BIT;
        $attachment = new VkPipelineColorBlendAttachmentState;
        $attachment->colorWriteMask = self::WRITE_ALL;
        $blend = new VkPipelineColorBlendStateCreateInfo;
        $blend->pAttachments = [$attachment];
        $dynamic = new VkPipelineDynamicStateCreateInfo;
        $dynamic->pDynamicStates = [VK_DYNAMIC_STATE_VIEWPORT, VK_DYNAMIC_STATE_SCISSOR];

        $info = new VkGraphicsPipelineCreateInfo;
        $info->pStages = [$stage(VK_SHADER_STAGE_VERTEX_BIT, $this->showVertex), $stage(VK_SHADER_STAGE_FRAGMENT_BIT, $this->showFragment)];
        $info->pVertexInputState = new VkPipelineVertexInputStateCreateInfo;
        $info->pInputAssemblyState = $assembly;
        $info->pViewportState = $viewport;
        $info->pRasterizationState = $raster;
        $info->pMultisampleState = $multisample;
        $info->pDepthStencilState = new VkPipelineDepthStencilStateCreateInfo;
        $info->pColorBlendState = $blend;
        $info->pDynamicState = $dynamic;
        $info->layout = $this->layout;
        $info->renderPass = $this->showPass($format);
        self::ok(vkCreateGraphicsPipelines($this->device, null, [$info], null, $pipelines), 'vkCreateGraphicsPipelines');

        return $this->showPipelines[$format] = $pipelines[0];
    }

    private static function offset(int $x, int $y, int $z): VkOffset3D
    {
        $offset = new VkOffset3D;
        $offset->x = $x;
        $offset->y = $y;
        $offset->z = $z;

        return $offset;
    }

    public function release(): void
    {
        if ($this->released || ! isset($this->instance)) {
            return;
        }
        $this->destroyAll();
    }

    /** Physical device, logical device, queue, command objects, shaders, layouts, sampler, blank image. */
    private function openDevice(): void
    {
        self::ok(vkEnumeratePhysicalDevices($this->instance, $devices), 'vkEnumeratePhysicalDevices');
        foreach ($devices ?? [] as $candidate) {
            vkGetPhysicalDeviceProperties($candidate, $properties);
            if ($properties->deviceType !== VK_PHYSICAL_DEVICE_TYPE_CPU) {
                $this->physical = $candidate;
                break;
            }
        }
        if (! isset($this->physical)) {
            throw new DrawingException('vulkan: no GPU device (only the CPU device, or none).');
        }
        $limits = $properties->limits;
        $this->largest = $limits->maxImageDimension2D;
        $this->colorSamples = $limits->framebufferColorSampleCounts;
        $this->stencilSamples = $limits->framebufferStencilSampleCounts;
        $this->atom = max(1, $limits->nonCoherentAtomSize);
        if ($limits->maxPushConstantsSize < self::PUSH_FRAME + self::PUSH_PAINT) {
            throw new DrawingException("vulkan: this device allows {$limits->maxPushConstantsSize} bytes of push constants; the engine needs ".(self::PUSH_FRAME + self::PUSH_PAINT).'.');
        }
        vkGetPhysicalDeviceMemoryProperties($this->physical, $memory);
        $this->memoryTypes = array_values($memory->memoryTypes);

        vkGetPhysicalDeviceQueueFamilyProperties($this->physical, $families);
        foreach ($families as $index => $family) {
            if (($family->queueFlags & VK_QUEUE_GRAPHICS_BIT) !== 0) {
                $this->family = $index;
                break;
            }
        }
        if (! isset($index) || ($families[$this->family]->queueFlags & VK_QUEUE_GRAPHICS_BIT) === 0) {
            throw new DrawingException('vulkan: the device has no graphics queue.');
        }

        vkEnumerateDeviceExtensionProperties($this->physical, null, $available);
        $listed = array_map(fn (VkExtensionProperties $e): string => $e->extensionName, $available ?? []);
        if (! in_array(VK_KHR_SWAPCHAIN_EXTENSION_NAME, $listed, true)) {
            throw new DrawingException('vulkan: the device lacks '.VK_KHR_SWAPCHAIN_EXTENSION_NAME.'.');
        }
        $this->extensions = array_values(array_filter([
            VK_KHR_SWAPCHAIN_EXTENSION_NAME,
            VK_KHR_PORTABILITY_SUBSET_EXTENSION_NAME,
            VK_KHR_EXTERNAL_MEMORY_FD_EXTENSION_NAME,
            VK_EXT_EXTERNAL_MEMORY_DMA_BUF_EXTENSION_NAME,
            VK_EXT_IMAGE_DRM_FORMAT_MODIFIER_EXTENSION_NAME,
            VK_KHR_INCREMENTAL_PRESENT_EXTENSION_NAME,
            VK_EXT_HDR_METADATA_EXTENSION_NAME,
        ], fn (string $name): bool => in_array($name, $listed, true)));
        // Present id and present wait go together, enabled only where the device has both features.
        $create = new VkDeviceCreateInfo;
        if (in_array(VK_KHR_PRESENT_ID_EXTENSION_NAME, $listed, true) && in_array(VK_KHR_PRESENT_WAIT_EXTENSION_NAME, $listed, true)) {
            $id = new VkPhysicalDevicePresentIdFeaturesKHR;
            $wait = new VkPhysicalDevicePresentWaitFeaturesKHR;
            $id->pNext = $wait;
            $features = new VkPhysicalDeviceFeatures2;
            $features->pNext = $id;
            vkGetPhysicalDeviceFeatures2($this->physical, $features);
            if ($id->presentId && $wait->presentWait) {
                $this->presentWaits = true;
                array_push($this->extensions, VK_KHR_PRESENT_ID_EXTENSION_NAME, VK_KHR_PRESENT_WAIT_EXTENSION_NAME);
                $enableId = new VkPhysicalDevicePresentIdFeaturesKHR;
                $enableId->presentId = true;
                $enableWait = new VkPhysicalDevicePresentWaitFeaturesKHR;
                $enableWait->presentWait = true;
                $enableId->pNext = $enableWait;
                $create->pNext = $enableId;
            }
        }
        $queueInfo = new VkDeviceQueueCreateInfo;
        $queueInfo->queueFamilyIndex = $this->family;
        $queueInfo->pQueuePriorities = [1.0];
        $create->pQueueCreateInfos = [$queueInfo];
        $create->enabledExtensionNames = $this->extensions;
        self::ok(vkCreateDevice($this->physical, $create, null, $device), 'vkCreateDevice');
        $this->device = $device;
        vkGetDeviceQueue($this->device, $this->family, 0, $queue);
        $this->queue = $queue;

        foreach ([VK_FORMAT_D24_UNORM_S8_UINT, VK_FORMAT_D32_SFLOAT_S8_UINT] as $format) {
            vkGetPhysicalDeviceFormatProperties($this->physical, $format, $properties);
            if (($properties->optimalTilingFeatures & VK_FORMAT_FEATURE_DEPTH_STENCIL_ATTACHMENT_BIT) !== 0) {
                $this->stencilFormat = $format;
                break;
            }
        }
        if ($this->stencilFormat === 0) {
            throw new DrawingException('vulkan: the device has no depth-stencil format with a stencil.');
        }

        $poolInfo = new VkCommandPoolCreateInfo;
        $poolInfo->flags = VK_COMMAND_POOL_CREATE_RESET_COMMAND_BUFFER_BIT;
        $poolInfo->queueFamilyIndex = $this->family;
        self::ok(vkCreateCommandPool($this->device, $poolInfo, null, $pool), 'vkCreateCommandPool');
        $this->pool = $pool;
        $allocate = new VkCommandBufferAllocateInfo;
        $allocate->commandPool = $this->pool;
        $allocate->level = VK_COMMAND_BUFFER_LEVEL_PRIMARY;
        $allocate->commandBufferCount = 1 + 2 * self::RING;
        self::ok(vkAllocateCommandBuffers($this->device, $allocate, $buffers), 'vkAllocateCommandBuffers');
        $this->onceCommands = $buffers[0];
        $this->frameRing = array_slice($buffers, 1, self::RING);
        $this->presentRing = array_slice($buffers, 1 + self::RING, self::RING);
        for ($i = 0; $i < self::RING; $i++) {
            self::ok(vkCreateFence($this->device, new VkFenceCreateInfo, null, $fence), 'vkCreateFence');
            $this->frameFences[] = $fence;
        }

        $this->vertexModule = $this->module(self::SHADERS.'/venusian.vert.spv');
        $this->fragmentModule = $this->module(self::SHADERS.'/venusian.frag.spv');
        $this->showVertex = $this->module(self::SHADERS.'/show.vert.spv');
        $this->showFragment = $this->module(self::SHADERS.'/show.frag.spv');

        $binding = new VkDescriptorSetLayoutBinding;
        $binding->descriptorType = VK_DESCRIPTOR_TYPE_COMBINED_IMAGE_SAMPLER;
        $binding->descriptorCount = 1;
        $binding->stageFlags = VK_SHADER_STAGE_FRAGMENT_BIT;
        $setInfo = new VkDescriptorSetLayoutCreateInfo;
        $setInfo->pBindings = [$binding];
        self::ok(vkCreateDescriptorSetLayout($this->device, $setInfo, null, $setLayout), 'vkCreateDescriptorSetLayout');
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
        $layoutInfo->pSetLayouts = [$this->setLayout];
        $layoutInfo->pPushConstantRanges = [$frame, $paint];
        self::ok(vkCreatePipelineLayout($this->device, $layoutInfo, null, $layout), 'vkCreatePipelineLayout');
        $this->layout = $layout;

        $samplerInfo = new VkSamplerCreateInfo;
        $samplerInfo->magFilter = VK_FILTER_NEAREST;
        $samplerInfo->minFilter = VK_FILTER_NEAREST;
        $samplerInfo->mipmapMode = VK_SAMPLER_MIPMAP_MODE_NEAREST;
        $samplerInfo->addressModeU = $samplerInfo->addressModeV = $samplerInfo->addressModeW = VK_SAMPLER_ADDRESS_MODE_CLAMP_TO_EDGE;
        self::ok(vkCreateSampler($this->device, $samplerInfo, null, $sampler), 'vkCreateSampler');
        $this->sampler = $sampler;

        // A 1 × 1 transparent image on set 0 for every draw that samples nothing.
        [$this->blankImage, $this->blankMemory] = $this->image(VK_FORMAT_R8G8B8A8_UNORM, 1, 1, VK_SAMPLE_COUNT_1_BIT, VK_IMAGE_USAGE_SAMPLED_BIT | VK_IMAGE_USAGE_TRANSFER_DST_BIT);
        $this->blankView = $this->view($this->blankImage, VK_FORMAT_R8G8B8A8_UNORM, VK_IMAGE_ASPECT_COLOR_BIT);
        $blank = $this->blankImage;
        [$staging, $stagingMemory] = $this->stage("\0\0\0\0", VK_BUFFER_USAGE_TRANSFER_SRC_BIT);
        try {
            $this->once(function (VkCommandBuffer $commands) use ($blank, $staging): void {
                vkCmdPipelineBarrier($commands, VK_PIPELINE_STAGE_TOP_OF_PIPE_BIT, VK_PIPELINE_STAGE_TRANSFER_BIT, 0, [], [],
                    [self::barrier($blank, VK_IMAGE_LAYOUT_UNDEFINED, VK_IMAGE_LAYOUT_GENERAL, 0, VK_ACCESS_TRANSFER_WRITE_BIT)]);
                vkCmdCopyBufferToImage($commands, $staging, $blank, VK_IMAGE_LAYOUT_GENERAL, [self::copyOf(new Region(0, 0, 1, 1))]);
                vkCmdPipelineBarrier($commands, VK_PIPELINE_STAGE_TRANSFER_BIT, VK_PIPELINE_STAGE_ALL_COMMANDS_BIT, 0, [], [],
                    [self::barrier($blank, VK_IMAGE_LAYOUT_GENERAL, VK_IMAGE_LAYOUT_GENERAL, VK_ACCESS_TRANSFER_WRITE_BIT, VK_ACCESS_SHADER_READ_BIT)]);
            });
        } finally {
            vkDestroyBuffer($this->device, $staging, null);
            vkFreeMemory($this->device, $stagingMemory, null);
        }
    }

    /** Everything the device made, in reverse; tolerates a constructor that stopped half way. */
    private function destroyAll(): void
    {
        $this->released = true;
        if (isset($this->device)) {
            vkDeviceWaitIdle($this->device);
            foreach ($this->retiredRing as $slot => $retired) {
                $this->retiredRing[$slot] = [];
                foreach (array_reverse($retired) as $destroy) {
                    $destroy();
                }
            }
            $this->destroySwapchain();
            $this->exporting = false;
            $this->releaseTarget();
            // Let go while still holding a surface: the swapchain and the exports are gone, so the
            // canvas takes its surface back now, while this device's instance still exists.
            if (! is_null($this->lent) && ! $this->lent->released()) {
                $lent = $this->lent;
                $this->lent = null;
                $lent->release();
            }
            foreach ($this->owned as [$image, $view, $memory]) {
                vkDestroyImageView($this->device, $view, null);
                vkDestroyImage($this->device, $image, null);
                vkFreeMemory($this->device, $memory, null);
            }
            $this->owned = [];
            if (isset($this->blankView)) {
                vkDestroyImageView($this->device, $this->blankView, null);
                vkDestroyImage($this->device, $this->blankImage, null);
                vkFreeMemory($this->device, $this->blankMemory, null);
            }
            isset($this->sampler) && vkDestroySampler($this->device, $this->sampler, null);
            isset($this->layout) && vkDestroyPipelineLayout($this->device, $this->layout, null);
            isset($this->setLayout) && vkDestroyDescriptorSetLayout($this->device, $this->setLayout, null);
            isset($this->vertexModule) && vkDestroyShaderModule($this->device, $this->vertexModule, null);
            isset($this->fragmentModule) && vkDestroyShaderModule($this->device, $this->fragmentModule, null);
            foreach ($this->frameFences as $fence) {
                vkDestroyFence($this->device, $fence, null);
            }
            $this->frameFences = [];
            foreach ($this->showPipelines as $pipeline) {
                vkDestroyPipeline($this->device, $pipeline, null);
            }
            foreach ($this->showPasses as $pass) {
                vkDestroyRenderPass($this->device, $pass, null);
            }
            $this->showPipelines = $this->showPasses = [];
            isset($this->showVertex) && vkDestroyShaderModule($this->device, $this->showVertex, null);
            isset($this->showFragment) && vkDestroyShaderModule($this->device, $this->showFragment, null);
            isset($this->pool) && vkDestroyCommandPool($this->device, $this->pool, null);
            vkDestroyDevice($this->device, null);
        }
        vkDestroyInstance($this->instance, null);
    }

    /** The target's attachments, framebuffers, passes and pipelines; the resolved image is its VulkanFramebuffer's. */
    private function releaseTarget(): void
    {
        $this->finish();
        $this->framebuffer = null;
        $this->resolved = $this->resolvedView = null;
        foreach ($this->pipelines as $pipeline) {
            vkDestroyPipeline($this->device, $pipeline, null);
        }
        foreach ($this->framebuffers as $framebuffer) {
            vkDestroyFramebuffer($this->device, $framebuffer, null);
        }
        foreach ($this->passes as $pass) {
            vkDestroyRenderPass($this->device, $pass, null);
        }
        $this->pipelines = $this->framebuffers = $this->passes = [];
        $this->dropExports();
        foreach ($this->attachments as [$image, $view, $memory]) {
            vkDestroyImageView($this->device, $view, null);
            vkDestroyImage($this->device, $image, null);
            vkFreeMemory($this->device, $memory, null);
        }
        $this->attachments = [];
    }

    private function live(): void
    {
        if ($this->released) {
            throw new DrawingException('vulkan: this device was released.');
        }
    }

    /** @throws DrawingException When $width × $height is past the largest image this device makes. */
    private function fits(int $width, int $height): void
    {
        if ($width > $this->largest || $height > $this->largest) {
            throw new DrawingException("vulkan: {$width} × {$height} is past the largest image this device makes, {$this->largest} × {$this->largest}.");
        }
    }

    /** @throws DrawingException Naming $call and the code when it did not succeed. */
    private static function ok(int $result, string $call): int
    {
        if ($result !== VK_SUCCESS) {
            throw new DrawingException("vulkan: {$call} failed with VkResult {$result}.");
        }

        return $result;
    }

    private function module(string $path): VkShaderModule
    {
        $info = new VkShaderModuleCreateInfo;
        $info->code = file_get_contents($path) ?: throw new DrawingException("vulkan: {$path} could not be read.");
        self::ok(vkCreateShaderModule($this->device, $info, null, $module), 'vkCreateShaderModule');

        return $module;
    }

    /**
     * First memory type in $typeBits with every $required bit, one that also has
     * every $preferred bit first when there is one.
     */
    private function memoryType(int $typeBits, int $required, int $preferred = 0): int
    {
        $fallback = null;
        foreach ($this->memoryTypes as $index => $type) {
            if (($typeBits & (1 << $index)) === 0 || ($type->propertyFlags & $required) !== $required) {
                continue;
            }
            if (($type->propertyFlags & $preferred) === $preferred) {
                return $index;
            }
            $fallback ??= $index;
        }

        return $fallback ?? throw new DrawingException('vulkan: no memory type fits the request.');
    }

    private function allocate(VkMemoryRequirements $requirements, int $required, int $preferred = 0): VkDeviceMemory
    {
        $allocate = new VkMemoryAllocateInfo;
        $allocate->allocationSize = $requirements->size;
        $allocate->memoryTypeIndex = $this->memoryType($requirements->memoryTypeBits, $required, $preferred);
        self::ok(vkAllocateMemory($this->device, $allocate, null, $memory), 'vkAllocateMemory');
        if (($required & VK_MEMORY_PROPERTY_HOST_VISIBLE_BIT) !== 0) {
            $this->coherent = ($this->memoryTypes[$allocate->memoryTypeIndex]->propertyFlags & VK_MEMORY_PROPERTY_HOST_COHERENT_BIT) !== 0;
        }

        return $memory;
    }

    /** @return array{VkImage, VkDeviceMemory} A 2D optimal-tiling image, device-local where there is such memory. */
    private function image(int $format, int $width, int $height, int $samples, int $usage): array
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
        $info->usage = $usage;
        self::ok(vkCreateImage($this->device, $info, null, $image), 'vkCreateImage');
        try {
            vkGetImageMemoryRequirements($this->device, $image, $requirements);
            $memory = $this->allocate($requirements, 0, VK_MEMORY_PROPERTY_DEVICE_LOCAL_BIT);
            self::ok(vkBindImageMemory($this->device, $image, $memory, 0), 'vkBindImageMemory');
        } catch (\Throwable $failure) {
            vkDestroyImage($this->device, $image, null);

            throw $failure;
        }

        return [$image, $memory];
    }

    private function view(VkImage $image, int $format, int $aspect): VkImageView
    {
        $info = new VkImageViewCreateInfo;
        $info->image = $image;
        $info->viewType = VK_IMAGE_VIEW_TYPE_2D;
        $info->format = $format;
        $info->subresourceRange->aspectMask = $aspect;
        $info->subresourceRange->levelCount = 1;
        $info->subresourceRange->layerCount = 1;
        self::ok(vkCreateImageView($this->device, $info, null, $view), 'vkCreateImageView');

        return $view;
    }

    /** @return array{VkBuffer, VkDeviceMemory, int} A host-visible buffer of $size, coherent memory preferred; its memory's size. */
    private function hostBuffer(int $size, int $usage): array
    {
        $info = new VkBufferCreateInfo;
        $info->size = $size;
        $info->usage = $usage;
        self::ok(vkCreateBuffer($this->device, $info, null, $buffer), 'vkCreateBuffer');
        try {
            vkGetBufferMemoryRequirements($this->device, $buffer, $requirements);
            $memory = $this->allocate($requirements, VK_MEMORY_PROPERTY_HOST_VISIBLE_BIT, VK_MEMORY_PROPERTY_HOST_COHERENT_BIT);
            self::ok(vkBindBufferMemory($this->device, $buffer, $memory, 0), 'vkBindBufferMemory');
        } catch (\Throwable $failure) {
            vkDestroyBuffer($this->device, $buffer, null);

            throw $failure;
        }

        return [$buffer, $memory, $requirements->size];
    }

    /** @return array{VkBuffer, VkDeviceMemory, int} A host-visible buffer holding $bytes, flushed. */
    private function stage(string $bytes, int $usage): array
    {
        [$buffer, $memory, $allocated] = $this->hostBuffer(strlen($bytes), $usage);
        try {
            self::ok(vkMapMemory($this->device, $memory, 0, $allocated, 0, $address), 'vkMapMemory');
            try {
                vk_write_mapped($address, $bytes);
                self::ok(vkFlushMappedMemoryRanges($this->device, [$this->range($memory, strlen($bytes), $allocated)]), 'vkFlushMappedMemoryRanges');
            } finally {
                vkUnmapMemory($this->device, $memory);
            }
        } catch (\Throwable $failure) {
            vkDestroyBuffer($this->device, $buffer, null);
            vkFreeMemory($this->device, $memory, null);

            throw $failure;
        }

        return [$buffer, $memory, $allocated];
    }

    /** $size rounded up to nonCoherentAtomSize, no further than the allocation. */
    private function atomRound(int $size): int
    {
        return intdiv($size + $this->atom - 1, $this->atom) * $this->atom;
    }

    private function range(VkDeviceMemory $memory, int $size, int $allocated): VkMappedMemoryRange
    {
        $range = new VkMappedMemoryRange;
        $range->memory = $memory;
        $range->offset = 0;
        $range->size = min($this->atomRound($size), $allocated);

        return $range;
    }

    /** Record with $record into the one-time command buffer, submit, and wait for the queue. */
    private function once(Closure $record): void
    {
        self::ok(vkResetCommandBuffer($this->onceCommands, 0), 'vkResetCommandBuffer');
        $begin = new VkCommandBufferBeginInfo;
        $begin->flags = VK_COMMAND_BUFFER_USAGE_ONE_TIME_SUBMIT_BIT;
        self::ok(vkBeginCommandBuffer($this->onceCommands, $begin), 'vkBeginCommandBuffer');
        $record($this->onceCommands);
        self::ok(vkEndCommandBuffer($this->onceCommands), 'vkEndCommandBuffer');
        $submit = new VkSubmitInfo;
        $submit->pCommandBuffers = [$this->onceCommands];
        self::ok(vkQueueSubmit($this->queue, [$submit], null), 'vkQueueSubmit');
        self::ok(vkQueueWaitIdle($this->queue), 'vkQueueWaitIdle');
    }

    private static function barrier(VkImage $image, int $old, int $new, int $source, int $destination): VkImageMemoryBarrier
    {
        $barrier = new VkImageMemoryBarrier;
        $barrier->srcAccessMask = $source;
        $barrier->dstAccessMask = $destination;
        $barrier->oldLayout = $old;
        $barrier->newLayout = $new;
        $barrier->srcQueueFamilyIndex = VK_QUEUE_FAMILY_IGNORED;
        $barrier->dstQueueFamilyIndex = VK_QUEUE_FAMILY_IGNORED;
        $barrier->image = $image;
        $barrier->subresourceRange->aspectMask = VK_IMAGE_ASPECT_COLOR_BIT;
        $barrier->subresourceRange->levelCount = 1;
        $barrier->subresourceRange->layerCount = 1;

        return $barrier;
    }

    /** A copy of $region of a colour image to or from a tightly packed buffer. */
    private static function copyOf(Region $region): VkBufferImageCopy
    {
        $copy = new VkBufferImageCopy;
        $copy->imageSubresource->aspectMask = VK_IMAGE_ASPECT_COLOR_BIT;
        $copy->imageSubresource->layerCount = 1;
        $copy->imageOffset->x = $region->x;
        $copy->imageOffset->y = $region->y;
        $copy->imageExtent->width = $region->width;
        $copy->imageExtent->height = $region->height;
        $copy->imageExtent->depth = 1;

        return $copy;
    }

    /**
     * The render pass for the current sample count, with the colour attachment's
     * load op as asked. Four samples: [0] 4× colour, [1] 4× depth-stencil, [2] the
     * resolved image as the resolve attachment (not on the restore pass, which
     * samples it). One sample: [0] the resolved image,
     * [1] depth-stencil. The resolved image is GENERAL in and out; the 4× colour
     * keeps its contents between frames (LOAD) so a partial frame draws over them.
     * Both passes' compatible clones (CLEAR, LOAD, DONT_CARE) share one pipeline.
     */
    private function renderPass(int $colorLoadOp): VkRenderPass
    {
        if (isset($this->passes[$colorLoadOp])) {
            return $this->passes[$colorLoadOp];
        }
        $attachment = function (int $format, int $samples, int $load, int $store, int $stencilLoad, int $stencilStore, int $initial, int $final): VkAttachmentDescription {
            $a = new VkAttachmentDescription;
            $a->format = $format;
            $a->samples = $samples;
            $a->loadOp = $load;
            $a->storeOp = $store;
            $a->stencilLoadOp = $stencilLoad;
            $a->stencilStoreOp = $stencilStore;
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
        $stencil = fn (int $samples): VkAttachmentDescription => $attachment(
            $this->stencilFormat, $samples, VK_ATTACHMENT_LOAD_OP_CLEAR, VK_ATTACHMENT_STORE_OP_DONT_CARE,
            VK_ATTACHMENT_LOAD_OP_CLEAR, VK_ATTACHMENT_STORE_OP_DONT_CARE,
            VK_IMAGE_LAYOUT_UNDEFINED, VK_IMAGE_LAYOUT_DEPTH_STENCIL_ATTACHMENT_OPTIMAL,
        );
        $subpass = new VkSubpassDescription;
        $subpass->pipelineBindPoint = VK_PIPELINE_BIND_POINT_GRAPHICS;
        if ($this->samples === 4) {
            $loading = $colorLoadOp === VK_ATTACHMENT_LOAD_OP_LOAD;
            $attachments = [
                $attachment($this->colorFormat, VK_SAMPLE_COUNT_4_BIT, $colorLoadOp, VK_ATTACHMENT_STORE_OP_STORE,
                    VK_ATTACHMENT_LOAD_OP_DONT_CARE, VK_ATTACHMENT_STORE_OP_DONT_CARE,
                    $loading ? VK_IMAGE_LAYOUT_COLOR_ATTACHMENT_OPTIMAL : VK_IMAGE_LAYOUT_UNDEFINED, VK_IMAGE_LAYOUT_COLOR_ATTACHMENT_OPTIMAL),
                $stencil(VK_SAMPLE_COUNT_4_BIT),
            ];
            $subpass->pColorAttachments = [$reference(0, VK_IMAGE_LAYOUT_COLOR_ATTACHMENT_OPTIMAL)];
            $subpass->pDepthStencilAttachment = $reference(1, VK_IMAGE_LAYOUT_DEPTH_STENCIL_ATTACHMENT_OPTIMAL);
            if (self::resolves($colorLoadOp)) {
                $attachments[] = $attachment($this->colorFormat, VK_SAMPLE_COUNT_1_BIT, VK_ATTACHMENT_LOAD_OP_DONT_CARE, VK_ATTACHMENT_STORE_OP_STORE,
                    VK_ATTACHMENT_LOAD_OP_DONT_CARE, VK_ATTACHMENT_STORE_OP_DONT_CARE,
                    VK_IMAGE_LAYOUT_GENERAL, VK_IMAGE_LAYOUT_GENERAL);
                $subpass->pResolveAttachments = [$reference(2, VK_IMAGE_LAYOUT_GENERAL)];
            }
        } else {
            $attachments = [
                $attachment($this->colorFormat, VK_SAMPLE_COUNT_1_BIT, $colorLoadOp, VK_ATTACHMENT_STORE_OP_STORE,
                    VK_ATTACHMENT_LOAD_OP_DONT_CARE, VK_ATTACHMENT_STORE_OP_DONT_CARE,
                    VK_IMAGE_LAYOUT_GENERAL, VK_IMAGE_LAYOUT_GENERAL),
                $stencil(VK_SAMPLE_COUNT_1_BIT),
            ];
            $subpass->pColorAttachments = [$reference(0, VK_IMAGE_LAYOUT_GENERAL)];
            $subpass->pDepthStencilAttachment = $reference(1, VK_IMAGE_LAYOUT_DEPTH_STENCIL_ATTACHMENT_OPTIMAL);
        }

        $tests = VK_PIPELINE_STAGE_EARLY_FRAGMENT_TESTS_BIT | VK_PIPELINE_STAGE_LATE_FRAGMENT_TESTS_BIT;
        $stencilAccess = VK_ACCESS_DEPTH_STENCIL_ATTACHMENT_READ_BIT | VK_ACCESS_DEPTH_STENCIL_ATTACHMENT_WRITE_BIT;
        $out = new VkSubpassDependency;
        $out->srcSubpass = 0;
        $out->dstSubpass = VK_SUBPASS_EXTERNAL;
        $out->srcStageMask = VK_PIPELINE_STAGE_COLOR_ATTACHMENT_OUTPUT_BIT | $tests;
        $out->dstStageMask = VK_PIPELINE_STAGE_TRANSFER_BIT | VK_PIPELINE_STAGE_FRAGMENT_SHADER_BIT | VK_PIPELINE_STAGE_COLOR_ATTACHMENT_OUTPUT_BIT | $tests;
        $out->srcAccessMask = VK_ACCESS_COLOR_ATTACHMENT_WRITE_BIT | $stencilAccess;
        $out->dstAccessMask = VK_ACCESS_TRANSFER_READ_BIT | VK_ACCESS_SHADER_READ_BIT | VK_ACCESS_COLOR_ATTACHMENT_WRITE_BIT | VK_ACCESS_COLOR_ATTACHMENT_READ_BIT | $stencilAccess;
        $in = new VkSubpassDependency;
        $in->srcSubpass = VK_SUBPASS_EXTERNAL;
        $in->dstSubpass = 0;
        $in->srcStageMask = VK_PIPELINE_STAGE_TRANSFER_BIT | VK_PIPELINE_STAGE_FRAGMENT_SHADER_BIT | VK_PIPELINE_STAGE_COLOR_ATTACHMENT_OUTPUT_BIT | $tests;
        $in->dstStageMask = VK_PIPELINE_STAGE_COLOR_ATTACHMENT_OUTPUT_BIT | $tests;
        $in->srcAccessMask = VK_ACCESS_TRANSFER_WRITE_BIT | VK_ACCESS_SHADER_READ_BIT | VK_ACCESS_COLOR_ATTACHMENT_WRITE_BIT | $stencilAccess;
        $in->dstAccessMask = VK_ACCESS_COLOR_ATTACHMENT_WRITE_BIT | VK_ACCESS_COLOR_ATTACHMENT_READ_BIT | $stencilAccess;

        $info = new VkRenderPassCreateInfo;
        $info->pAttachments = $attachments;
        $info->pSubpasses = [$subpass];
        $info->pDependencies = [$out, $in];
        self::ok(vkCreateRenderPass($this->device, $info, null, $pass), 'vkCreateRenderPass');

        return $this->passes[$colorLoadOp] = $pass;
    }

    /** The framebuffer of the pass with this colour load op: its views in attachment order. */
    private function framebufferFor(int $colorLoadOp): VkFramebuffer
    {
        if (isset($this->framebuffers[$colorLoadOp])) {
            return $this->framebuffers[$colorLoadOp];
        }
        $views = match (true) {
            $this->samples === 1 => [$this->resolvedView, $this->attachments['stencil'][1]],
            self::resolves($colorLoadOp) => [$this->attachments['color'][1], $this->attachments['stencil'][1], $this->resolvedView],
            default => [$this->attachments['color'][1], $this->attachments['stencil'][1]],
        };
        $info = new VkFramebufferCreateInfo;
        $info->renderPass = $this->renderPass($colorLoadOp);
        $info->pAttachments = $views;
        $info->width = $this->extent[0];
        $info->height = $this->extent[1];
        $info->layers = 1;
        self::ok(vkCreateFramebuffer($this->device, $info, null, $framebuffer), 'vkCreateFramebuffer');

        return $this->framebuffers[$colorLoadOp] = $framebuffer;
    }

    /** Begin the pass with this colour load op over the whole target; colour clears to transparent black, stencil to 0. */
    private function beginPass(VkCommandBuffer $commands, int $colorLoadOp): void
    {
        $color = new VkClearValue;
        $color->color = new VkClearColorValue;
        $color->color->float32 = [0.0, 0.0, 0.0, 0.0];
        $depthStencil = new VkClearValue;
        $depthStencil->depthStencil = new VkClearDepthStencilValue;
        $depthStencil->depthStencil->depth = 1.0;
        $depthStencil->depthStencil->stencil = 0;
        $begin = new VkRenderPassBeginInfo;
        $begin->renderPass = $this->renderPass($colorLoadOp);
        $begin->framebuffer = $this->framebufferFor($colorLoadOp);
        $begin->renderArea->extent->width = $this->extent[0];
        $begin->renderArea->extent->height = $this->extent[1];
        $begin->pClearValues = $this->samples === 4 && self::resolves($colorLoadOp) ? [$color, $depthStencil, $color] : [$color, $depthStencil];
        vkCmdBeginRenderPass($commands, $begin, VK_SUBPASS_CONTENTS_INLINE);
    }

    /**
     * At 4×, whether the pass with this colour load op resolves into the target.
     * The restore pass (DONT_CARE) does not: it samples the target, so the
     * target is no attachment of it. A single-subpass pass ignores the resolve
     * attachment for compatibility, so the frame's pipelines serve it too.
     */
    private static function resolves(int $colorLoadOp): bool
    {
        return $colorLoadOp !== VK_ATTACHMENT_LOAD_OP_DONT_CARE;
    }
}
