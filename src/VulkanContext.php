<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan;

use Jovian\Bindings\Vulkan\Enums\VkAccessFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkBufferUsageFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkCommandBufferLevel;
use Jovian\Bindings\Vulkan\Enums\VkCommandBufferUsageFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkCommandPoolCreateFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkComponentSwizzle;
use Jovian\Bindings\Vulkan\Enums\VkDescriptorPoolCreateFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkDescriptorType;
use Jovian\Bindings\Vulkan\Enums\VkFilter;
use Jovian\Bindings\Vulkan\Enums\VkFormat;
use Jovian\Bindings\Vulkan\Enums\VkImageAspectFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkImageLayout;
use Jovian\Bindings\Vulkan\Enums\VkImageTiling;
use Jovian\Bindings\Vulkan\Enums\VkImageType;
use Jovian\Bindings\Vulkan\Enums\VkImageUsageFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkImageViewType;
use Jovian\Bindings\Vulkan\Enums\VkInstanceCreateFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkMemoryPropertyFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkPhysicalDeviceType;
use Jovian\Bindings\Vulkan\Enums\VkPipelineStageFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkQueueFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkResult;
use Jovian\Bindings\Vulkan\Enums\VkSampleCountFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkSamplerAddressMode;
use Jovian\Bindings\Vulkan\Enums\VkSamplerMipmapMode;
use Jovian\Bindings\Vulkan\Enums\VkShaderStageFlagBits;
use Jovian\Bindings\Vulkan\Enums\VkSharingMode;
use Jovian\Bindings\Vulkan\Runtime\Bridge;
use Jovian\Bindings\Vulkan\Structs\VkApplicationInfo;
use Jovian\Bindings\Vulkan\Structs\VkBufferCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkBufferImageCopy;
use Jovian\Bindings\Vulkan\Structs\VkCommandBufferAllocateInfo;
use Jovian\Bindings\Vulkan\Structs\VkCommandBufferBeginInfo;
use Jovian\Bindings\Vulkan\Structs\VkCommandPoolCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkComponentMapping;
use Jovian\Bindings\Vulkan\Structs\VkDescriptorImageInfo;
use Jovian\Bindings\Vulkan\Structs\VkDescriptorPoolCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkDescriptorPoolSize;
use Jovian\Bindings\Vulkan\Structs\VkDescriptorSetAllocateInfo;
use Jovian\Bindings\Vulkan\Structs\VkDescriptorSetLayoutBinding;
use Jovian\Bindings\Vulkan\Structs\VkDescriptorSetLayoutCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkDeviceCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkDeviceQueueCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkExtensionProperties;
use Jovian\Bindings\Vulkan\Structs\VkExtent3D;
use Jovian\Bindings\Vulkan\Structs\VkFenceCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkImageCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkImageMemoryBarrier;
use Jovian\Bindings\Vulkan\Structs\VkImageSubresourceLayers;
use Jovian\Bindings\Vulkan\Structs\VkImageSubresourceRange;
use Jovian\Bindings\Vulkan\Structs\VkImageViewCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkInstanceCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkMemoryAllocateInfo;
use Jovian\Bindings\Vulkan\Structs\VkMemoryRequirements;
use Jovian\Bindings\Vulkan\Structs\VkOffset3D;
use Jovian\Bindings\Vulkan\Structs\VkPhysicalDeviceMemoryProperties;
use Jovian\Bindings\Vulkan\Structs\VkPhysicalDeviceProperties;
use Jovian\Bindings\Vulkan\Structs\VkPipelineLayoutCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkPushConstantRange;
use Jovian\Bindings\Vulkan\Structs\VkQueueFamilyProperties;
use Jovian\Bindings\Vulkan\Structs\VkSamplerCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkShaderModuleCreateInfo;
use Jovian\Bindings\Vulkan\Structs\VkSubmitInfo;
use Jovian\Bindings\Vulkan\Structs\VkWriteDescriptorSet;
use Jovian\Bindings\Vulkan\Values\ApiVersion;
use Jovian\Bindings\Vulkan\VK\VK10;
use Jovian\Venusian\Vulkan\Enums\Budget;
use Jovian\Venusian\Vulkan\Enums\QueueFamily;
use Jovian\Venusian\Vulkan\Enums\Sentinel;
use Jovian\Venusian\Vulkan\Enums\Timeout;
use Jovian\Venusian\Vulkan\Exceptions\VulkanDrawingException;
use Jovian\Venusian\Vulkan\Shaders\Spirv;
use Jovian\Venusian\Vulkan\Support\Blocks;
use Jovian\Venusian\Vulkan\Values\GpuTexture;
use Jovian\Venusian\Vulkan\Values\MemoryTypes;

