<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog;

use InvalidArgumentException;

final class AtlasWatchdogCheckRegistry
{
    public const FIELD_WATCHDOG_CHECK_ID_CANNOT_BE_EMPTY_ = 'Watchdog check id cannot be empty.';
    /** @var array<string,AtlasWatchdogCheck> */
    private array $checks = [];

    /**
     * Register a watchdog check plugin.
     *
     * Future ACOS slices should call this from a service provider:
     * app(AtlasWatchdogCheckRegistry::class)->register(app(MyCheck::class));
     */
    public function register(AtlasWatchdogCheck $check): self
    {
        $id = trim($check->id());
        if ($id === '') {
            throw new InvalidArgumentException(self::FIELD_WATCHDOG_CHECK_ID_CANNOT_BE_EMPTY_);
        }
        if (isset($this->checks[$id])) {
            throw new InvalidArgumentException('Watchdog check already registered: '.$id);
        }

        $this->checks[$id] = $check;

        return $this;
    }

    /** @return list<AtlasWatchdogCheck> */
    public function all(): array
    {
        return array_values($this->checks);
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->checks);
    }
}
