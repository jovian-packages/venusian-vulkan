<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Support;

use Closure;
use Jovian\Bindings\Vulkan\Runtime\Bridge;
use Jovian\Venusian\Vulkan\Enums\Budget;
use Jovian\Venusian\Vulkan\Exceptions\VulkanDrawingException;

/**
 * Tracked Bridge::alloc ledger. Per-call temporaries and long-lived packs
 * are freed together on release().
 */
final class Blocks
{
    /** @var list<int> */
    private array $blocks = [];

    public function alloc(int $bytes): int
    {
        $ptr = Bridge::alloc($bytes);
        if ($ptr === 0) {
            throw VulkanDrawingException::allocation();
        }
        $this->blocks[] = $ptr;

        return $ptr;
    }

    public function keep(int $packedPtr): int
    {
        if ($packedPtr === 0) {
            throw VulkanDrawingException::allocation();
        }
        $this->blocks[] = $packedPtr;

        return $packedPtr;
    }

    public function cstring(string $value): int
    {
        return $this->keep(Bridge::cstring($value));
    }

    /**
     * @param  list<string>  $names
     */
    public function cstrings(array $names): int
    {
        $pointers = [];
        foreach ($names as $name) {
            $pointers[] = $this->cstring($name);
        }

        return $this->handles($pointers);
    }

    /**
     * @param  list<int>  $handles
     */
    public function handles(array $handles): int
    {
        $width = Budget::HANDLE_BYTES->value;
        $ptr = $this->alloc(max(1, count($handles)) * $width);
        foreach ($handles as $index => $handle) {
            Bridge::write($ptr, $index * $width, pack('P', $handle));
        }

        return $ptr;
    }

    /**
     * @param  list<int>  $values
     */
    public function uint32s(array $values): int
    {
        $width = Budget::COUNT_BYTES->value;
        $ptr = $this->alloc(max(1, count($values)) * $width);
        foreach ($values as $index => $value) {
            Bridge::write($ptr, $index * $width, pack('V', $value));
        }

        return $ptr;
    }

    /**
     * @param  Closure(int): (\Jovian\Bindings\Vulkan\Enums\VkResult|int)  $vkCreate
     */
    public function create(string $call, Closure $vkCreate): int
    {
        $out = $this->alloc(Budget::HANDLE_BYTES->value);
        VulkanDrawingException::check($vkCreate($out), $call);
        $handle = self::handleAt($out);
        if ($handle === 0) {
            throw VulkanDrawingException::allocation();
        }

        return $handle;
    }

    public static function handleAt(int $ptr, int $index = 0): int
    {
        $width = Budget::HANDLE_BYTES->value;
        $bytes = Bridge::read($ptr, $index * $width, $width);
        if (! is_string($bytes) || strlen($bytes) !== $width) {
            throw VulkanDrawingException::allocation();
        }

        return unpack('P', $bytes)[1];
    }

    public static function countAt(int $ptr): int
    {
        $width = Budget::COUNT_BYTES->value;
        $bytes = Bridge::read($ptr, 0, $width);
        if (! is_string($bytes) || strlen($bytes) !== $width) {
            throw VulkanDrawingException::allocation();
        }

        return unpack('V', $bytes)[1];
    }

    public function release(): void
    {
        foreach (array_reverse($this->blocks) as $block) {
            Bridge::free($block);
        }
        $this->blocks = [];
    }
}
