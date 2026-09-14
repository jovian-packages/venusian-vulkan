<?php

declare(strict_types=1);

use Jovian\Venusian\Vulkan\Exceptions\VulkanDrawingException;
use Jovian\Venusian\Vulkan\Values\Retirement;

it('accumulates destroy closures and reaps them once in retirement order', function () {
    $ledger = new Retirement;
    $order = [];

    $ledger->retire(function () use (&$order): void {
        $order[] = 'a';
    });
    $ledger->retire(function () use (&$order): void {
        $order[] = 'b';
    });
    $ledger->retire(function () use (&$order): void {
        $order[] = 'c';
    });

    expect($ledger->pending())->toBe(3);

    $reaped = $ledger->reap();

    expect($reaped)->toBe(3)
        ->and($order)->toBe(['a', 'b', 'c'])
        ->and($ledger->pending())->toBe(0);
});

it('treats a second reap as a no-op', function () {
    $ledger = new Retirement;
    $runs = 0;
    $ledger->retire(function () use (&$runs): void {
        $runs++;
    });

    expect($ledger->reap())->toBe(1)
        ->and($ledger->reap())->toBe(0)
        ->and($runs)->toBe(1)
        ->and($ledger->pending())->toBe(0);
});

it('refuses retire() while reap() is running', function () {
    $ledger = new Retirement;
    $ledger->retire(function () use ($ledger): void {
        $ledger->retire(function (): void {});
    });

    expect(fn () => $ledger->reap())->toThrow(VulkanDrawingException::class)
        ->and($ledger->pending())->toBe(0);
});
