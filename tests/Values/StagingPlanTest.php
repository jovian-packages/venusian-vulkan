<?php

declare(strict_types=1);

use Jovian\Venusian\Vulkan\Enums\PainterAttribute;
use Jovian\Venusian\Vulkan\Values\StagingPlan;

it('places at the cursor when the cursor is already 4-aligned', function () {
    $placed = StagingPlan::place(0, PainterAttribute::stride());

    expect($placed->offset)->toBe(0)
        ->and($placed->bytes)->toBe(36)
        ->and($placed->next)->toBe(36);
});

it('aligns the cursor up to 4 bytes for both vertex and index payloads', function () {
    $vertex = StagingPlan::place(1, 36);
    $index = StagingPlan::place($vertex->next, 6);

    expect($vertex->offset)->toBe(4)
        ->and($vertex->next)->toBe(40)
        ->and($index->offset)->toBe(40)
        ->and($index->next)->toBe(46);

    $oddIndex = StagingPlan::place(5, 6);
    expect($oddIndex->offset)->toBe(8)
        ->and($oddIndex->next)->toBe(14);
});

it('reports overflow as max(2 × old, needed)', function () {
    $placed = StagingPlan::place(250, 36);

    expect($placed->offset)->toBe(252)
        ->and($placed->next)->toBe(288)
        ->and(StagingPlan::grownSize(256, $placed->next))->toBe(512)
        ->and(StagingPlan::grownSize(256, 1000))->toBe(1000);
});

it('resets by placing at cursor 0', function () {
    $first = StagingPlan::place(100, 36);
    $reset = StagingPlan::place(0, 36);

    expect($first->offset)->toBe(100)
        ->and($reset->offset)->toBe(0)
        ->and($reset->next)->toBe(36);
});
