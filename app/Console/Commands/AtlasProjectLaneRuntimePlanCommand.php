<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneAdmissionPolicy;
use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneContextFreshnessGate;
use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneIsolationSentinel;
use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneKnowledgeSyncPolicy;
use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneQueueNamespacePolicy;
use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneReleaseGovernor;
use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneVerificationPolicy;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only CLI exposing the multi-project lane RUNTIME PLAN for an admitted lane. Three verbs:
 *   inspect  — list required manifest + facts fields.
 *   plan     — compose admission + namespace + verification + knowledge-sync plan facts (no I/O).
 *   decision — return AtlasProjectLaneReleaseGovernor output from supplied facts.
 */
final class AtlasProjectLaneRuntimePlanCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:task:project-lane-runtime-plan {action : inspect|plan|decision} {--manifest=} {--facts=} {--json}';

    /** @var string */
    protected $description = 'Project-lane runtime plan: inspect / plan / decision (read-only).';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'inspect' => $this->inspect(),
            'plan' => $this->plan(),
            'decision' => $this->decision(),
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
            'verbs' => ['inspect', 'plan', 'decision'],
            'required_manifest_fields' => AtlasProjectLaneAdmissionPolicy::REQUIRED_FIELDS,
            'required_facts_fields' => [
                'verification_court_verdict.verdict',
                'verification_court_verdict.server_side_green',
                'receipt_evidence.envelope_hash',
                'rollback_gate.conformant',
                'knowledge_sync_plan.conformant',
                'cross_lane_refusal_flags',
                'repeated_failure_streak',
            ],
            'non_execution_guarantees' => [
                'writes_storage' => false,
                'starts_workers' => false,
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
        $manifest = $this->readJson('manifest');
        if (! is_array($manifest)) {
            return ['status' => 'usage_error', 'reason' => '--manifest JSON file required'];
        }
        $admission = $this->app()->make(AtlasProjectLaneAdmissionPolicy::class)->admit($manifest);
        $observations = is_array($manifest['context_observations'] ?? null) ? $manifest['context_observations'] : [];
        $freshness = $this->app()->make(AtlasProjectLaneContextFreshnessGate::class)->evaluate($manifest, $observations);
        $namespace = $this->safeNamespace($manifest);

        // Empty task-evidence by default — caller can layer real evidence via the decision verb.
        $verification = $this->app()->make(AtlasProjectLaneVerificationPolicy::class)->decide($admission, $freshness, []);
        $knowledgeSync = $this->app()->make(AtlasProjectLaneKnowledgeSyncPolicy::class)->plan([
            'lane_manifest' => ['project_id' => (string) ($manifest['project_id'] ?? ''), 'allowed_scope_roots' => (array) ($manifest['allowed_scope_roots'] ?? [])],
            'touched_paths' => is_array($manifest['touched_paths'] ?? null) ? $manifest['touched_paths'] : [],
        ]);
        $isolationSentinel = $this->app()->make(AtlasProjectLaneIsolationSentinel::class)->evaluate([
            'project_id' => (string) ($manifest['project_id'] ?? ''),
            'namespace_facts' => is_array($namespace) ? $namespace : null,
            'receipt_facts' => is_array($manifest['receipt_facts'] ?? null) ? $manifest['receipt_facts'] : null,
            'leak_detector_verdict' => is_array($manifest['leak_detector_verdict'] ?? null) ? $manifest['leak_detector_verdict'] : null,
        ]);

        return [
            'status' => 'ok',
            'admission' => $admission,
            'namespace' => $namespace,
            'context_freshness' => $freshness,
            'verification_policy' => $verification,
            'knowledge_sync_plan' => $knowledgeSync,
            'isolation_sentinel' => $isolationSentinel,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decision(): array
    {
        $facts = $this->readJson('facts');
        if (! is_array($facts)) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON file required'];
        }
        $verdict = $this->app()->make(AtlasProjectLaneReleaseGovernor::class)->decide($facts);

        return ['status' => 'ok', 'release_governor' => $verdict];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    private function safeNamespace(array $manifest): array
    {
        try {
            return $this->app()->make(AtlasProjectLaneQueueNamespacePolicy::class)->derive([
                'project_id' => (string) ($manifest['project_id'] ?? ''),
                'repo_root' => (string) ($manifest['repo_root'] ?? ''),
                'mainline_branch' => (string) ($manifest['mainline_branch'] ?? ''),
            ]);
        } catch (Throwable $e) {
            return ['status' => 'namespace_unavailable', 'reason' => $e->getMessage()];
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
