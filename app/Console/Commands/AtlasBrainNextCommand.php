<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\AtlasLoopOriginationPipeline;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCompoundingDigest;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainEvolutionDocAuthor;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainEvolutionLevelClassifier;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainFrontierSourceRegistry;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainGateAdversarialAuditor;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHeartbeatLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainLeverageBrief;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainMetricSnapshot;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainOrphanSpecDrafter;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPortfolioRouter;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainReflectionStream;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainResearchSourceRegistry;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeDryProbe;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeRegistry;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedGateAdversarialAuditor;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedQualityGate;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSpecSimulationTwin;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainStructuralSignalDigest;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainTaskSpecTranslator;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModelBuilder;
use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use App\Services\Engineering\EliteCompactionFreezeGuard;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * EXTERNAL BRAIN · the "decide" verb. Mirrors `atlas:task next`: it PULLS the next originated evolution spec
 * for a scope and hands it to a worker — but it NEVER edits app/, NEVER commits, NEVER merges. author≠judge:
 * the brain writes ONLY to the journal (docs/) + the done-set ledger (storage/), and emits a packet spec the
 * worker (or `atlas:brain:seed`) carries forward. The STOP decision is the dry-probe's, not the model's.
 *
 * FLOW: master-switch gate → build comprehension model → dry-probe over recent cycles → originate via the
 * pipeline → HARD dedup (done-set) → translate to a packet spec → screen every allowed_file via the harness
 * guard → seed-quality gate (FIX-1, advisory at the seed boundary) → author the journal section + record a
 * 'served' cycle. Every refusal/abstain records a refusal cycle so the dry-probe can converge honestly.
 */
