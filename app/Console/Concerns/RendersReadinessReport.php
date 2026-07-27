<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use App\Support\YesNo;

/**
 * Human rendering for the `schema / ok / passed / failed / checks` readiness
 * payload that 15 atlas:ai:* commands share.
 *
 * It existed as 15 copies, and the copies had drifted: 6 rendered the per-check
 * lines and 9 stopped at the counts. Every readiness service emits `checks`, so
 * those 9 were printing "failed: 1" out of 38 and swallowing which one — the
 * operator had to go read the JSON to find out. `atlas:ai:tool-runtime` is in
 * that state today, hiding a failing `seed:default_tools`.
 *
 * So this is not a copy being folded up: the fold is what restores the names.
 * The kept behaviour is the one that shows more, matching the 6 that never lost it.
 *
 * AtlasAiControlPlaneCommand deliberately does NOT use this — its payload is a
 * different shape (status / ready / degraded / missing / components).
 */
trait RendersReadinessReport
{
    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderReadinessReport(array $payload): int
    {
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) ($payload['schema'] ?? ''));
            $this->components->twoColumnDetail('ok', YesNo::trueFalse((bool) ($payload['ok'] ?? false)));
            $this->components->twoColumnDetail('passed', (string) ($payload['summary']['passed'] ?? 0));
            $this->components->twoColumnDetail('failed', (string) ($payload['summary']['failed'] ?? 0));
            foreach ((array) ($payload['checks'] ?? []) as $check) {
                if (! is_array($check)) {
                    continue;
                }
                $this->components->twoColumnDetail((string) ($check['name'] ?? ''), (string) ($check['status'] ?? ''));
            }
        });

        return ($payload['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
