<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopDeliveryDossierService;
use Illuminate\Console\Command;

/**
 * ACDE lever F3 — emit the recent per-delivery SIGNED dossiers (the feature-outcome ledger). Each dossier
 * carries the machine-resolved D2 dimensions + the F2 completeness checklist, HMAC-signed so a consumer can
 * trust it. Per-delivery proof — never a head-to-head (Rivals is dead).
 */
class AtlasLoopDeliveryDossierCommand extends Command
{
    protected $signature = 'atlas:loop:delivery-dossier
        {--limit=20 : how many recent merged deliveries to dossier}
        {--json : canonical JSON output}';

    protected $description = 'Report-only: the recent per-delivery HMAC-signed dossiers (D2 dimensions + F2 checklist), a feature-outcome ledger.';

    public function handle(AtlasLoopDeliveryDossierService $service): int
    {
        $dossiers = $service->recent((int) $this->option('limit'));

        if ($this->option('json')) {
            $this->line((string) json_encode($dossiers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if ($dossiers === []) {
            $this->warn('no signed dossiers (delivery_dossier is OFF — set ATLAS_LOOP_DELIVERY_DOSSIER_ENABLED=true to arm — or there are no merged deliveries yet).');

            return self::SUCCESS;
        }

        $this->info('Per-delivery signed dossiers (feature-outcome ledger — PER DELIVERY, never a head-to-head)');
        foreach ($dossiers as $d) {
            $dim = (array) ($d['dimensions'] ?? []);
            $state = ($dim['clean'] ?? false) ? 'clean' : ('defect:'.($dim['defect_reason'] ?? 'unknown'));
            $criteria = count((array) ($d['completeness_checklist'] ?? []));
            $this->line('• '.$d['target_path'].'  ['.$state.', '.$criteria.' criteria]  sig '.substr((string) ($d['signature'] ?? ''), 0, 12).'…  verify='.($service->verify($d) ? 'ok' : 'FAIL'));
        }

        return self::SUCCESS;
    }
}
