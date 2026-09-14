<?php

declare(strict_types=1);

namespace Jovian\Venusian\Vulkan\Values;

use Closure;
use Jovian\Venusian\Vulkan\Exceptions\VulkanDrawingException;

/**
 * Deferred-destruction ledger. retire() now, reap() after the frame fence.
 *
 * A command buffer may still bind a handle that has been retired; destroy
 * runs only once the fence has signalled. retire() during reap() is a
 * programming error — the ledger is not re-entrant.
 */
final class Retirement
{
    /** @var list<Closure(): void> */
    private array $pending = [];

    private bool $reaping = false;

    public function retire(Closure $destroy): void
    {
        if ($this->reaping) {
            throw new VulkanDrawingException('cannot retire while the ledger is being reaped');
        }

        $this->pending[] = $destroy;
    }

    public function reap(): int
    {
        $this->reaping = true;
        $closures = $this->pending;
        $this->pending = [];
        $count = 0;

        try {
            foreach ($closures as $destroy) {
                $destroy();
                $count++;
            }
        } finally {
            $this->reaping = false;
        }

        return $count;
    }

    public function pending(): int
    {
        return count($this->pending);
    }
}