/**
 * One instance, device, queue, modules, layouts, sampler, descriptor pool,
 * and a 1×1 white placeholder. Cached on VulkanEngine for the process.
 */
final class VulkanContext
{
    public private(set) GpuTexture $placeholder;

    private bool $released = false;

    public function __construct(
        public readonly int $instance,
        public readonly int $physicalDevice,
        public readonly int $device,
        public readonly int $queue,
        public readonly int $queueFamily,
        /** @var list<string> The order-free set a SurfaceHost named; [] is headless. The engine's cache key. */
        public readonly array $instanceExtensions,
        /** @var list<string> What vkCreateInstance enabled: the set plus portability enumeration when listed. */
        public readonly array $enabledInstanceExtensions,
        public readonly ApiVersion $loaderVersion,
        public readonly MemoryTypes $memoryTypes,
        public readonly int $maxTextureSize,
        public readonly int $maxPushConstantsSize,
        public readonly int $vertexModule,
        public readonly int $fragmentModule,
        public readonly int $descriptorSetLayout,
        public readonly int $pipelineLayout,
        public readonly int $sampler,
        public readonly int $descriptorPool,
        public readonly Blocks $blocks,
        private readonly int $uploadPool,
        private readonly int $uploadBuffer,
        private readonly int $uploadFence,
    ) {}

