<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorAdmissionPolicy;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorReleaseDecisionLedger;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorRiskClassifier;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorRollbackPlanGate;
use Illuminate\Console\Command;
use Throwable;

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
    /** @var string */
    protected $signature = 'atlas:self-construction:merge-governor {action : inspect|decide|history} {--candidate=} {--ledger=} {--json}';

    /** @var string */
    protected $description = 'Read-only Merge Governor surface: inspect / decide (dry-run) / history.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'inspect' => $this->inspect(),
            'decide' => $this->decide(),
            'history' => $this->history(),
            default => ['status' => 'unknown_action', 'action' => $action],
        };
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

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

        $risk = $this->app()->make(AtlasMergeGovernorRiskClassifier::class)->classify($candidate['risk_input'] ?? []);
        $rollback = $this->app()->make(AtlasMergeGovernorRollbackPlanGate::class)->evaluate($candidate['rollback_plan'] ?? []);
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
                $envelope['ledger'] = $ledger->append($candidate['ledger_envelope']);
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

    /** @return \Illuminate\Contracts\Container\Container */
    private function app()
    {
        return $this->getLaravel();
    }
}
