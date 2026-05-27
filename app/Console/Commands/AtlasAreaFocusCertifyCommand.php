<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopOperationalCertificationService;
use Illuminate\Console\Command;

/**
 * Area Focus Loop · Operational Certification CLI (AP-722).
 *
 * Atlas Software Company Stewardship Stack é stack/capability family dentro do
 * Atlas Autonomous Software Company Runtime, não OS novo.
 *
 * Composes AP-716/718/719/720 owners into one read-only / decision-oriented
 * verdict (operational | partial | blocked). Records only the AP-720 append-only
 * evidence cycle; no repo mutation, no provider, no execution, no branch/merge/
 * deploy/secrets.
 */
class AtlasAreaFocusCertifyCommand extends Command
{
    protected $signature = 'atlas:night-shift:area-focus-certify
        {--area=agentic_engineering_os : Canonical area_id}
        {--no-evidence : Read-only structural certification (skip the append-only evidence cycle)}
        {--json : Emit JSON}';

    protected $description = 'Atlas Night Shift · Area Focus Loop operational certification (AP-722): composes the slices into one verdict. No repo mutation, no provider, no execution, no merge/deploy/secrets.';

    public function handle(AreaFocusLoopOperationalCertificationService $cert): int
    {
        $payload = $cert->certify([
            'area_id' => (string) $this->option('area'),
            'record_evidence' => ! (bool) $this->option('no-evidence'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($payload['status'] ?? '') === AreaFocusLoopOperationalCertificationService::STATUS_BLOCKED
                ? self::FAILURE
                : self::SUCCESS;
        }

        $this->components->twoColumnDetail('Area Focus Loop', 'operational certification (AP-722)');
        $this->components->twoColumnDetail('Area', (string) ($payload['area_id'] ?? '?'));
        $this->components->twoColumnDetail('Verdict', strtoupper((string) ($payload['status'] ?? 'unknown')));
        foreach ($payload['slice_checks'] ?? [] as $check) {
            $this->line(sprintf(
                '  [%s] %s · %s · %s',
                (string) ($check['status'] ?? '?'),
                (string) ($check['ap_contract'] ?? '?'),
                (string) ($check['id'] ?? '?'),
                (string) ($check['detail'] ?? ''),
            ));
        }
        if (! ($payload['governance']['passed'] ?? true)) {
            $this->warn('  governance violations: '.implode(', ', $payload['governance']['violations'] ?? []));
        }

        return ($payload['status'] ?? '') === AreaFocusLoopOperationalCertificationService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }
}
