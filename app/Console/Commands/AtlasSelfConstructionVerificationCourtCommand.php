<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtEvidenceContract;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtFalseGreenDetector;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtGateReplayPlan;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtVerdictLedger;
use Illuminate\Console\Command;
use Throwable;

/**
 * READ-ONLY CLI for the Verification Court surface. Four verbs:
 *   inspect — list services + non-execution guarantees.
 *   plan    — read evidence JSON, print replay plan FACTS (no commands run).
 *   verdict — read evidence + replay results, run the false-green detector, print FACTS;
 *             appends to the verdict ledger ONLY when --ledger is supplied.
 *   history — read ledger rows from --ledger without changing verification state.
 */
final class AtlasSelfConstructionVerificationCourtCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:self-construction:verification-court {action : inspect|plan|verdict|history} {--evidence=} {--replay=} {--ledger=} {--json}';

    /** @var string */
    protected $description = 'Read-only Verification Court surface: inspect / plan / verdict / history.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'inspect' => $this->inspect(),
            'plan' => $this->plan(),
            'verdict' => $this->verdict(),
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
            'verbs' => ['inspect', 'plan', 'verdict', 'history'],
            'services' => [
                AtlasVerificationCourtEvidenceContract::SCHEMA,
                AtlasVerificationCourtGateReplayPlan::SCHEMA,
                AtlasVerificationCourtFalseGreenDetector::SCHEMA,
                AtlasVerificationCourtVerdictLedger::SCHEMA,
            ],
            'non_execution_guarantees' => [
                'reads_storage_when_ledger_supplied' => true,
                'writes_storage' => false,
                'runs_replay_commands' => false,
                'invokes_shell_or_git' => false,
                'calls_external_providers' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function plan(): array
    {
        $evidence = $this->readJson('evidence');
        if (! is_array($evidence)) {
            return ['status' => 'usage_error', 'reason' => '--evidence JSON file required'];
        }
        $contract = $this->app()->make(AtlasVerificationCourtEvidenceContract::class)->evaluate($evidence);
        $plan = $this->app()->make(AtlasVerificationCourtGateReplayPlan::class)->derive([
            'packet_facts' => is_array($evidence['packet_facts'] ?? null) ? $evidence['packet_facts'] : [],
            'evidence_contract_result' => $contract,
            'changed_files' => is_array($evidence['files_changed'] ?? null) ? $evidence['files_changed'] : [],
            'risk_level' => (string) ($evidence['risk_level'] ?? ''),
            'project_lane' => is_array($evidence['project_lane'] ?? null) ? $evidence['project_lane'] : null,
        ]);

        return ['status' => 'ok', 'evidence_contract' => $contract, 'replay_plan' => $plan];
    }

    /**
     * @return array<string,mixed>
     */
    private function verdict(): array
    {
        $evidence = $this->readJson('evidence');
        $replay = $this->readJson('replay');
        if (! is_array($evidence) || ! is_array($replay)) {
            return ['status' => 'usage_error', 'reason' => '--evidence and --replay JSON files required'];
        }

        $contract = $this->app()->make(AtlasVerificationCourtEvidenceContract::class)->evaluate($evidence);
        $plan = $this->app()->make(AtlasVerificationCourtGateReplayPlan::class)->derive([
            'packet_facts' => is_array($evidence['packet_facts'] ?? null) ? $evidence['packet_facts'] : [],
            'evidence_contract_result' => $contract,
            'changed_files' => is_array($evidence['files_changed'] ?? null) ? $evidence['files_changed'] : [],
            'risk_level' => (string) ($evidence['risk_level'] ?? ''),
            'project_lane' => is_array($evidence['project_lane'] ?? null) ? $evidence['project_lane'] : null,
        ]);
        $detector = $this->app()->make(AtlasVerificationCourtFalseGreenDetector::class)->detect([
            'evidence_contract_result' => $contract,
            'replay_plan_result' => $plan,
            'replay_outcomes' => is_array($replay['outcomes'] ?? null) ? $replay['outcomes'] : [],
            'changed_files' => is_array($evidence['files_changed'] ?? null) ? $evidence['files_changed'] : [],
            'allowed_files' => is_array($evidence['allowed_files'] ?? null) ? $evidence['allowed_files'] : [],
            'proxy_only_evidence' => (bool) ($evidence['proxy_only_evidence'] ?? false),
        ]);

        $envelope = ['status' => 'ok', 'evidence_contract' => $contract, 'replay_plan' => $plan, 'detector' => $detector];

        $ledgerPath = (string) ($this->option('ledger') ?? '');
        if ($ledgerPath !== '' && isset($evidence['ledger_envelope'])) {
            try {
                $envelope['ledger'] = (new AtlasVerificationCourtVerdictLedger($ledgerPath))->append($evidence['ledger_envelope']);
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
            return ['status' => 'ok', 'rows' => (new AtlasVerificationCourtVerdictLedger($path))->all()];
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $optionName): ?array
    {
        $path = (string) ($this->option($optionName) ?? '');
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @return \Illuminate\Contracts\Container\Container */
    private function app()
    {
        return $this->getLaravel();
    }
}
