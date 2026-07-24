<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AtlasAcosRollbackTriggerCheckService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use App\Support\YesNo;

/**
 * ACOS Excellence ROL-01 — read-only rollback trigger check for EVI-01 / WDG-01.
 */
final class AtlasAcosRollbackTriggerCheckCommand extends Command
{
    protected $signature = 'atlas:acos:rollback-triggers
        {--json : Emit canonical JSON}
        {--as-of= : ISO8601 instant for fixtures/tests (default: now UTC)}
        {--simulate= : Trigger id to simulate as fired (tests only)}';

    protected $description = 'ACOS ROL-01 — pre-declared rollback trigger check with watchdog alert surface.';

    public function handle(AtlasAcosRollbackTriggerCheckService $check): int
    {
        $asOf = null;
        $raw = $this->option('as-of');
        if (is_string($raw) && trim($raw) !== '') {
            $asOf = CarbonImmutable::parse(trim($raw))->utc();
        }

        $simulate = $this->option('simulate');
        $simulateId = is_string($simulate) && trim($simulate) !== '' ? trim($simulate) : null;

        $payload = $check->check($asOf, $simulateId);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->components->twoColumnDetail('status', (string) ($payload['status'] ?? '?'));
            $this->components->twoColumnDetail('alert', YesNo::format($payload['alert'] ?? false));
            $this->components->twoColumnDetail('flip_count', (string) ($payload['flip_count'] ?? 0));
            $this->components->twoColumnDetail('alerts', (string) count($payload['alerts'] ?? []));
        }

        return ($payload['alert'] ?? false) ? self::FAILURE : self::SUCCESS;
    }
}
