<?php

declare(strict_types=1);

namespace Gold\Support;

/**
 * FROZEN gold fixture (AP-815 D1). Do not "fix" — labeled oracle input.
 *
 * A leaf collaborator in its OWN namespace (Gold\Support) so the consumer must
 * `use` it — making the constructor-injection dependency both human-obvious and
 * visible to the legacy extractor (which only emits edges for `use`/`::class`).
 */
final class Logger
{
    public function write(string $message): void
    {
        // no-op: fixture
    }
}
