<?php

declare(strict_types=1);

use Jovian\Venusian\Vulkan\Exceptions\VulkanDrawingException;
use Jovian\Venusian\Vulkan\Shaders\Spirv;

it('exposes the SPIR-V magic word', function () {
    expect(Spirv::magic())->toBe(0x07230203);
});

it('loads both painter modules as 4-byte-aligned SPIR-V', function (string $name) {
    $bytes = Spirv::load($name);

    expect(strlen($bytes) % 4)->toBe(0)
        ->and(unpack('V', substr($bytes, 0, 4))[1])->toBe(Spirv::magic())
        ->and(Spirv::path($name))->toBe(realpath(dirname(__DIR__).'/src/Shaders/'.$name) ?: dirname(__DIR__).'/src/Shaders/'.$name);
})->with(['painter.vert.spv', 'painter.frag.spv']);

it('records sha256 hashes in the README that match the committed modules', function () {
    $readme = file_get_contents(dirname(__DIR__).'/src/Shaders/README.md');
    expect($readme)->not->toBeFalse();

    foreach (['painter.vert.spv', 'painter.frag.spv'] as $name) {
        $hash = hash_file('sha256', Spirv::path($name));
        expect($readme)->toContain($hash)
            ->and($readme)->toContain((string) filesize(Spirv::path($name)));
    }
});

it('refuses to load the README as SPIR-V', function () {
    expect(fn () => Spirv::load('README.md'))->toThrow(VulkanDrawingException::class);
});