    /**
     * @param  list<string>  $instanceExtensions  every extension the SurfaceHost names; [] boots headless
     */
    public static function boot(array $instanceExtensions): self
    {
        if (! Bridge::load()) {
            throw VulkanDrawingException::loader();
        }

        $wanted = self::extensionSet($instanceExtensions);
        $blocks = new Blocks;
        $loaderVersion = Bridge::version();
        [$instance, $enabled] = self::createInstance($blocks, $wanted);
        [$physicalDevice, $queueFamily, $limits] = self::pickPhysicalDevice($blocks, $instance);
        [$device, $queue] = self::createDevice($blocks, $physicalDevice, $queueFamily, $wanted !== []);

        $memPropsBlock = $blocks->alloc(VkPhysicalDeviceMemoryProperties::size());
        VK10::vkGetPhysicalDeviceMemoryProperties($physicalDevice, $memPropsBlock);
        $memoryTypes = MemoryTypes::fromProperties(VkPhysicalDeviceMemoryProperties::unpack($memPropsBlock));

        $vertexModule = self::createShaderModule($blocks, $device, 'painter.vert.spv');
        $fragmentModule = self::createShaderModule($blocks, $device, 'painter.frag.spv');
        $descriptorSetLayout = self::createDescriptorSetLayout($blocks, $device);
        $pipelineLayout = self::createPipelineLayout($blocks, $device, $descriptorSetLayout);
        $sampler = self::createSampler($blocks, $device);
        $descriptorPool = self::createDescriptorPool($blocks, $device);
        [$uploadPool, $uploadBuffer, $uploadFence] = self::createUploadPath($blocks, $device, $queueFamily);

        $context = new self(
            instance: $instance,
            physicalDevice: $physicalDevice,
            device: $device,
            queue: $queue,
            queueFamily: $queueFamily,
            instanceExtensions: $wanted,
            enabledInstanceExtensions: $enabled,
            loaderVersion: $loaderVersion,
            memoryTypes: $memoryTypes,
            maxTextureSize: $limits['maxTextureSize'],
            maxPushConstantsSize: $limits['maxPushConstantsSize'],
            vertexModule: $vertexModule,
            fragmentModule: $fragmentModule,
            descriptorSetLayout: $descriptorSetLayout,
            pipelineLayout: $pipelineLayout,
            sampler: $sampler,
            descriptorPool: $descriptorPool,
            blocks: $blocks,
            uploadPool: $uploadPool,
            uploadBuffer: $uploadBuffer,
            uploadFence: $uploadFence,
        );
        $context->placeholder = $context->uploadTexture(pack('C4', 255, 255, 255, 255), 1, 1);

        return $context;
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function createBuffer(int $size, int $usage, int $flags): array
    {
        $info = $this->blocks->keep((new VkBufferCreateInfo(
            size: $size,
            usage: $usage,
            sharingMode: VkSharingMode::EXCLUSIVE,
        ))->pack());
        $buffer = $this->blocks->create(
            'vkCreateBuffer',
            fn (int $out) => VK10::vkCreateBuffer($this->device, $info, 0, $out),
        );
        $memory = $this->allocateMemoryForBuffer($buffer, $flags);
        VulkanDrawingException::check(
            VK10::vkBindBufferMemory($this->device, $buffer, $memory, 0),
            'vkBindBufferMemory',
        );

        return [$buffer, $memory];
    }

    public function allocateMemory(int $size, int $typeBits, int $required): int
    {
        $index = $this->memoryTypes->select($typeBits, $required);
        if ($index < 0) {
            throw VulkanDrawingException::noMemoryType();
        }

        $info = $this->blocks->keep((new VkMemoryAllocateInfo(
            allocationSize: $size,
            memoryTypeIndex: $index,
        ))->pack());

        return $this->blocks->create(
            'vkAllocateMemory',
            fn (int $out) => VK10::vkAllocateMemory($this->device, $info, 0, $out),
        );
    }

    public function mapMemory(int $memory): int
    {
        $out = $this->blocks->alloc(Budget::HANDLE_BYTES->value);
        VulkanDrawingException::check(
            VK10::vkMapMemory($this->device, $memory, 0, Sentinel::WHOLE_SIZE->value, 0, $out),
            'vkMapMemory',
        );
        $pointer = Blocks::handleAt($out);
        if ($pointer === 0) {
            throw VulkanDrawingException::allocation();
        }

        return $pointer;
    }

    public function imageBarrier(
        int $commandBuffer,
        int $image,
        VkImageLayout|int $oldLayout,
        VkImageLayout|int $newLayout,
        int $srcStage,
        int $dstStage,
        int $srcAccess,
        int $dstAccess,
    ): void {
        $barrier = $this->blocks->keep((new VkImageMemoryBarrier(
            srcAccessMask: $srcAccess,
            dstAccessMask: $dstAccess,
            oldLayout: $oldLayout,
            newLayout: $newLayout,
            srcQueueFamilyIndex: QueueFamily::IGNORED->value,
            dstQueueFamilyIndex: QueueFamily::IGNORED->value,
            image: $image,
            subresourceRange: self::colorRange(),
        ))->pack());
        VK10::vkCmdPipelineBarrier(
            $commandBuffer,
            $srcStage,
            $dstStage,
            0,
            0,
            0,
            0,
            0,
            1,
            $barrier,
        );
    }

    public function uploadTexture(string $rgba8, int $width, int $height): GpuTexture
    {
        if ($width < 1 || $height < 1 || $width > $this->maxTextureSize || $height > $this->maxTextureSize) {
            throw VulkanDrawingException::textureSize($width, $height);
        }

        $bytes = $width * $height * 4;
        if (strlen($rgba8) < $bytes) {
            throw VulkanDrawingException::textureSize($width, $height);
        }

        $host = VkMemoryPropertyFlagBits::HOST_VISIBLE_BIT->value
            | VkMemoryPropertyFlagBits::HOST_COHERENT_BIT->value;
        [$staging, $stagingMemory] = $this->createBuffer(
            $bytes,
            VkBufferUsageFlagBits::TRANSFER_SRC_BIT->value,
            $host,
        );
        $mapped = $this->mapMemory($stagingMemory);
        Bridge::write($mapped, 0, substr($rgba8, 0, $bytes));
        VK10::vkUnmapMemory($this->device, $stagingMemory);

        $imageInfo = $this->blocks->keep((new VkImageCreateInfo(
            imageType: VkImageType::TYPE_2D,
            format: VkFormat::R8G8B8A8_UNORM,
            extent: new VkExtent3D(width: $width, height: $height, depth: 1),
            mipLevels: 1,
            arrayLayers: 1,
            samples: VkSampleCountFlagBits::COUNT_1_BIT,
            tiling: VkImageTiling::OPTIMAL,
            usage: VkImageUsageFlagBits::SAMPLED_BIT->value | VkImageUsageFlagBits::TRANSFER_DST_BIT->value,
            sharingMode: VkSharingMode::EXCLUSIVE,
            initialLayout: VkImageLayout::UNDEFINED,
        ))->pack());
        $image = $this->blocks->create(
            'vkCreateImage',
            fn (int $out) => VK10::vkCreateImage($this->device, $imageInfo, 0, $out),
        );
        $imageMemory = $this->allocateMemoryForImage($image, VkMemoryPropertyFlagBits::DEVICE_LOCAL_BIT->value);
        VulkanDrawingException::check(
            VK10::vkBindImageMemory($this->device, $image, $imageMemory, 0),
            'vkBindImageMemory',
        );

        $region = $this->blocks->keep((new VkBufferImageCopy(
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
        ))->pack());

        VulkanDrawingException::check(VK10::vkResetCommandBuffer($this->uploadBuffer, 0), 'vkResetCommandBuffer');
        $begin = $this->blocks->keep((new VkCommandBufferBeginInfo(
            flags: VkCommandBufferUsageFlagBits::ONE_TIME_SUBMIT_BIT->value,
        ))->pack());
        VulkanDrawingException::check(VK10::vkBeginCommandBuffer($this->uploadBuffer, $begin), 'vkBeginCommandBuffer');
        $this->imageBarrier(
            $this->uploadBuffer,
            $image,
            VkImageLayout::UNDEFINED,
            VkImageLayout::TRANSFER_DST_OPTIMAL,
            VkPipelineStageFlagBits::TOP_OF_PIPE_BIT->value,
            VkPipelineStageFlagBits::TRANSFER_BIT->value,
            0,
            VkAccessFlagBits::TRANSFER_WRITE_BIT->value,
        );
        VK10::vkCmdCopyBufferToImage(
            $this->uploadBuffer,
            $staging,
            $image,
            VkImageLayout::TRANSFER_DST_OPTIMAL,
            1,
            $region,
        );
        $this->imageBarrier(
            $this->uploadBuffer,
            $image,
            VkImageLayout::TRANSFER_DST_OPTIMAL,
            VkImageLayout::SHADER_READ_ONLY_OPTIMAL,
            VkPipelineStageFlagBits::TRANSFER_BIT->value,
            VkPipelineStageFlagBits::FRAGMENT_SHADER_BIT->value,
            VkAccessFlagBits::TRANSFER_WRITE_BIT->value,
            VkAccessFlagBits::SHADER_READ_BIT->value,
        );
        VulkanDrawingException::check(VK10::vkEndCommandBuffer($this->uploadBuffer), 'vkEndCommandBuffer');

        $submit = $this->blocks->keep((new VkSubmitInfo(
            commandBufferCount: 1,
            pCommandBuffers: $this->blocks->handles([$this->uploadBuffer]),
        ))->pack());
        VulkanDrawingException::check(VK10::vkResetFences($this->device, 1, $this->blocks->handles([$this->uploadFence])), 'vkResetFences');
        VulkanDrawingException::check(
            VK10::vkQueueSubmit($this->queue, 1, $submit, $this->uploadFence),
            'vkQueueSubmit',
        );
        VulkanDrawingException::check(
            VK10::vkWaitForFences($this->device, 1, $this->blocks->handles([$this->uploadFence]), true, Timeout::FOREVER->value),
            'vkWaitForFences',
        );

        VK10::vkDestroyBuffer($this->device, $staging, 0);
        VK10::vkFreeMemory($this->device, $stagingMemory, 0);

        $view = $this->createImageView($image, VkFormat::R8G8B8A8_UNORM);
        $set = $this->allocateTextureSet($view);

        return new GpuTexture($image, $imageMemory, $view, $set);
    }

    public function destroyTexture(GpuTexture $texture): void
    {
        if ($texture->set !== 0) {
            VK10::vkFreeDescriptorSets(
                $this->device,
                $this->descriptorPool,
                1,
                $this->blocks->handles([$texture->set]),
            );
        }
        if ($texture->view !== 0) {
            VK10::vkDestroyImageView($this->device, $texture->view, 0);
        }
        if ($texture->image !== 0) {
            VK10::vkDestroyImage($this->device, $texture->image, 0);
        }
        if ($texture->memory !== 0) {
            VK10::vkFreeMemory($this->device, $texture->memory, 0);
        }
    }

    public function placeholder(): GpuTexture
    {
        return $this->placeholder;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;

        if (isset($this->placeholder)) {
            $this->destroyTexture($this->placeholder);
        }
        VK10::vkDestroyFence($this->device, $this->uploadFence, 0);
        VK10::vkDestroyCommandPool($this->device, $this->uploadPool, 0);
        VK10::vkDestroyDescriptorPool($this->device, $this->descriptorPool, 0);
        VK10::vkDestroySampler($this->device, $this->sampler, 0);
        VK10::vkDestroyPipelineLayout($this->device, $this->pipelineLayout, 0);
        VK10::vkDestroyDescriptorSetLayout($this->device, $this->descriptorSetLayout, 0);
        VK10::vkDestroyShaderModule($this->device, $this->fragmentModule, 0);
        VK10::vkDestroyShaderModule($this->device, $this->vertexModule, 0);
        VK10::vkDestroyDevice($this->device, 0);
        VK10::vkDestroyInstance($this->instance, 0);
        $this->blocks->release();
    }

    private function allocateMemoryForBuffer(int $buffer, int $required): int
    {
        $req = $this->blocks->alloc(VkMemoryRequirements::size());
        VK10::vkGetBufferMemoryRequirements($this->device, $buffer, $req);
        $needs = VkMemoryRequirements::unpack($req);

        return $this->allocateMemory($needs->size, $needs->memoryTypeBits, $required);
    }

    private function allocateMemoryForImage(int $image, int $required): int
    {
        $req = $this->blocks->alloc(VkMemoryRequirements::size());
        VK10::vkGetImageMemoryRequirements($this->device, $image, $req);
        $needs = VkMemoryRequirements::unpack($req);

        return $this->allocateMemory($needs->size, $needs->memoryTypeBits, $required);
    }

    private function createImageView(int $image, VkFormat|int $format): int
    {
        $info = $this->blocks->keep((new VkImageViewCreateInfo(
            image: $image,
            viewType: VkImageViewType::TYPE_2D,
            format: $format,
            components: new VkComponentMapping(
                r: VkComponentSwizzle::IDENTITY,
                g: VkComponentSwizzle::IDENTITY,
                b: VkComponentSwizzle::IDENTITY,
                a: VkComponentSwizzle::IDENTITY,
            ),
            subresourceRange: self::colorRange(),
        ))->pack());

        return $this->blocks->create(
            'vkCreateImageView',
            fn (int $out) => VK10::vkCreateImageView($this->device, $info, 0, $out),
        );
    }

    private function allocateTextureSet(int $view): int
    {
        $layouts = $this->blocks->handles([$this->descriptorSetLayout]);
        $info = $this->blocks->keep((new VkDescriptorSetAllocateInfo(
            descriptorPool: $this->descriptorPool,
            descriptorSetCount: 1,
            pSetLayouts: $layouts,
        ))->pack());
        $out = $this->blocks->alloc(Budget::HANDLE_BYTES->value);
        $code = VulkanDrawingException::code(VK10::vkAllocateDescriptorSets($this->device, $info, $out));
        if ($code === VkResult::ERROR_OUT_OF_POOL_MEMORY->value || $code === VkResult::ERROR_FRAGMENTED_POOL->value) {
            throw VulkanDrawingException::texturesExhausted();
        }
        VulkanDrawingException::check($code, 'vkAllocateDescriptorSets');
        $set = Blocks::handleAt($out);
        if ($set === 0) {
            throw VulkanDrawingException::allocation();
        }

        $imageInfo = $this->blocks->keep((new VkDescriptorImageInfo(
            sampler: $this->sampler,
            imageView: $view,
            imageLayout: VkImageLayout::SHADER_READ_ONLY_OPTIMAL,
        ))->pack());
        $write = $this->blocks->keep((new VkWriteDescriptorSet(
            dstSet: $set,
            dstBinding: 0,
            dstArrayElement: 0,
            descriptorCount: 1,
            descriptorType: VkDescriptorType::COMBINED_IMAGE_SAMPLER,
            pImageInfo: $imageInfo,
        ))->pack());
        VK10::vkUpdateDescriptorSets($this->device, 1, $write, 0, 0);

        return $set;
    }

    private static function colorRange(): VkImageSubresourceRange
    {
        return new VkImageSubresourceRange(
            aspectMask: VkImageAspectFlagBits::COLOR_BIT->value,
            baseMipLevel: 0,
            levelCount: 1,
            baseArrayLayer: 0,
            layerCount: 1,
        );
    }

    /**
     * One order-free key for an instance extension set: unique, sorted.
     *
     * @param  list<string>  $extensions
     * @return list<string>
     */
    public static function extensionSet(array $extensions): array
    {
        $set = array_values(array_unique($extensions));
        sort($set);

        return $set;
    }

    /**
     * @return list<string> Instance extensions the loader lists.
     */
    public static function listedInstanceExtensions(): array
    {
        if (! Bridge::load()) {
            throw VulkanDrawingException::loader();
        }

        $blocks = new Blocks;
        try {
            return self::enumerateInstanceExtensions($blocks);
        } finally {
            $blocks->release();
        }
    }

    /**
     * @param  list<string>  $extensions
     * @return array{0: int, 1: list<string>}
     */
    private static function createInstance(Blocks $blocks, array $extensions): array
    {
        $listed = self::enumerateInstanceExtensions($blocks);
        $wanted = [];
        foreach ($extensions as $extension) {
            if (! in_array($extension, $listed, true)) {
                throw VulkanDrawingException::missingExtension($extension);
            }
            $wanted[] = $extension;
        }
        if (in_array('VK_KHR_portability_enumeration', $listed, true) && ! in_array('VK_KHR_portability_enumeration', $wanted, true)) {
            $wanted[] = 'VK_KHR_portability_enumeration';
        }
        $portability = in_array('VK_KHR_portability_enumeration', $wanted, true);

        $appInfo = $blocks->keep((new VkApplicationInfo(
            pApplicationName: $blocks->cstring('venusian'),
            applicationVersion: 1,
            apiVersion: ApiVersion::make(1, 3, 0)->toPacked(),
        ))->pack());
        $info = $blocks->keep((new VkInstanceCreateInfo(
            flags: $portability ? VkInstanceCreateFlagBits::ENUMERATE_PORTABILITY_BIT_KHR->value : 0,
            pApplicationInfo: $appInfo,
            enabledExtensionCount: count($wanted),
            ppEnabledExtensionNames: $wanted === [] ? 0 : $blocks->cstrings($wanted),
        ))->pack());

        $instance = $blocks->create(
            'vkCreateInstance',
            static fn (int $out) => VK10::vkCreateInstance($info, 0, $out),
        );
        if (! Bridge::loadInstance($instance)) {
            throw VulkanDrawingException::loader();
        }

        return [$instance, $wanted];
    }

    /**
     * @return list<string>
     */
    private static function enumerateInstanceExtensions(Blocks $blocks): array
    {
        $count = $blocks->alloc(Budget::COUNT_BYTES->value);
        VulkanDrawingException::check(
            VK10::vkEnumerateInstanceExtensionProperties('', $count, 0),
            'vkEnumerateInstanceExtensionProperties',
        );
        $n = Blocks::countAt($count);
        if ($n === 0) {
            return [];
        }

        $list = $blocks->alloc($n * VkExtensionProperties::size());
        VulkanDrawingException::check(
            VK10::vkEnumerateInstanceExtensionProperties('', $count, $list),
            'vkEnumerateInstanceExtensionProperties',
        );

        $names = [];
        for ($i = 0; $i < $n; $i++) {
            $names[] = VkExtensionProperties::unpack($list + $i * VkExtensionProperties::size())->extensionName;
        }

        return $names;
    }

    /**
     * @return array{0: int, 1: int, 2: array{maxTextureSize: int, maxPushConstantsSize: int}}
     */
    private static function pickPhysicalDevice(Blocks $blocks, int $instance): array
    {
        $count = $blocks->alloc(Budget::COUNT_BYTES->value);
        VulkanDrawingException::check(
            VK10::vkEnumeratePhysicalDevices($instance, $count, 0),
            'vkEnumeratePhysicalDevices',
        );
        $n = Blocks::countAt($count);
        if ($n === 0) {
            throw VulkanDrawingException::noDevice();
        }

        $list = $blocks->alloc($n * Budget::HANDLE_BYTES->value);
        VulkanDrawingException::check(
            VK10::vkEnumeratePhysicalDevices($instance, $count, $list),
            'vkEnumeratePhysicalDevices',
        );

        $best = 0;
        $bestFamily = -1;
        $bestScore = -1;
        $bestLimits = ['maxTextureSize' => 0, 'maxPushConstantsSize' => 0];
        $propsBlock = $blocks->alloc(VkPhysicalDeviceProperties::size());

        for ($i = 0; $i < $n; $i++) {
            $candidate = Blocks::handleAt($list, $i);
            VK10::vkGetPhysicalDeviceQueueFamilyProperties($candidate, $count, 0);
            $families = Blocks::countAt($count);
            if ($families === 0) {
                continue;
            }
            $familyBlock = $blocks->alloc($families * VkQueueFamilyProperties::size());
            VK10::vkGetPhysicalDeviceQueueFamilyProperties($candidate, $count, $familyBlock);

            $family = -1;
            for ($f = 0; $f < $families; $f++) {
                $qf = VkQueueFamilyProperties::unpack($familyBlock + $f * VkQueueFamilyProperties::size());
                if (($qf->queueFlags & VkQueueFlagBits::GRAPHICS_BIT->value) !== 0 && $qf->queueCount > 0) {
                    $family = $f;
                    break;
                }
            }
            if ($family < 0) {
                continue;
            }

            VK10::vkGetPhysicalDeviceProperties($candidate, $propsBlock);
            $props = VkPhysicalDeviceProperties::unpack($propsBlock);
            $type = $props->deviceType instanceof VkPhysicalDeviceType
                ? $props->deviceType->value
                : (int) $props->deviceType;
            $score = match ($type) {
                VkPhysicalDeviceType::INTEGRATED_GPU->value => 3,
                VkPhysicalDeviceType::DISCRETE_GPU->value => 2,
                VkPhysicalDeviceType::VIRTUAL_GPU->value => 1,
                default => 0,
            };
            if ($score <= $bestScore) {
                continue;
            }

            $best = $candidate;
            $bestFamily = $family;
            $bestScore = $score;
            $bestLimits = [
                'maxTextureSize' => $props->limits?->maxImageDimension2D ?? 0,
                'maxPushConstantsSize' => $props->limits?->maxPushConstantsSize ?? 0,
            ];
        }

        if ($best === 0) {
            throw VulkanDrawingException::noDevice();
        }
        if ($bestLimits['maxPushConstantsSize'] < Budget::PUSH_CONSTANT_BYTES->value) {
            throw VulkanDrawingException::pushConstantsTooSmall($bestLimits['maxPushConstantsSize']);
        }

        return [$best, $bestFamily, $bestLimits];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function createDevice(Blocks $blocks, int $physicalDevice, int $queueFamily, bool $presents): array
    {
        $listed = self::enumerateDeviceExtensions($blocks, $physicalDevice);
        $wanted = [];
        if ($presents) {
            if (! in_array('VK_KHR_swapchain', $listed, true)) {
                throw VulkanDrawingException::missingExtension('VK_KHR_swapchain');
            }
            $wanted[] = 'VK_KHR_swapchain';
        }
        if (in_array('VK_KHR_portability_subset', $listed, true)) {
            $wanted[] = 'VK_KHR_portability_subset';
        }

        $priorities = $blocks->alloc(4);
        Bridge::write($priorities, 0, pack('f', 1.0));
        $queueInfo = $blocks->keep((new VkDeviceQueueCreateInfo(
            queueFamilyIndex: $queueFamily,
            queueCount: 1,
            pQueuePriorities: $priorities,
        ))->pack());
        $info = $blocks->keep((new VkDeviceCreateInfo(
            queueCreateInfoCount: 1,
            pQueueCreateInfos: $queueInfo,
            enabledExtensionCount: count($wanted),
            ppEnabledExtensionNames: $wanted === [] ? 0 : $blocks->cstrings($wanted),
        ))->pack());

        $device = $blocks->create(
            'vkCreateDevice',
            static fn (int $out) => VK10::vkCreateDevice($physicalDevice, $info, 0, $out),
        );
        if (! Bridge::loadDevice($device)) {
            throw VulkanDrawingException::loader();
        }

        $queueOut = $blocks->alloc(Budget::HANDLE_BYTES->value);
        VK10::vkGetDeviceQueue($device, $queueFamily, 0, $queueOut);
        $queue = Blocks::handleAt($queueOut);
        if ($queue === 0) {
            throw VulkanDrawingException::noDevice();
        }

        return [$device, $queue];
    }

    /**
     * @return list<string>
     */
    private static function enumerateDeviceExtensions(Blocks $blocks, int $physicalDevice): array
    {
        $count = $blocks->alloc(Budget::COUNT_BYTES->value);
        VulkanDrawingException::check(
            VK10::vkEnumerateDeviceExtensionProperties($physicalDevice, '', $count, 0),
            'vkEnumerateDeviceExtensionProperties',
        );
        $n = Blocks::countAt($count);
        if ($n === 0) {
            return [];
        }

        $list = $blocks->alloc($n * VkExtensionProperties::size());
        VulkanDrawingException::check(
            VK10::vkEnumerateDeviceExtensionProperties($physicalDevice, '', $count, $list),
            'vkEnumerateDeviceExtensionProperties',
        );

        $names = [];
        for ($i = 0; $i < $n; $i++) {
            $names[] = VkExtensionProperties::unpack($list + $i * VkExtensionProperties::size())->extensionName;
        }

        return $names;
    }

    private static function createShaderModule(Blocks $blocks, int $device, string $name): int
    {
        $code = Spirv::load($name);
        $bytes = $blocks->alloc(strlen($code));
        Bridge::write($bytes, 0, $code);
        $info = $blocks->keep((new VkShaderModuleCreateInfo(
            codeSize: strlen($code),
            pCode: $bytes,
        ))->pack());

        return $blocks->create(
            'vkCreateShaderModule',
            static fn (int $out) => VK10::vkCreateShaderModule($device, $info, 0, $out),
        );
    }

    private static function createDescriptorSetLayout(Blocks $blocks, int $device): int
    {
        $binding = $blocks->keep((new VkDescriptorSetLayoutBinding(
            binding: 0,
            descriptorType: VkDescriptorType::COMBINED_IMAGE_SAMPLER,
            descriptorCount: 1,
            stageFlags: VkShaderStageFlagBits::FRAGMENT_BIT->value,
        ))->pack());
        $info = $blocks->keep((new VkDescriptorSetLayoutCreateInfo(
            bindingCount: 1,
            pBindings: $binding,
        ))->pack());

        return $blocks->create(
            'vkCreateDescriptorSetLayout',
            static fn (int $out) => VK10::vkCreateDescriptorSetLayout($device, $info, 0, $out),
        );
    }

    private static function createPipelineLayout(Blocks $blocks, int $device, int $setLayout): int
    {
        $range = $blocks->keep((new VkPushConstantRange(
            stageFlags: VkShaderStageFlagBits::VERTEX_BIT->value,
            offset: 0,
            size: Budget::PUSH_CONSTANT_BYTES->value,
        ))->pack());
        $info = $blocks->keep((new VkPipelineLayoutCreateInfo(
            setLayoutCount: 1,
            pSetLayouts: $blocks->handles([$setLayout]),
            pushConstantRangeCount: 1,
            pPushConstantRanges: $range,
        ))->pack());

        return $blocks->create(
            'vkCreatePipelineLayout',
            static fn (int $out) => VK10::vkCreatePipelineLayout($device, $info, 0, $out),
        );
    }

    private static function createSampler(Blocks $blocks, int $device): int
    {
        $info = $blocks->keep((new VkSamplerCreateInfo(
            magFilter: VkFilter::LINEAR,
            minFilter: VkFilter::LINEAR,
            mipmapMode: VkSamplerMipmapMode::LINEAR,
            addressModeU: VkSamplerAddressMode::CLAMP_TO_EDGE,
            addressModeV: VkSamplerAddressMode::CLAMP_TO_EDGE,
            addressModeW: VkSamplerAddressMode::CLAMP_TO_EDGE,
        ))->pack());

        return $blocks->create(
            'vkCreateSampler',
            static fn (int $out) => VK10::vkCreateSampler($device, $info, 0, $out),
        );
    }

    private static function createDescriptorPool(Blocks $blocks, int $device): int
    {
        $size = $blocks->keep((new VkDescriptorPoolSize(
            type: VkDescriptorType::COMBINED_IMAGE_SAMPLER,
            descriptorCount: Budget::DESCRIPTOR_SETS->value,
        ))->pack());
        $info = $blocks->keep((new VkDescriptorPoolCreateInfo(
            flags: VkDescriptorPoolCreateFlagBits::FREE_DESCRIPTOR_SET_BIT->value,
            maxSets: Budget::DESCRIPTOR_SETS->value,
            poolSizeCount: 1,
            pPoolSizes: $size,
        ))->pack());

        return $blocks->create(
            'vkCreateDescriptorPool',
            static fn (int $out) => VK10::vkCreateDescriptorPool($device, $info, 0, $out),
        );
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function createUploadPath(Blocks $blocks, int $device, int $queueFamily): array
    {
        $poolInfo = $blocks->keep((new VkCommandPoolCreateInfo(
            flags: VkCommandPoolCreateFlagBits::RESET_COMMAND_BUFFER_BIT->value,
            queueFamilyIndex: $queueFamily,
        ))->pack());
        $pool = $blocks->create(
            'vkCreateCommandPool',
            static fn (int $out) => VK10::vkCreateCommandPool($device, $poolInfo, 0, $out),
        );

        $alloc = $blocks->keep((new VkCommandBufferAllocateInfo(
            commandPool: $pool,
            level: VkCommandBufferLevel::PRIMARY,
            commandBufferCount: 1,
        ))->pack());
        $buffers = $blocks->alloc(Budget::HANDLE_BYTES->value);
        VulkanDrawingException::check(
            VK10::vkAllocateCommandBuffers($device, $alloc, $buffers),
            'vkAllocateCommandBuffers',
        );
        $buffer = Blocks::handleAt($buffers);
        if ($buffer === 0) {
            throw VulkanDrawingException::allocation();
        }

        $fence = $blocks->create(
            'vkCreateFence',
            static fn (int $out) => VK10::vkCreateFence($device, $blocks->keep((new VkFenceCreateInfo)->pack()), 0, $out),
        );

        return [$pool, $buffer, $fence];
    }
}
