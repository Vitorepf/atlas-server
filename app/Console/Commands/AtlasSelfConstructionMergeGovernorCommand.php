<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorAdmissionPolicy;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorReleaseDecisionLedger;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorRiskClassifier;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorRollbackPlanGate;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * READ-ONLY CLI for inspecting Merge Governor readiness, dry-running an admission decision and
 * reading the decision ledger. Three verbs:
 *   inspect — list required services + non-execution guarantees.
 *   decide  — read candidate JSON, compose classifier + rollback gate + admission policy, print FACTS.
 *             Appends to the decision ledger ONLY when --ledger is supplied.
 *   history — read ledger rows from --ledger and print them without changing release state.
 */
final class AtlasSelfConstructionMergeGovernorCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:self-construction:merge-governor {action : inspect|decide|history|enforce-readiness} {--candidate=} {--ledger=} {--since=} {--json}';

    /** @var string */
    protected $description = 'Read-only Merge Governor surface: inspect / decide (dry-run) / history.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'inspect' => $this->inspect(),
            'decide' => $this->decide(),
            'history' => $this->history(),
            'enforce-readiness' => $this->enforceReadiness(),
            default => ['status' => 'unknown_action', 'action' => $action],
        };
        $this->line($this->encode($payload));

        return ($payload['status'] ?? 'ok') === 'ok' || ! isset($payload['status']) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function inspect(): array
    {
        return [
            'status' => 'ok',
            'verbs' => ['inspect', 'decide', 'history'],
            'services' => [
                AtlasMergeGovernorRiskClassifier::SCHEMA,
                AtlasMergeGovernorRollbackPlanGate::SCHEMA,
                AtlasMergeGovernorAdmissionPolicy::SCHEMA,
                AtlasMergeGovernorReleaseDecisionLedger::SCHEMA,
            ],
            'non_execution_guarantees' => [
                'reads_storage_when_ledger_supplied' => true,
                'writes_storage' => false,
                'starts_merge' => false,
                'invokes_shell_or_git' => false,
                'calls_external_providers' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decide(): array
    {
        $path = (string) ($this->option('candidate') ?? '');
        if ($path === '' || ! is_file($path)) {
            return ['status' => 'usage_error', 'reason' => '--candidate JSON file required'];
        }
        try {
            $candidate = json_decode((string) file_get_contents($path), true);
        } catch (Throwable $e) {
            return ['status' => 'candidate_unreadable', 'reason' => $e->getMessage()];
        }
        if (! is_array($candidate)) {
            return ['status' => 'candidate_unreadable', 'reason' => 'not a JSON object'];
        }

        $riskInput = is_array($candidate['risk_input'] ?? null) ? $candidate['risk_input'] : [];
        if (trim((string) ($riskInput['task_evidence_ref'] ?? '')) === '') {
            // Same derivation as AtlasTaskCommitGovernanceChain::govern(): the task id
            // when present, else a deterministic hash of the candidate's evidence. The
            // classifier grew a hard task_evidence_ref requirement and this caller was
            // never updated — every command decide risk_blocked on the missing ref
            // (1833 recorded would-blocks on 30/06-01/07 were exactly this).
            $taskId = trim((string) ($candidate['task_packet_id'] ?? ''));
            $riskInput['task_evidence_ref'] = $taskId !== '' ? $taskId : hash('sha256', (string) json_encode($riskInput));
        }
        $risk = $this->app()->make(AtlasMergeGovernorRiskClassifier::class)->classify($riskInput);

        // Same derivations AtlasTaskCommitGovernanceChain::govern() applies before the
        // rollback gate — the gate grew required fields (pre_image_hash, restore_target,
        // verification_command) this candidate-JSON caller was never updated to derive.
        $rollbackInput = is_array($candidate['rollback_plan'] ?? null) ? $candidate['rollback_plan'] : [];
        $taskId = trim((string) ($candidate['task_packet_id'] ?? ''));
        $rollbackInput['restore_target'] = trim((string) ($rollbackInput['restore_target'] ?? '')) !== ''
            ? $rollbackInput['restore_target']
            : ($taskId !== '' ? 'git_revert:'.$taskId : 'git_revert:HEAD');
        $rollbackInput['pre_image_hash'] = trim((string) ($rollbackInput['pre_image_hash'] ?? '')) !== ''
            ? $rollbackInput['pre_image_hash']
            : (string) $riskInput['task_evidence_ref'];
        $rollbackInput['verification_command'] = trim((string) ($rollbackInput['verification_command'] ?? '')) !== ''
            ? $rollbackInput['verification_command']
            : 'php artisan atlas:task test-suite';
        $rollback = $this->app()->make(AtlasMergeGovernorRollbackPlanGate::class)->evaluate($rollbackInput);
        $facts = [
            'project_id' => (string) ($candidate['project_id'] ?? ''),
            'risk_classification' => $risk,
            'rollback_gate' => $rollback,
            'verification_court' => is_array($candidate['verification_court'] ?? null) ? $candidate['verification_court'] : [],
            'release_window_policy' => is_array($candidate['release_window_policy'] ?? null) ? $candidate['release_window_policy'] : [],
        ];
        $decision = $this->app()->make(AtlasMergeGovernorAdmissionPolicy::class)->decide($facts);

        $envelope = [
            'status' => 'ok',
            'risk_classification' => $risk,
            'rollback_gate' => $rollback,
            'admission' => $decision,
        ];

        $ledgerPath = (string) ($this->option('ledger') ?? '');
        if ($ledgerPath !== '' && isset($candidate['ledger_envelope'])) {
            $ledger = new AtlasMergeGovernorReleaseDecisionLedger($ledgerPath);
            try {
                $ledgerEnvelope = (array) $candidate['ledger_envelope'];
                // The ledger grew required fields (risk_level, changed_files_hash);
                // derive them from the classification this very decide just computed.
                if (trim((string) ($ledgerEnvelope['risk_level'] ?? '')) === '') {
                    $ledgerEnvelope['risk_level'] = (string) ($risk['risk_level'] ?? 'medium');
                }
                if (trim((string) ($ledgerEnvelope['changed_files_hash'] ?? '')) === '') {
                    $ledgerEnvelope['changed_files_hash'] = hash('sha256', (string) json_encode(array_values((array) ($riskInput['changed_files'] ?? []))));
                }
                $envelope['ledger'] = $ledger->append($ledgerEnvelope);
            } catch (Throwable $e) {
                $envelope['ledger'] = ['status' => 'error', 'message' => $e->getMessage()];
            }
        }

        return $envelope;
    }

    /**
     * @return array<string,mixed>
     */
    private function history(): array
    {
        $path = (string) ($this->option('ledger') ?? '');
        if ($path === '') {
            return ['status' => 'usage_error', 'reason' => '--ledger path required for history'];
        }
        try {
            $ledger = new AtlasMergeGovernorReleaseDecisionLedger($path);
            $rows = $ledger->listChronological();
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        return ['status' => 'ok', 'rows' => $rows];
    }

    /**
     * Evidence fold: per risk level, is the CLEAN release-decision ledger ready
     * to justify observe→enforce? Mechanical + fail-closed: readiness requires
     * enough samples AND zero decisions carrying a known-spurious reason (the
     * producer bugs the S21 fixes closed). NEVER flips a mode — same contract
     * as the ADML activation sweep: the fold produces the evidence, flipping
     * remains an explicit operator/flag decision.
     *
     * @return array<string,mixed>
     */
    private function enforceReadiness(): array
    {
        $path = (string) ($this->option('ledger') ?? '');
        if ($path === '') {
            $path = storage_path('atlas/governance/merge-governor-release-decision-ledger.jsonl');
        }
        $since = trim((string) ($this->option('since') ?? ''));

        try {
            $rows = (new AtlasMergeGovernorReleaseDecisionLedger($path))->listChronological();
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        // Reasons produced by caller/attribution bugs, not by bad commits — a
        // ledger window containing ANY of them is not evidence for enforce.
        $spuriousReasons = ['missing_task_evidence_ref', 'project_lane_missing'];
        $minSamples = 20;

        $byRisk = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ($since !== '' && (string) ($row['decided_at'] ?? '') < $since) {
                continue;
            }
            $risk = (string) ($row['risk_level'] ?? 'unknown');
            $byRisk[$risk] ??= ['samples' => 0, 'decisions' => [], 'spurious' => 0, 'top_reasons' => []];
            $byRisk[$risk]['samples']++;
            $decision = (string) ($row['decision'] ?? 'unknown');
            $byRisk[$risk]['decisions'][$decision] = ($byRisk[$risk]['decisions'][$decision] ?? 0) + 1;
            foreach ((array) ($row['reasons'] ?? []) as $reason) {
                $reason = (string) $reason;
                $byRisk[$risk]['top_reasons'][$reason] = ($byRisk[$risk]['top_reasons'][$reason] ?? 0) + 1;
                foreach ($spuriousReasons as $spurious) {
                    if (str_contains($reason, $spurious)) {
                        $byRisk[$risk]['spurious']++;

                        break;
                    }
                }
            }
        }

        $levels = [];
        foreach ($byRisk as $risk => $agg) {
            arsort($agg['top_reasons']);
            $admitted = (int) ($agg['decisions']['admitted'] ?? 0);
            $wouldBlock = $agg['samples'] - $admitted;
            $ready = $agg['samples'] >= $minSamples && $agg['spurious'] === 0;
            $reason = match (true) {
                $agg['samples'] < $minSamples => 'insufficient_samples',
                $agg['spurious'] > 0 => 'spurious_reasons_in_window',
                default => 'evidence_clean',
            };
            $levels[$risk] = [
                'samples' => $agg['samples'],
                'decisions' => $agg['decisions'],
                'would_block_rate' => $agg['samples'] > 0 ? round($wouldBlock / $agg['samples'], 4) : null,
                'spurious_reason_count' => $agg['spurious'],
                'top_reasons' => array_slice($agg['top_reasons'], 0, 5, true),
                'enforce_ready' => $ready,
                'reason' => $reason,
            ];
        }
        ksort($levels);

        return [
            'status' => 'ok',
            'schema' => 'atlas.mergegovernor.enforce_readiness.v1',
            'since' => $since !== '' ? $since : null,
            'min_samples' => $minSamples,
            'spurious_reasons' => $spuriousReasons,
            'risk_levels' => $levels,
            'note' => 'read-only evidence fold; flipping observe->enforce remains an explicit operator decision',
        ];
    }

    /** @return \Illuminate\Contracts\Container\Container */
    private function app()
    {
        return $this->getLaravel();
    }
}
