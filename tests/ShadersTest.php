<?php

declare(strict_types=1);

use Jovian\Engines\Vulkan\VulkanDevice;

it('ships SPIR-V for both stages carrying its GLSL source', function (string $stage): void {
    $spv = file_get_contents(VulkanDevice::SHADERS."/venusian.{$stage}.spv");
    $glsl = file_get_contents(VulkanDevice::SHADERS."/venusian.{$stage}");

    expect(strlen($spv) % 4)->toBe(0)
        ->and(unpack('V', $spv)[1])->toBe(0x07230203)
        ->and($spv)->toContain('layout(push_constant)')
        ->and($glsl)->toContain('layout(push_constant)')
        ->and($glsl)->not->toContain('layout(set = 3');
})->with(['vert', 'frag']);

it('maps pixel y down, as Vulkan clip space runs', function (): void {
    expect(file_get_contents(VulkanDevice::SHADERS.'/venusian.vert'))->toContain('point.y / push.size.y * 2.0 - 1.0');
});

it('ships the show pass as SPIR-V carrying its GLSL source', function (string $stage): void {
    $spv = file_get_contents(VulkanDevice::SHADERS."/show.{$stage}.spv");
    $glsl = file_get_contents(VulkanDevice::SHADERS."/show.{$stage}");

    expect(unpack('V', $spv)[1])->toBe(0x07230203)
        ->and(str_contains($spv, $glsl))->toBeTrue("show.{$stage}.spv is not compiled from show.{$stage}: glslangValidator -V -g show.{$stage} -o show.{$stage}.spv");
})->with(['vert', 'frag']);
