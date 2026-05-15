<?php

declare(strict_types=1);

namespace App\Modules\Inbox;

/**
 * Initial state — Inbox depends on the Eloquent model directly.
 */
final class InboxFacade
{
    public function recent(): array
    {
        // Leak: would return Eloquent models in production.
        return [];
    }
}
