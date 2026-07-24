<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AtlasOperationalVolumeCheckService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use App\Support\YesNo;

/**
 * ACOS Excellence VOL-01 — read-only operational volume check for WDG-01 / EVI-01.
 *
 * Prerequisite (named, not fixed): GAP-HERMES-01 — see service docblock.
 */
final class AtlasOperationalVolumeCheckCommand extends Command
{
    protected $signature = 'atlas:acos:operational-volume
        {--json : Emit canonical JSON}
        {--as-of= : ISO8601 instant for fixtures/tests (default: now UTC)}';

    protected $description = 'ACOS VOL-01 — volume-real check with pinned thresholds and janela faminta alert.';

    public function handle(AtlasOperationalVolumeCheckService $check): int
    {
        $asOf = null;
        $raw = $this->option('as-of');
        if (is_string($raw) && trim($raw) !== '') {
            $asOf = CarbonImmutable::parse(trim($raw))->utc();
        }

        $payload = $check->check($asOf);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->components->twoColumnDetail('status', (string) ($payload['status'] ?? '?'));
            $this->components->twoColumnDetail('alert', YesNo::format($payload['alert'] ?? false));
            $this->components->twoColumnDetail('dev_count', (string) data_get($payload, 'windows.dev.count', 0));
            $this->components->twoColumnDetail('forge_count', (string) data_get($payload, 'windows.forge.count', 0));
        }

        return ($payload['alert'] ?? false) ? self::FAILURE : self::SUCCESS;
    }
}
