<?php

declare(strict_types=1);

namespace App\Services\Ai\Aemor;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Throwable;

final class AtlasAemorCertificationService
{
    public const SCHEMA_VERSION = 'atlas.aemor.certification.v1';

    public const STATUS_PASSED = 'passed';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly AtlasAemorRuntimeService $runtime,
        private readonly AtlasAemorJudgmentService $judgment,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $checks = [
            $this->canonicalDocs(),
            $this->persistenceSurface(),
            $this->runtimeSmoke(),
            $this->judgmentSmoke(),
            $this->commandsPresent(),
            $this->integrationWiring(),
            $this->testsPresent(),
            $this->claimPolicy(),
        ];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => collect($checks)->contains(fn (array $check): bool => ($check['status'] ?? null) === 'fail') ? self::STATUS_BLOCKED : self::STATUS_PASSED,
            'generated_at' => CarbonImmutable::now()->toJSON(),
            'summary' => [
                'total' => count($checks),
                'pass' => collect($checks)->where('status', 'pass')->count(),
                'fail' => collect($checks)->where('status', 'fail')->count(),
            ],
            'checks' => $checks,
            'blockers' => array_values(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? null) === 'fail')),
            'claim_policy' => $this->runtime->claimPolicy(),
            'scope' => [
                'covers' => 'AEMOR local execution episodes, events, outcomes, distillation, replay, memory candidates, judgment guard and commands.',
                'does_not_cover' => 'external benchmark, provider execution, UI rendering or automatic policy mutation.',
            ],
        ];
        $payload['certification_hash'] = MissionCanonicalHash::sha256(array_diff_key($payload, ['generated_at' => true]));

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function canonicalDocs(): array
    {
        $main = base_path('docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md');
        $guard = base_path('docs/engineering-knowledge-base/atlas-aemor-judgment-learning-guard.md');
        $ok = $this->contains($main, 'Atlas Execution Memory & Outcome Runtime')
            && $this->contains($guard, 'Atlas AEMOR Judgment & Learning Guard')
            && $this->contains($main, 'atlas.aemor.execution_episode.v1')
            && $this->contains($guard, 'atlas.aemor.false_learning_gate.v1');

        return $this->check('canonical_docs', $ok, [$this->relative($main), $this->relative($guard)], 'Repair AEMOR canonical docs.');
    }

    /**
     * @return array<string,mixed>
     */
    private function persistenceSurface(): array
    {
        $migration = base_path('database/migrations/2026_05_20_130000_create_atlas_aemor_tables.php');
        $intelligenceMigration = base_path('database/migrations/2026_05_20_131000_add_intelligence_outputs_to_atlas_aemor_judgment_reports.php');
        $models = [
            base_path('app/Models/AtlasAemorExecutionEpisode.php'),
            base_path('app/Models/AtlasAemorOutcome.php'),
            base_path('app/Models/AtlasAemorJudgmentReport.php'),
        ];
        $ok = $this->contains($migration, 'atlas_aemor_execution_episodes')
            && $this->contains($migration, 'atlas_aemor_judgment_reports')
            && $this->contains($intelligenceMigration, 'outcome_attribution')
            && $this->contains($intelligenceMigration, 'operational_doctrine')
            && $this->contains($models[2], 'provider_skill_reliability')
            && $this->contains($models[2], 'counterfactual_replay')
            && collect($models)->every(fn (string $path): bool => File::exists($path));

        return $this->check('persistence_surface', $ok, array_map($this->relative(...), [$migration, $intelligenceMigration, ...$models]), 'Restore AEMOR migration/models and persisted Intelligence Layer outputs.');
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeSmoke(): array
    {
        try {
            $episode = $this->runtime->openEpisode([
                'objective' => 'AEMOR certification smoke',
                'workspace' => base_path(),
                'domain' => 'programming',
                'flow_id' => 'atlas_dev',
                'evidence_refs' => ['cert:aemor'],
            ]);
            $event = $this->runtime->observe([
                'episode_id' => $episode['episode_id'] ?? null,
                'event_type' => 'certification_smoke',
                'payload' => ['safe' => true],
                'evidence_refs' => ['cert:aemor:event'],
            ]);
            $outcome = $this->runtime->closeOutcome([
                'episode_id' => $episode['episode_id'] ?? null,
                'status' => 'succeeded',
                'summary' => 'AEMOR smoke closed with evidence.',
                'metrics' => ['tests_passed' => true, 'attribution_reviewed' => true],
                'evidence_refs' => ['cert:aemor:outcome'],
            ]);
            $distill = $this->runtime->distill([
                'episode_id' => $episode['episode_id'] ?? null,
                'outcome_id' => $outcome['outcome_id'] ?? null,
                'claim' => 'AEMOR certification smoke produced an evidence-backed learning candidate.',
                'evidence_refs' => ['cert:aemor:outcome'],
            ]);
        } catch (Throwable $exception) {
            return $this->check('runtime_smoke', false, ['exception' => $exception->getMessage()], 'Fix AEMOR runtime smoke.');
        }

        $ok = ($episode['status'] ?? null) === 'open'
            && ($event['status'] ?? null) === 'observed'
            && ($outcome['status'] ?? null) === 'succeeded'
            && ($distill['status'] ?? null) === 'candidate';

        return $this->check('runtime_smoke', $ok, [
            'episode_id' => $episode['episode_id'] ?? null,
            'outcome_id' => $outcome['outcome_id'] ?? null,
        ], 'Make AEMOR open/observe/close/distill work with evidence.');
    }

    /**
     * @return array<string,mixed>
     */
    private function judgmentSmoke(): array
    {
        try {
            $episode = $this->runtime->openEpisode([
                'objective' => 'AEMOR judgment smoke',
                'workspace' => base_path(),
                'evidence_refs' => ['cert:aemor:judgment'],
            ]);
            $this->runtime->closeOutcome([
                'episode_id' => $episode['episode_id'] ?? null,
                'status' => 'succeeded',
                'summary' => 'AEMOR judgment smoke outcome.',
                'metrics' => ['tests_passed' => true, 'attribution_reviewed' => true],
                'evidence_refs' => ['cert:aemor:judgment'],
            ]);
            $judgment = $this->judgment->judge((string) ($episode['episode_id'] ?? ''));
        } catch (Throwable $exception) {
            return $this->check('judgment_smoke', false, ['exception' => $exception->getMessage()], 'Fix AEMOR Judgment Guard.');
        }

        $ok = ($judgment['schema_version'] ?? null) === AtlasAemorJudgmentService::SCHEMA_VERSION
            && isset($judgment['judgment_hash'])
            && ($judgment['false_learning_gate']['status'] ?? null) === 'pass'
            && ($judgment['outcome_attribution']['schema_version'] ?? null) === 'atlas.aemor.outcome_attribution.v1'
            && ($judgment['negative_knowledge']['schema_version'] ?? null) === 'atlas.aemor.negative_knowledge.v1'
            && ($judgment['provider_skill_reliability']['schema_version'] ?? null) === 'atlas.aemor.provider_skill_reliability.v1'
            && ($judgment['counterfactual_replay']['schema_version'] ?? null) === 'atlas.aemor.counterfactual_replay.v1'
            && ($judgment['memory_budget']['schema_version'] ?? null) === 'atlas.aemor.memory_budget.v1'
            && ($judgment['operational_doctrine']['schema_version'] ?? null) === 'atlas.aemor.operational_doctrine.v1';

        return $this->check('judgment_smoke', $ok, [
            'judgment_hash' => $judgment['judgment_hash'] ?? null,
            'intelligence_outputs' => [
                'outcome_attribution' => $judgment['outcome_attribution']['schema_version'] ?? null,
                'negative_knowledge' => $judgment['negative_knowledge']['schema_version'] ?? null,
                'provider_skill_reliability' => $judgment['provider_skill_reliability']['schema_version'] ?? null,
                'counterfactual_replay' => $judgment['counterfactual_replay']['schema_version'] ?? null,
                'memory_budget' => $judgment['memory_budget']['schema_version'] ?? null,
                'operational_doctrine' => $judgment['operational_doctrine']['schema_version'] ?? null,
            ],
        ], 'Make AEMOR judgment smoke pass anti-false-learning gate and emit all Intelligence Layer outputs.');
    }

    /**
     * @return array<string,mixed>
     */
    private function commandsPresent(): array
    {
        $paths = [
            base_path('app/Console/Commands/AtlasAemorCommand.php'),
            base_path('app/Console/Commands/AtlasAemorReadinessCommand.php'),
            base_path('app/Console/Commands/AtlasAemorEpisodeOpenCommand.php'),
            base_path('app/Console/Commands/AtlasAemorObserveCommand.php'),
            base_path('app/Console/Commands/AtlasAemorCloseOutcomeCommand.php'),
            base_path('app/Console/Commands/AtlasAemorDistillCommand.php'),
            base_path('app/Console/Commands/AtlasAemorMemoryAuditCommand.php'),
            base_path('app/Console/Commands/AtlasAemorReplayCommand.php'),
            base_path('app/Console/Commands/AtlasAemorControlPlaneCommand.php'),
            base_path('app/Console/Commands/AtlasAemorCertifyCommand.php'),
            base_path('app/Console/Commands/AtlasAemorJudgmentCommand.php'),
            base_path('app/Console/Commands/AtlasAemorRiskPredictCommand.php'),
            base_path('app/Console/Commands/AtlasAemorMemoryConflictsCommand.php'),
            base_path('app/Console/Commands/AtlasAemorJudgmentCertifyCommand.php'),
        ];
        $ok = collect($paths)->every(fn (string $path): bool => File::exists($path));

        return $this->check('commands_present', $ok, array_map($this->relative(...), $paths), 'Restore AEMOR commands.');
    }

    /**
     * @return array<string,mixed>
     */
    private function integrationWiring(): array
    {
        $apcr = base_path('app/Services/Ai/PersistentContext/AtlasPersistentContextRuntimeService.php');
        $hyperflow = base_path('app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php');
        $controlPlane = base_path('app/Services/Ai/ControlPlane/AtlasAiControlPlaneService.php');
        $ok = $this->contains($apcr, 'AtlasAemorJudgmentService')
            && $this->contains($apcr, 'review_aemor_risk_prediction')
            && $this->contains($hyperflow, 'AtlasAemorRuntimeService')
            && $this->contains($hyperflow, 'openAemorEpisode')
            && $this->contains($hyperflow, 'closeAemorPlannedOutcome')
            && $this->contains($controlPlane, 'aemor(')
            && $this->contains($controlPlane, 'atlas:aemor:certify --json --strict');

        return $this->check('integration_wiring', $ok, [$this->relative($apcr), $this->relative($hyperflow), $this->relative($controlPlane)], 'Wire AEMOR into APCR, Hyperflow and Control Plane.');
    }

    /**
     * @return array<string,mixed>
     */
    private function testsPresent(): array
    {
        $paths = [
            base_path('tests/Feature/Ai/Aemor/AtlasAemorRuntimeServiceTest.php'),
            base_path('tests/Feature/Ai/Aemor/Judgment/AtlasAemorJudgmentServiceTest.php'),
        ];
        $ok = collect($paths)->every(fn (string $path): bool => File::exists($path));

        return $this->check('tests_present', $ok, array_map($this->relative(...), $paths), 'Add AEMOR feature tests.');
    }

    /**
     * @return array<string,mixed>
     */
    private function claimPolicy(): array
    {
        $policy = $this->runtime->claimPolicy();
        $ok = ($policy['benchmark_not_run'] ?? false) === true
            && ($policy['provider_calls_made'] ?? true) === false
            && ($policy['auto_changes_policy'] ?? true) === false
            && ($policy['auto_promotes_memory'] ?? true) === false;

        return $this->check('claim_policy', $ok, $policy, 'Restore AEMOR no-provider/no-benchmark/no-autopromotion policy.');
    }

    /**
     * @param  array<string,mixed>|list<string>  $evidence
     * @return array<string,mixed>
     */
    private function check(string $id, bool $ok, array $evidence, string $remediation): array
    {
        return [
            'id' => $id,
            'status' => $ok ? 'pass' : 'fail',
            'severity' => 'critical',
            'evidence' => $evidence,
            'remediation' => $ok ? null : $remediation,
        ];
    }

    private function contains(string $path, string $needle): bool
    {
        return str_contains(File::exists($path) ? File::get($path) : '', $needle);
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }
}
