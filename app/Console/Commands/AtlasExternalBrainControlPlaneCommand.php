<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainControlPlaneSnapshot;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only control plane inspector for the external-brain pipeline.
 *
 * Actions
 *   inspect  — full snapshot (maturity, blockers, evidence gaps, dimensions)
 *   plan     — next actions and missing capabilities for the upcoming wave
 *   audit    — batch quality audit verdict and any Goodhart findings
 *   certify  — whether the brain is ready for another quota run (maturity ≥ functional, no critical blockers)
 *
 * All actions emit JSON. The command never enqueues work, starts workers,
 * calls providers, commits, or mutates evidence ledgers.
 */
final class AtlasExternalBrainControlPlaneCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:external-brain:control-plane
        {action=inspect : Action to perform (inspect|plan|audit|certify)}
        {--queue-status= : Override queue status for the snapshot (healthy|degraded|stalled)}
        {--audit-verdict= : Override audit verdict for the snapshot (pass|repair_required|reject)}
        {--stalled-yield : Mark yield as stalled}
        {--json : Alias kept for script compatibility; output is always JSON}
    ';

    /** @var string */
    protected $description = 'Read-only external-brain control plane inspector (inspect|plan|audit|certify).';

    public function handle(AtlasExternalBrainControlPlaneSnapshot $snap): int
    {
        $action = strtolower(trim((string) $this->argument('action')));

        $snapshot = $snap->snapshot($this->buildInputs());

        $payload = match ($action) {
            'inspect'  => $this->formatInspect($snapshot),
            'plan'     => $this->formatPlan($snapshot),
            'audit'    => $this->formatAudit($snapshot),
            'certify'  => $this->formatCertify($snapshot),
            default    => [
                'status'        => 'error',
                'reason'        => 'unknown_action',
                'valid_actions' => ['inspect', 'plan', 'audit', 'certify'],
            ],
        };

        $this->line($this->encode($payload));

        return $action === 'unknown' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Build snapshot inputs from CLI options / config / env.
     * All keys are optional; missing ones lower maturity safely.
     *
     * @return array<string,mixed>
     */
    private function buildInputs(): array
    {
        $queueStatus  = (string) ($this->option('queue-status')  ?? config('atlas.external_brain.queue_status',  'unknown'));
        $auditVerdict = (string) ($this->option('audit-verdict') ?? config('atlas.external_brain.audit_verdict', 'unknown'));
        $stalledYield = (bool)   ($this->option('stalled-yield') ?? config('atlas.external_brain.stalled_yield', false));

        $rubricRaw = config('atlas.external_brain.rubric_scores', '{}');
        $rubric    = is_array($rubricRaw)
            ? $rubricRaw
            : (json_decode((string) $rubricRaw, true) ?? []);

        return [
            'rubric_scores'      => $rubric,
            'queue_health'       => ['status' => $queueStatus],
            'audit_result'       => ['verdict' => $auditVerdict, 'findings' => []],
            'ledger_summary'     => null,
            'stalled_yield'      => $stalledYield,
            'known_capabilities' => [],
        ];
    }

    /** @param array<string,mixed> $snap */
    private function formatInspect(array $snap): array
    {
        return [
            'status'        => 'ok',
            'action'        => 'inspect',
            'maturity_band' => $snap['maturity_band'],
            'maturity_score' => $snap['maturity_score'],
            'blockers'      => $snap['blockers'],
            'next_actions'  => $snap['next_actions'],
            'evidence_gaps' => $snap['evidence_gaps'],
            'dimensions'    => $snap['dimensions'],
        ];
    }

    /** @param array<string,mixed> $snap */
    private function formatPlan(array $snap): array
    {
        return [
            'status'                    => 'ok',
            'action'                    => 'plan',
            'maturity_band'             => $snap['maturity_band'],
            'next_actions'              => $snap['next_actions'],
            'next_missing_capabilities' => $snap['next_missing_capabilities'],
            'blockers'                  => $snap['blockers'],
        ];
    }

    /** @param array<string,mixed> $snap */
    private function formatAudit(array $snap): array
    {
        return [
            'status'         => 'ok',
            'action'         => 'audit',
            'maturity_band'  => $snap['maturity_band'],
            'audit_verdict'  => $snap['dimensions']['audit_verdict'],
            'blockers'       => $snap['blockers'],
            'evidence_gaps'  => $snap['evidence_gaps'],
        ];
    }

    /** @param array<string,mixed> $snap */
    private function formatCertify(array $snap): array
    {
        $criticalBands = [
            AtlasExternalBrainControlPlaneSnapshot::BAND_BOOTSTRAPPING,
            AtlasExternalBrainControlPlaneSnapshot::BAND_EMERGING,
        ];
        $hasCriticalBlocker = array_filter($snap['blockers'], static fn (array $b): bool => isset($b['impact']) && str_contains($b['impact'], 'caps_maturity_at_emerging'));
        $ready = ! in_array($snap['maturity_band'], $criticalBands, true) && $hasCriticalBlocker === [];

        return [
            'status'        => $ready ? 'ready' : 'not_ready',
            'action'        => 'certify',
            'certified'     => $ready,
            'maturity_band' => $snap['maturity_band'],
            'blockers'      => $snap['blockers'],
            'next_actions'  => $snap['next_actions'],
        ];
    }
}
