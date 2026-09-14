<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Values;

use Jovian\Bindings\Vulkan\Enums\VkColorSpaceKHR;
use Jovian\Bindings\Vulkan\Enums\VkCompositeAlphaFlagBitsKHR;
use Jovian\Bindings\Vulkan\Enums\VkFormat;
use Jovian\Bindings\Vulkan\Enums\VkPresentModeKHR;
use Jovian\Bindings\Vulkan\Structs\VkExtent2D;
use Jovian\Bindings\Vulkan\Structs\VkSurfaceCapabilitiesKHR;
use Jovian\Bindings\Vulkan\Structs\VkSurfaceFormatKHR;
use Jovian\Venusian\Vulkan\Enums\SurfaceSentinel;
use Jovian\Venusian\Vulkan\Exceptions\VulkanDrawingException;

/**
 * Pure pick of swapchain format, extent, image count, present mode, and alpha.
 */
final readonly class SwapchainChoice
{
    public function __construct(
        public VkFormat|int $format,
        public VkColorSpaceKHR|int $colorSpace,
        public int $width,
        public int $height,
        public int $imageCount,
        public VkPresentModeKHR $presentMode,
        public int $compositeAlpha,
        public int $preTransform,
    ) {}

    /**
     * @param  list<VkSurfaceFormatKHR>  $formats
     * @param  list<VkPresentModeKHR|int>  $modes
     */
    public static function pick(
        VkSurfaceCapabilitiesKHR $caps,
        array $formats,
        array $modes,
        int $hostW,
        int $hostH,
    ): self {
        // $modes is part of the pick() contract (what the surface offered).
        // This slice always presents FIFO — Vulkan requires it, and vsync is implied.

        if ($formats === []) {
            throw new VulkanDrawingException('no VkSurfaceFormatKHR offered');
        }

        $chosen = self::chooseFormat($formats);
        [$width, $height] = self::chooseExtent($caps, $hostW, $hostH);

        return new self(
            format: self::asFormat($chosen->format),
            colorSpace: self::asColorSpace($chosen->colorSpace),
            width: $width,
            height: $height,
            imageCount: self::chooseImageCount($caps),
            presentMode: VkPresentModeKHR::FIFO_KHR,
            compositeAlpha: self::chooseCompositeAlpha($caps->supportedCompositeAlpha),
            preTransform: self::intOf($caps->currentTransform),
        );
    }

    /**
     * @param  list<VkSurfaceFormatKHR>  $formats
     */
    private static function chooseFormat(array $formats): VkSurfaceFormatKHR
    {
        foreach ([VkFormat::B8G8R8A8_UNORM, VkFormat::R8G8B8A8_UNORM] as $preferred) {
            foreach ($formats as $format) {
                if (self::intOf($format->format) === $preferred->value) {
                    return $format;
                }
            }
        }

        return $formats[0];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function chooseExtent(VkSurfaceCapabilitiesKHR $caps, int $hostW, int $hostH): array
    {
        $current = $caps->currentExtent;
        $undefined = SurfaceSentinel::EXTENT_UNDEFINED->value;

        // MoltenVK reports 0×0 on a layer that is not yet on screen.
        // 0xFFFFFFFF is the spec's "undefined"; 0 is the same in practice.
        // Once the layer is on a view, currentExtent is bounds × contentsScale
        // (naturalDrawableSizeMVK). A mint we own starts at contentsScale 1,
        // so Retina reports the point size while the twin asked for pixels.
        if (
            ! is_null($current)
            && $current->width !== $undefined
            && $current->height !== $undefined
            && $current->width > 0
            && $current->height > 0
            && ! self::hostIsRetinaMultiple($current->width, $current->height, $hostW, $hostH)
        ) {
            return [$current->width, $current->height];
        }

        return self::clampHost($caps, $hostW, $hostH);
    }

    /**
     * True when the host is a 2× or 3× backing of the reported extent —
     * MoltenVK's point-sized natural extent on a Retina CAMetalLayer.
     */
    public static function hostIsRetinaMultiple(int $currentW, int $currentH, int $hostW, int $hostH): bool
    {
        if ($currentW < 1 || $currentH < 1) {
            return false;
        }

        foreach ([2, 3] as $scale) {
            if (
                abs($hostW - $currentW * $scale) <= 2
                && abs($hostH - $currentH * $scale) <= 2
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function clampHost(VkSurfaceCapabilitiesKHR $caps, int $hostW, int $hostH): array
    {
        $min = $caps->minImageExtent ?? new VkExtent2D(1, 1);
        $max = $caps->maxImageExtent ?? new VkExtent2D($hostW, $hostH);

        return [
            max($min->width, min($hostW, $max->width)),
            max($min->height, min($hostH, $max->height)),
        ];
    }

    private static function chooseImageCount(VkSurfaceCapabilitiesKHR $caps): int
    {
        $count = max($caps->minImageCount, 2);
        if ($caps->maxImageCount !== SurfaceSentinel::IMAGE_COUNT_UNBOUNDED->value) {
            $count = min($count, $caps->maxImageCount);
        }

        return $count;
    }

    private static function chooseCompositeAlpha(int $supported): int
    {
        $opaque = VkCompositeAlphaFlagBitsKHR::OPAQUE_BIT_KHR->value;
        if (($supported & $opaque) === $opaque) {
            return $opaque;
        }

        // Lowest set bit. A zero mask is not a legal VkSurfaceCapabilitiesKHR
        // answer; the caller would have already failed surface query.
        return $supported & -$supported;
    }

    private static function asFormat(VkFormat|int $format): VkFormat|int
    {
        return $format instanceof VkFormat ? $format : (VkFormat::tryFrom($format) ?? $format);
    }

    private static function asColorSpace(VkColorSpaceKHR|int $space): VkColorSpaceKHR|int
    {
        return $space instanceof VkColorSpaceKHR ? $space : (VkColorSpaceKHR::tryFrom($space) ?? $space);
    }

    private static function intOf(mixed $value): int
    {
        return $value instanceof \BackedEnum ? (int) $value->value : (int) $value;
    }
}