final class AtlasBrainNextCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:brain:next {scope : the scope slug (e.g. loop)}
        {--repo= : repo root (default base_path)}
        {--docs=* : canonical doc roots for doc-stated-gap detection}
        {--m=3 : consecutive refusals the dry-probe requires before declaring the scope dry}
        {--max-prior=50 : how many prior done-set targets to feed the originator as context}
        {--actor= : external brain actor/client id for heartbeat}
        {--scope-signals : include the rich scope_signals digest for this invocation}
        {--json}';

    /** @var string */
    protected $description = 'Brain DECIDE: originate + design the next grounded evolution spec for a scope (author≠judge — writes only docs/ + ledger).';

    private string $heartbeatScope = '';

    private string $heartbeatActor = '';

    private bool $forceScopeSignals = false;

    /** @var array<string,string>|null */
    private ?array $tempSpecRecovery = null;

    public function handle(): int
    {
        $scope = trim((string) $this->argument('scope')) ?: 'autonomous';
        $this->heartbeatScope = $scope;
        $this->heartbeatActor = trim((string) ($this->option('actor') ?? ''));
        $this->forceScopeSignals = (bool) $this->option('scope-signals');
        $this->tempSpecRecovery = null;

        // §0 — fail-CLOSED master gate. OFF ⇒ clean no-op (never a crash), gated independently of the muscle.
        if (! AtlasBrainMasterSwitch::enabled()) {
            return $this->emit(['status' => 'disabled', 'reason' => 'brain_master_switch_off']);
        }

        $freezeRefusal = app(EliteCompactionFreezeGuard::class)->refusalPayload('atlas:brain:next');
        if ($freezeRefusal !== null) {
            return $this->emit($freezeRefusal, self::FAILURE);
        }

        $repoRoot = rtrim((string) ($this->option('repo') ?: base_path()), '/');
        $docsRoots = array_values(array_filter(array_map('strval', (array) $this->option('docs'))));
        $m = max(1, (int) $this->option('m'));
        $maxPrior = max(0, (int) $this->option('max-prior'));

        try {
            return $this->decide($scope, $repoRoot, $docsRoots, $m, $maxPrior);
        } catch (Throwable $e) {
            return $this->emit(['status' => 'error', 'error' => $e->getMessage()], self::FAILURE);
        }
    }

    /**
     * @param  list<string>  $docsRoots
     */
    private function decide(string $scope, string $repoRoot, array $docsRoots, int $m, int $maxPrior): int
    {
        // Resolve the scope into its concrete reach: comprehension roots, doc roots, and the meta_harness
        // decision. The registry is pétreo — the brain can never widen its own scope nor self-arm meta_harness.
        $scopeDef = app(AtlasBrainScopeRegistry::class)->resolve($scope);
        $scope = $scopeDef['slug'];
        $metaHarness = $scopeDef['meta_harness'];
        $ledger = new AtlasBrainDoneSetLedger($scope, (string) config('atlas.brain.done_set_root'));
        $this->tempSpecRecovery = $this->discardDoneTempSpec($ledger);
        $model = $this->comprehend($repoRoot, $scopeDef['roots'], $docsRoots !== [] ? $docsRoots : $scopeDef['docs_roots']);

        // STOP is the dry-probe's call — never the model self-judging. Dry ⇒ honest stop.
        $probe = app(AtlasBrainScopeDryProbe::class)->probe($ledger->recentCycles(max($m, 10)), $model, $m);
        if (($probe['state'] ?? '') === 'dry') {
            return $this->emit([
                'status' => 'dry',
                'scope' => $scope,
                'consecutive_refusals' => (int) ($probe['consecutive_refusals'] ?? 0),
            ]);
        }

        // ORIGINATE + DESIGN. priorAttempts = the done-set targets, so the originator never re-proposes them.
        $priorAttempts = $this->priorTargets($ledger, $maxPrior);
        // S215 Discovery→Brain coupling: per-target refusal memory steers leverage-first origination away
        // from targets the brain has already refused (OFF by default => empty map => byte-identical pick).
        $refusalCounts = $this->buildRefusalCounts($ledger);
        $origination = app(AtlasLoopOriginationPipeline::class)->produce($model, $repoRoot, $priorAttempts, $refusalCounts);

        $produced = (bool) ($origination['produced'] ?? false);
        $action = (string) ($origination['action'] ?? '');
        // Refusal for dry-purposes: nothing produced OR an abstain (park-and-ask) — REGARDLESS of `produced`.
        if (! $produced || $action === 'abstain') {
            $status = ! $produced ? 'refused' : 'abstain';
            $refusalTarget = (string) ($origination['target_path'] ?? '');
            $reason = (string) ($origination['reason'] ?? ($action === 'abstain' ? 'parked_and_asked' : 'not_originated'));
            $this->recordRefusal($ledger, $model->snapshotId, $status, $action, $refusalTarget);
            // REFLEXION memory — record this refusal as a fact, then recall prior failure semantics for THIS
            // scope so the next origination (the pasted brain) is conditioned on what already failed. Both
            // legs are flag-gated (reflection_enabled): OFF ⇒ record is a no-op and recallTexts returns [].
            $this->reflect($scope, $model->snapshotId, $status, $refusalTarget, $reason);

            $payload = [
                'status' => $status,
                'scope' => $scope,
                'reason' => $reason,
                'operator_question' => $origination['operator_question'] ?? null,
            ];
            $recalled = app(AtlasBrainReflectionStream::class)->recallTexts($scope, ['signals' => ['target_path' => $refusalTarget]]);
            if ($recalled !== []) {
                $payload['reflections'] = $recalled;
            }
            $payload += $this->priorBriefsFor($scope);
            $payload += $this->scopeSignalsFor($scope, $model, $ledger);

            return $this->emit($payload);
        }

        $targetPath = ltrim((string) ($origination['target_path'] ?? ''), '/');

        // HARD dedup — the done-set is the brain's sticky memory. A target already originated ⇒ refusal, not a re-serve.
        if ($ledger->isDone($targetPath)) {
            $this->recordRefusal($ledger, $model->snapshotId, 'already_done', $action, $targetPath);

            return $this->emit(['status' => 'already_done', 'scope' => $scope, 'target_path' => $targetPath]);
        }

        // TRANSLATE to the exact seed-gov-lanes packet spec.
        $spec = app(AtlasBrainTaskSpecTranslator::class)->translate([
            'objective' => (string) ($origination['objective'] ?? ''),
            'target_path' => $targetPath,
            'obligations' => array_values((array) ($origination['obligations'] ?? [])),
            'snapshot_id' => $model->snapshotId,
        ]);

        // SCREEN every allowed_file through the harness guard. forbidden (pétreo) OR harness_gated (meta OFF)
        // ⇒ NON-seedable; the brain never originates against its own lock. meta_harness comes from the SCOPE.
        $guard = app(AtlasLoopHarnessGuard::class);
        $blockedTargets = [];
        foreach ((array) $spec['allowed_files'] as $file) {
            $tier = $guard->admit((string) $file, $metaHarness);
            if ($tier === 'forbidden' || $tier === 'harness_gated') {
                $blockedTargets[(string) $file] = $tier;
            }
        }
        if ($blockedTargets !== []) {
            $this->recordRefusal($ledger, $model->snapshotId, 'forbidden_target', $action, $targetPath);

            return $this->emit([
                'status' => 'forbidden_target',
                'scope' => $scope,
                'target_path' => $targetPath,
                'blocked' => $blockedTargets,
            ]);
        }

        // SEED-QUALITY gate (FIX-1) — advisory promotion at the seed boundary; never edits the universal inspector.
        $gate = app(AtlasBrainSeedQualityGate::class)->evaluate($this->inspectorShape($spec));
        if (($gate['admit'] ?? false) !== true) {
            $this->recordRefusal($ledger, $model->snapshotId, 'prepare_blocked', $action, $targetPath);

            return $this->emit([
                'status' => 'prepare_blocked',
                'scope' => $scope,
                'target_path' => $targetPath,
                'reasons' => array_values((array) ($gate['reasons'] ?? [])),
            ]);
        }

        // AUTHOR the journal section (docs/ only) + record the 'served' cycle. author≠judge.
        $cycleN = count($ledger->recentCycles(100000)) + 1;
        $journalPath = app(AtlasBrainEvolutionDocAuthor::class)->append($scope, [
            'cycle_n' => $cycleN,
            'objective' => (string) $spec['objective'],
            'class' => $this->classifyClass($spec, $model),
            'cited_symbols' => [$targetPath],
            'evidence' => array_values((array) $spec['evidence_requirements']),
            'seeded_packet_ids' => [(string) $spec['task_packet_id']],
        ], $model->inventory, (string) config('atlas.brain.journal_root', 'docs/autonomos-evolution-journal'));

        $ledger->record([
            'snapshot_id' => $model->snapshotId,
            'status' => 'served',
            'produced' => true,
            'action' => $action,
            'target_path' => $targetPath,
            'task_packet_id' => (string) $spec['task_packet_id'],
            'refusal' => false,
        ]);
        $this->reflect($scope, $model->snapshotId, 'served', $targetPath, (string) $spec['objective']);

        return $this->emit([
            'status' => 'served',
            'scope' => $scope,
            'journal' => $journalPath,
            'packet' => ['specs' => ['packets' => [$spec]]],
        ] + $this->priorBriefsFor($scope) + $this->scopeSignalsFor($scope, $model, $ledger));
    }

    /**
     * PRIOR BRIEFS — top-5 action_hint reflections (the time series L14 populates each cycle). Same call on
     * served/refused/abstain so the brain always sees its own recent recommendations alongside the live one,
     * making perseveration (5×rotate_path with no rotation actually happening) detectable without an extra call.
     * Flag-gated inside the stream itself (reflection_enabled OFF ⇒ [] ⇒ no payload key).
     *
     * @return array<string,mixed>
     */
    private function priorBriefsFor(string $scope): array
    {
        $priorBriefs = app(AtlasBrainReflectionStream::class)->recallTexts($scope, ['signals' => ['action_hint' => '']], 5);

        return $priorBriefs === [] ? [] : ['prior_briefs' => $priorBriefs];
    }

    /**
     * COMPREHENSION-DEEPENING seam: return ['scope_signals' => digest] when the flag is ON, else []. Lives at
     * the wiring site (not inside the digest organ) so the organ stays pure. OFF ⇒ byte-identical payload.
     *
     * @return array<string,mixed>
     */
    private function scopeSignalsFor(string $scope, AtlasLoopScopeComprehensionModel $model, AtlasBrainDoneSetLedger $ledger): array
    {
        if (! $this->forceScopeSignals && ! (bool) config('atlas.brain.scope_signal_digest_enabled', false)) {
            return [];
        }
        $digest = app(AtlasBrainStructuralSignalDigest::class)->digest($model);
        // FRONTIER-HARVEST source: top-K curated candidates the operator (or a future ingestion organ) has
        // appended for THIS scope. Read at the same wiring seam so frontier shows up alongside structural
        // signals in one payload key — the brain doesn't need a separate fetch.
        $frontier = app(AtlasBrainFrontierSourceRegistry::class)->topK($scope);
        if ($frontier === []) {
            $frontier = $this->frontierDiscoveryCandidates();
        }
        // COMPOUNDING summary: tail-window of the per-scope done-set. NEVER a learning scalar — counts +
        // success streak only (anti-Goodhart). window=0 ⇒ ledger is empty / brand new scope; skip surfacing.
        $compounding = app(AtlasBrainCompoundingDigest::class)->digest($ledger);
        $hasCompounding = ($compounding['window'] ?? 0) > 0;

        if ($digest['orphans'] === [] && $digest['clone_clusters'] === [] && $digest['doc_stated_gaps'] === [] && $frontier === [] && ! $hasCompounding) {
            return []; // nothing to surface ⇒ stay quiet rather than emit an empty key
        }

        // PORTFOLIO ROUTER: deterministic signal→path recommendation alongside the raw signals. Surface BOTH
        // so the brain sees the recommendation but still has the underlying facts to override it (author≠judge).
        $route = app(AtlasBrainPortfolioRouter::class)->route([
            'orphans' => $digest['orphans'],
            'clone_clusters' => $digest['clone_clusters'],
            'doc_stated_gaps' => $digest['doc_stated_gaps'],
        ]);

        $signals = $digest + [
            'recommended_path' => $route['recommended_path'],
            'recommended_path_reason' => $route['reason'],
            'signal_class' => $route['signal_class'],
            'frontier_candidates' => $frontier,
            'compounding' => $hasCompounding ? $compounding : null,
            // METRICS-OPTIMIZATION: declared measurable facts the brain should be optimizing (counts
            // + tail-streak — never a learned scalar). Always present when scope_signals fires — the
            // metric list is a contract, even values of 0 are signal ("nothing to push here").
            'metrics' => app(AtlasBrainMetricSnapshot::class)->snapshot($model, $ledger)['metrics'],
            // GATE HEALTH — runtime adversarial audit of inspector + seed-gate. zero holes = airtight;
            // non-zero IS the highest-leverage next slice (the brain originates against a real regression).
            'gate_health' => [
                'inspector_holes' => count(app(AtlasBrainGateAdversarialAuditor::class)->audit(new AtlasTaskPacketQualityInspector)['holes']),
                'seed_gate_holes' => count(app(AtlasBrainSeedGateAdversarialAuditor::class)->audit(app(AtlasBrainSeedQualityGate::class))['holes']),
            ],
        ];
        // BRAIN-AS-AUTHOR EMBRYO (L9 wired): when the digest surfaces orphans, draft top-K candidate specs
        // (each passes the inspector by construction — see AtlasBrainOrphanSpecDrafterTest). The brain still
        // chooses + the gate still vets; drafts are SUGGESTIONS, not seeds. Null drafts (the drafter's
        // fail-closed) are filtered out so the brain never sees a bad shape.
        $drafts = app(AtlasBrainOrphanSpecDrafter::class)->draftAll(
            array_slice(array_map('strval', (array) ($signals['orphans'] ?? [])), 0, 3),
            $scope,
        );
        if ($drafts !== []) {
            $signals['drafted_candidates'] = $drafts;
            // SIMULATION TWIN ranks the drafts via the LIVE inspector + picks the cleanest. Brain reads
            // `recommended_draft` (a task_packet_id) and can seed it directly without re-evaluating.
            $sim = app(AtlasBrainSpecSimulationTwin::class)->simulate($drafts, new AtlasTaskPacketQualityInspector);
            if ($sim['winner'] !== null) {
                $signals['recommended_draft'] = $sim['winner'];
            }
        }
        // INTEGRATION: leverage_brief is the consolidated read over the ASSEMBLED signals block —
        // ONE action hint + top-3 evidence cues + rationale. Computed last so it sees every signal
        // the brain just produced (including drafted_candidates) AND its own prior-brief time series
        // (so it can detect perseveration via the L14 reflection feed without an extra call).
        $priorBriefs = app(AtlasBrainReflectionStream::class)->recallTexts($scope, ['signals' => ['action_hint' => '']], AtlasBrainLeverageBrief::PERSEVERATION_STREAK_THRESHOLD);
        $signals['leverage_brief'] = app(AtlasBrainLeverageBrief::class)->brief($signals, $priorBriefs);

        // TIME-SERIES of recommendations: record the brief as a Reflexion note so the next cycle can
        // recall "what we recommended for this scope last time" and see whether it converged. Flag-gated
        // by reflection_enabled inside the stream itself (OFF ⇒ no-op, byte-identical).
        app(AtlasBrainReflectionStream::class)->record([
            'scope' => $scope,
            'reflection' => 'leverage_brief: '.((string) ($signals['leverage_brief']['action_hint'] ?? '')).' — '.((string) ($signals['leverage_brief']['rationale'] ?? '')),
            'cycle_id' => $model->snapshotId,
            'result_kind' => AtlasBrainReflectionStream::KIND_NOTE,
            'signals' => ['action_hint' => (string) ($signals['leverage_brief']['action_hint'] ?? '')],
        ]);

        return ['scope_signals' => $signals];
    }

    /**
     * @return list<array<string,string>>
     */
    private function frontierDiscoveryCandidates(): array
    {
        return array_values(array_map(
            static fn (array $source): array => [
                'title' => 'Harvest frontier from '.$source['url_pattern'],
                'url' => $source['url_pattern'],
                'summary' => 'Operator-seeded discover source; harvest techniques, then read/ground via github/arxiv.',
                'source' => 'research-source-registry',
                'captured_at' => '',
            ],
            array_slice(app(AtlasBrainResearchSourceRegistry::class)->forTier('discover'), 0, AtlasBrainFrontierSourceRegistry::DEFAULT_K),
        ));
    }

    /** @return array<string,string>|null */
    private function discardDoneTempSpec(AtlasBrainDoneSetLedger $ledger): ?array
    {
        $actor = $this->heartbeatActor;
        if ($actor === '' || str_contains($actor, '/') || str_contains($actor, "\0")) {
            return null;
        }

        $path = '/tmp/brain-'.$actor.'.json';
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);
        $packet = is_array($decoded) && is_array($decoded['packets'][0] ?? null) ? $decoded['packets'][0] : [];
        $target = ltrim((string) (((array) ($packet['allowed_files'] ?? []))[0] ?? ''), '/');
        if ($target === '' || ! $ledger->isDone($target)) {
            return null;
        }

        $discarded = @unlink($path);

        return [
            'schema' => 'atlas.brain.temp_spec_recovery.v1',
            'action' => $discarded ? 'discarded_done_set_spec' : 'discard_failed',
            'target_path' => $target,
        ];
    }

    /**
     * Map the translator spec into the shape the inspector/seed-quality gate read (evidence_requirements →
     * required_evidence). The gate's clean-packet contract uses required_evidence as a flat list.
     *
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    private function inspectorShape(array $spec): array
    {
        $scope = trim((string) ($spec['brain_scope'] ?? $spec['lane_scope'] ?? $spec['scope'] ?? $this->argument('scope') ?? ''));

        return [
            'objective' => (string) ($spec['objective'] ?? ''),
            'allowed_files' => array_values((array) ($spec['allowed_files'] ?? [])),
            'scope_in' => array_values((array) ($spec['scope_in'] ?? [])),
            'acceptance_criteria' => array_values((array) ($spec['acceptance_criteria'] ?? [])),
            'required_evidence' => array_values((array) ($spec['evidence_requirements'] ?? [])),
            'problem' => (string) ($spec['problem'] ?? ''),
            'expected_delta' => (string) ($spec['expected_delta'] ?? ''),
            'value' => (string) ($spec['value'] ?? ''),
            'duplicate_key' => (string) ($spec['duplicate_key'] ?? ''),
            'freshness_check' => (string) ($spec['freshness_check'] ?? ''),
            'anti_proxy' => (string) ($spec['anti_proxy'] ?? ''),
            'modifies_existing_files' => (bool) ($spec['modifies_existing_files'] ?? false),
            'existing_file_delta' => (string) ($spec['existing_file_delta'] ?? ''),
            'brain_scope' => $scope,
            'objective_kind' => (string) ($spec['objective_kind'] ?? ''),
            'alternatives_compared' => (array) ($spec['alternatives_compared'] ?? []),
        ];
    }

    /**
     * @param  array<string,mixed>  $spec
     */
    private function classifyClass(array $spec, AtlasLoopScopeComprehensionModel $model): string
    {
        try {
            return (string) (app(AtlasBrainEvolutionLevelClassifier::class)->classify((string) ($spec['objective'] ?? ''), $model)['class'] ?? '');
        } catch (Throwable) {
            return '';
        }
    }

    /** @return list<string> the most-recent done-set target paths (context for the originator). */
    private function priorTargets(AtlasBrainDoneSetLedger $ledger, int $max): array
    {
        if ($max <= 0) {
            return [];
        }
        $targets = [];
        foreach ($ledger->recentCycles($max) as $row) {
            $t = trim((string) ($row['target_path'] ?? ''));
            if ($t !== '') {
                $targets[$t] = true;
            }
        }

        return array_keys($targets);
    }

    /**
     * S215 — per-target REFUSAL MEMORY for origination. Counts, per target_path, the brain's prior
     * TARGET-INTRINSIC refusals (forbidden_target, prepare_blocked) from the done-set tail. Excludes
     * environmental/served statuses: 'already_done' is the sticky-dedup (handled by isDone() upstream),
     * and a served target is never re-originated anyway. The map steers leverage-first origination away
     * from targets that keep getting refused for a reason intrinsic to the target itself.
     *
     * @return array<string,int>
     */
    private function buildRefusalCounts(AtlasBrainDoneSetLedger $ledger): array
    {
        $intrinsic = ['forbidden_target', 'prepare_blocked'];
        $counts = [];
        foreach ($ledger->recentCycles(100000) as $row) {
            if ((bool) ($row['refusal'] ?? false) !== true) {
                continue;
            }
            if (! in_array((string) ($row['status'] ?? ''), $intrinsic, true)) {
                continue;
            }
            $t = trim((string) ($row['target_path'] ?? ''));
            if ($t !== '') {
                $counts[$t] = ($counts[$t] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Record ONE semantic reflection fact for this cycle (Reflexion memory). Flag-gated inside the stream
     * (reflection_enabled OFF ⇒ no-op, byte-identical). No-scalar: stores the status+note text, never a score.
     */
    private function reflect(string $scope, string $snapshotId, string $status, string $targetPath, string $note): void
    {
        $kind = match ($status) {
            'served' => AtlasBrainReflectionStream::KIND_SUCCESS,
            'abstain' => AtlasBrainReflectionStream::KIND_NOTE,
            default => AtlasBrainReflectionStream::KIND_BLOCKED,
        };
        $text = trim($status.': '.$note);

        app(AtlasBrainReflectionStream::class)->record([
            'scope' => $scope,
            'reflection' => $text !== '' ? $text : $status,
            'cycle_id' => $snapshotId,
            'result_kind' => $kind,
            'signals' => $targetPath !== '' ? ['target_path' => $targetPath] : [],
        ]);
    }

    private function recordRefusal(AtlasBrainDoneSetLedger $ledger, string $snapshotId, string $status, string $action, string $targetPath): void
    {
        $ledger->record([
            'snapshot_id' => $snapshotId,
            'status' => $status,
            'produced' => false,
            'action' => $action,
            'target_path' => $targetPath,
            'task_packet_id' => '',
            'refusal' => true,
        ]);
    }

    /**
     * Build ONE comprehension model spanning EVERY root of the scope (multi-root → merged): the brain SEES the
     * whole autonomous block, not one subtree.
     *
     * @param  list<string>  $roots
     * @param  list<string>  $docsRoots
     */
    private function comprehend(string $repoRoot, array $roots, array $docsRoots): AtlasLoopScopeComprehensionModel
    {
        $builder = app(AtlasLoopScopeComprehensionModelBuilder::class);
        $opts = $docsRoots !== [] ? ['docs_roots' => $docsRoots] : [];
        $models = [];
        foreach ($roots as $root) {
            $models[] = $builder->build($repoRoot, $root, $opts);
        }

        return $this->mergeModels($models);
    }

    /**
     * Merge per-root models into one. Roots are disjoint subtrees, so inventory/edges/docPurposes concat
     * cleanly; orphans/forbidden/docStatedGaps are de-duped + sorted; snapshotId is a deterministic hash.
     *
     * @param  list<AtlasLoopScopeComprehensionModel>  $models
     */
    private function mergeModels(array $models): AtlasLoopScopeComprehensionModel
    {
        $models = array_values(array_filter($models));
        if (count($models) === 1) {
            return $models[0];
        }

        $inventory = $edges = $clones = $docPurposes = [];
        $orphans = $forbidden = $docGaps = $snaps = [];
        foreach ($models as $m) {
            $inventory = array_merge($inventory, $m->inventory);
            $edges += $m->edges;
            $clones = array_merge($clones, $m->cloneClusters);
            $docPurposes += $m->docPurposes;
            $orphans = array_merge($orphans, $m->orphans);
            $forbidden = array_merge($forbidden, $m->forbidden);
            $docGaps = array_merge($docGaps, $m->docStatedGaps);
            $snaps[] = $m->snapshotId;
        }
        $orphans = array_values(array_unique($orphans));
        $forbidden = array_values(array_unique($forbidden));
        $docGaps = array_values(array_unique($docGaps));
        sort($orphans);
        sort($forbidden);
        sort($docGaps);

        return new AtlasLoopScopeComprehensionModel(
            $inventory, $edges, $orphans, $clones, $forbidden, $docPurposes, $docGaps, sha1(implode('|', $snaps)),
        );
    }

    /** @param array<string,mixed> $payload */
    private function emit(array $payload, int $code = self::SUCCESS): int
    {
        if ($this->tempSpecRecovery !== null) {
            $payload['temp_spec_recovery'] = $this->tempSpecRecovery;
        }

        if ($this->heartbeatActor !== '') {
            app(AtlasBrainHeartbeatLedger::class)->record(
                (string) ($payload['scope'] ?? $this->heartbeatScope),
                [
                    'actor' => $this->heartbeatActor,
                    'command' => 'next',
                    'status' => (string) ($payload['status'] ?? ''),
                ]
            );
        }

        $this->line($this->encode($payload));

        return $code;
    }
}
