<?php

declare(strict_types=1);

use Jovian\Venusian\Vulkan\Values\GpuTexture;

it('holds the image, memory, view, and descriptor set handles', function () {
    $texture = new GpuTexture(image: 11, memory: 22, view: 33, set: 44);

    expect($texture->image)->toBe(11)
        ->and($texture->memory)->toBe(22)
        ->and($texture->view)->toBe(33)
        ->and($texture->set)->toBe(44);
});
