<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopCertificationService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Area Focus Loop · Structural Certification CLI (AP-725).
 *
 * Atlas Software Company Stewardship Stack é stack/capability family dentro do
 * Atlas Autonomous Software Company Runtime, não OS novo. Proves the read-only
 * Area Focus Loop family is present and healthy for a canonical area. It runs no
 * loop, opens no branch and never merges/deploys/touches secrets. It returns
 * `ready_read_only` or `blocked` — never `ready_for_autonomous_mutation`.
 */
class AtlasAreaFocusLoopCertifyCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:software-company-stewardship:area-focus-certify
        {--area=agentic_engineering_os : Canonical area_id}
        {--json : Emit JSON}';

    protected $description = 'Atlas Software Company Stewardship Stack · Area Focus Loop structural certification (read-only; checks APs, docs, services, command, schemas, gates, receipts, validations; ready_read_only|blocked, never mutation).';

    public function handle(AreaFocusLoopCertificationService $certification): int
    {
        $report = $certification->certify(['area_id' => (string) $this->option('area')]);

        $this->emit($report, function (array $p): void {
            $this->components->twoColumnDetail('Area Focus Loop', 'structural certification (read-only) · AP-725');
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? '?'));
            $this->components->twoColumnDetail('Status', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Mutation ready', ($p['mutation_ready'] ?? false) ? 'yes' : 'no (read-only)');
            $this->components->twoColumnDetail('Components', sprintf('%d present / %d missing', (int) ($p['present_count'] ?? 0), (int) ($p['missing_count'] ?? 0)));
            foreach (($p['coverage'] ?? []) as $kind => $cov) {
                $this->components->twoColumnDetail('  '.$kind, sprintf('%d ok / %d missing', (int) ($cov['present'] ?? 0), (int) ($cov['missing'] ?? 0)));
            }
            foreach (($p['missing_components'] ?? []) as $missing) {
                $this->warn('  missing: '.(string) $missing);
            }
            foreach (($p['pending_slice_aps'] ?? []) as $ap => $state) {
                if ($state !== 'present') {
                    $this->line(sprintf('  pending ap: %s · %s', (string) $ap, (string) $state));
                }
            }
            if (($p['status'] ?? '') === AreaFocusLoopCertificationService::STATUS_BLOCKED && isset($p['reason'])) {
                $this->warn('  blocked: '.(string) $p['reason'].' · '.(string) ($p['detail'] ?? ''));
            }
        });

        return ($report['status'] ?? '') === AreaFocusLoopCertificationService::STATUS_READY_READ_ONLY
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, callable $human): void
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return;
        }
        $human($payload);
    }
}
