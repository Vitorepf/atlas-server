<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\AtlasLoopOriginationPipeline;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainEvolutionDocAuthor;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainEvolutionLevelClassifier;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeDryProbe;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeRegistry;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedQualityGate;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainTaskSpecTranslator;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModelBuilder;
use Illuminate\Console\Command;
use Throwable;

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
    /** @var string */
    protected $signature = 'atlas:brain:next {scope : the scope slug (e.g. loop)}
        {--repo= : repo root (default base_path)}
        {--docs=* : canonical doc roots for doc-stated-gap detection}
        {--m=3 : consecutive refusals the dry-probe requires before declaring the scope dry}
        {--max-prior=50 : how many prior done-set targets to feed the originator as context}
        {--json}';

    /** @var string */
    protected $description = 'Brain DECIDE: originate + design the next grounded evolution spec for a scope (author≠judge — writes only docs/ + ledger).';

    public function handle(): int
    {
        // §0 — fail-CLOSED master gate. OFF ⇒ clean no-op (never a crash), gated independently of the muscle.
        if (! AtlasBrainMasterSwitch::enabled()) {
            return $this->emit(['status' => 'disabled', 'reason' => 'brain_master_switch_off']);
        }

        $scope = trim((string) $this->argument('scope')) ?: 'loop';
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
        $model = $this->comprehend($repoRoot, $scopeDef['roots'], $docsRoots !== [] ? $docsRoots : $scopeDef['docs_roots']);

        $ledger = new AtlasBrainDoneSetLedger($scope, (string) config('atlas.brain.done_set_root'));

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
        $origination = app(AtlasLoopOriginationPipeline::class)->produce($model, $repoRoot, $priorAttempts);

        $produced = (bool) ($origination['produced'] ?? false);
        $action = (string) ($origination['action'] ?? '');
        // Refusal for dry-purposes: nothing produced OR an abstain (park-and-ask) — REGARDLESS of `produced`.
        if (! $produced || $action === 'abstain') {
            $status = ! $produced ? 'refused' : 'abstain';
            $this->recordRefusal($ledger, $model->snapshotId, $status, $action, (string) ($origination['target_path'] ?? ''));

            return $this->emit([
                'status' => $status,
                'scope' => $scope,
                'reason' => (string) ($origination['reason'] ?? ($action === 'abstain' ? 'parked_and_asked' : 'not_originated')),
                'operator_question' => $origination['operator_question'] ?? null,
            ]);
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
        ], $model->inventory, (string) config('atlas.brain.journal_root', 'docs/loop-evolution-journal'));

        $ledger->record([
            'snapshot_id' => $model->snapshotId,
            'status' => 'served',
            'produced' => true,
            'action' => $action,
            'target_path' => $targetPath,
            'task_packet_id' => (string) $spec['task_packet_id'],
            'refusal' => false,
        ]);

        return $this->emit([
            'status' => 'served',
            'scope' => $scope,
            'journal' => $journalPath,
            'packet' => ['specs' => ['packets' => [$spec]]],
        ]);
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
        return [
            'objective' => (string) ($spec['objective'] ?? ''),
            'allowed_files' => array_values((array) ($spec['allowed_files'] ?? [])),
            'scope_in' => array_values((array) ($spec['scope_in'] ?? [])),
            'acceptance_criteria' => array_values((array) ($spec['acceptance_criteria'] ?? [])),
            'required_evidence' => array_values((array) ($spec['evidence_requirements'] ?? [])),
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
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $code;
    }
}
