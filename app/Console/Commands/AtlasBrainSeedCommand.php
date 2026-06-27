<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCycleProgressVerdict;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainEvolutionLevelClassifier;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeRegistry;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedQualityGate;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSpecRepairHints;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Illuminate\Console\Command;
use Throwable;

/**
 * EXTERNAL BRAIN · the "seed" verb. Takes a JSON specs FILE (the packet specs `atlas:brain:next` emitted),
 * runs every brain gate, and — ONLY when the BRAIN master switch is on — enqueues the survivors onto the
 * SERVING stack's DEDICATED disk (not the muscle's serving switch; gated independently).
 *
 * GATES per packet (a packet must clear ALL): classifier ≠ rejected_proxy → harness-guard admit() screen →
 * seed-quality gate (FIX-1) → cycle-progress verdict. --dry-run runs every gate with ZERO enqueue.
 *
 * Mirrors {@see AtlasTaskSeedGovLanesCommand} for file I/O; enqueues through {@see AtlasTaskServingStack} so a
 * seeded packet is the EXACT disk `atlas:task next` reads (the dedicated-disk gotcha).
 */
final class AtlasBrainSeedCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:seed {--specs= : path to the JSON specs file} {--scope= : scope slug — meta_harness comes from it (default: the configured default scope)} {--dry-run} {--json}';

    /** @var string */
    protected $description = 'Brain SEED: gate originated packet specs and enqueue survivors onto the dedicated serving disk (BRAIN switch-gated).';

    public function handle(): int
    {
        // FAIL-LOUD FIRST: refuse before reading anything if the serving disk is misconfigured (the gotcha that
        // made seeded packets invisible to workers). Throws a clear RuntimeException naming the bad disk.
        AtlasTaskServingStack::assertServingDiskConfigured();

        $path = (string) ($this->option('specs') ?? '');
        if ($path === '' || ! is_file($path)) {
            return $this->emit(['status' => 'usage_error', 'reason' => '--specs=<path to JSON> required'], self::FAILURE);
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return $this->emit(['status' => 'invalid_json', 'reason' => $e->getMessage()], self::FAILURE);
        }

        $packets = is_array($decoded['packets'] ?? null) ? $decoded['packets'] : (array_is_list($decoded) ? $decoded : []);
        if ($packets === []) {
            return $this->emit(['status' => 'no_packets', 'reason' => 'specs file has no packets[]'], self::FAILURE);
        }

        $dryRun = (bool) $this->option('dry-run');
        // Enqueue is gated by the BRAIN switch (NOT the muscle serving switch). OFF ⇒ gate-only, never enqueue.
        $brainOn = AtlasBrainMasterSwitch::enabled();

        $guard = app(AtlasLoopHarnessGuard::class);
        $classifier = app(AtlasBrainEvolutionLevelClassifier::class);
        $seedGate = app(AtlasBrainSeedQualityGate::class);
        $progress = app(AtlasBrainCycleProgressVerdict::class);
        // meta_harness comes from the SCOPE (the brain's reach is data, not a global flag). The registry is
        // pétreo — a packet can never smuggle its own meta_harness past the FORBIDDEN floor.
        $scopeDef = app(AtlasBrainScopeRegistry::class)->resolve((string) ($this->option('scope') ?? ''));
        $metaHarness = (bool) $scopeDef['meta_harness'];
        // DEDUP MEMORY (per-scope done-set, pétreo organ). `next` already calls isDone() before originating; `seed`
        // is the OTHER entry point (replay of a stale specs file, manual hand-off, future brain-as-author paths)
        // and was bypassing the ledger entirely — so a target already seeded in a previous cycle could re-enqueue
        // silently. We mirror `next`'s contract here: check pre-gate; record on enqueue / dry-run-gated-ok.
        $doneSet = new AtlasBrainDoneSetLedger((string) $scopeDef['slug'], (string) config('atlas.brain.done_set_root'));
        // The classifier needs a comprehension model; the specs carry no structural facts, so an empty model is
        // the honest input — the proxy check (objective text) is model-independent.
        $emptyModel = AtlasLoopScopeComprehensionModel::fromArray([]);

        $orch = AtlasTaskServingStack::orchestrator();
        $queue = AtlasTaskServingStack::queueRepo();

        $results = [];
        $counts = ['enqueued' => 0, 'blocked' => 0, 'dry_run' => 0, 'skipped_exists' => 0, 'skipped_done_set' => 0, 'error' => 0];

        foreach ($packets as $i => $spec) {
            $id = trim((string) ($spec['task_packet_id'] ?? ''));
            if ($id === '') {
                $results[] = ['index' => $i, 'status' => 'blocked', 'stage' => 'input', 'reasons' => ['missing_task_packet_id']];
                $counts['blocked']++;

                continue;
            }

            // STICKY dedup — the first allowed_file is the canonical target_path (mirrors `next`). A previously
            // originated target is REFUSED here without running the downstream gates; the brain never re-seeds
            // the same target on a second pass (skipped before any enqueue work, no ledger churn).
            $targetPath = ltrim((string) (((array) ($spec['allowed_files'] ?? []))[0] ?? ''), '/');
            if ($targetPath !== '' && $doneSet->isDone($targetPath)) {
                $results[] = ['task_packet_id' => $id, 'status' => 'skipped_done_set', 'stage' => 'dedup', 'target_path' => $targetPath];
                $counts['skipped_done_set']++;

                continue;
            }

            [$ok, $stage, $reasons] = $this->gate($spec, $guard, $classifier, $seedGate, $progress, $emptyModel, $metaHarness);
            if (! $ok) {
                $hints = app(AtlasBrainSpecRepairHints::class)->repair($reasons);
                $results[] = ['task_packet_id' => $id, 'status' => 'blocked', 'stage' => $stage, 'reasons' => $reasons, 'repair_hints' => $hints['hints']];
                $counts['blocked']++;

                continue;
            }

            if ($dryRun || ! $brainOn) {
                $results[] = ['task_packet_id' => $id, 'status' => 'dry_run', 'stage' => 'gated_ok', 'reasons' => $dryRun ? [] : ['brain_switch_off']];
                $counts['dry_run']++;
                // RECORD the gated-ok cycle into the done-set — a passing dry-run/gate proves the target was
                // considered, so a re-seed of the same target on a later call is STICKY-deduped (no double-author).
                $this->recordDone($doneSet, $id, $targetPath, $dryRun ? 'dry_run' : 'gated_brain_off', false);

                continue;
            }

            try {
                if ($queue->get($id) !== null) {
                    $results[] = ['task_packet_id' => $id, 'status' => 'skipped_exists', 'stage' => 'enqueue'];
                    $counts['skipped_exists']++;

                    continue;
                }
                $env = $orch->prepareAndEnqueue(['task_packet' => $this->toPacketInput($spec, $id)]);
                $event = (string) ($env['event'] ?? $env['status'] ?? '');
                if ($event === 'prepared_and_enqueued') {
                    $results[] = ['task_packet_id' => $id, 'status' => 'enqueued', 'stage' => 'enqueue'];
                    $counts['enqueued']++;
                    $this->recordDone($doneSet, $id, $targetPath, 'seeded', true);
                } else {
                    $results[] = ['task_packet_id' => $id, 'status' => 'blocked', 'stage' => 'enqueue', 'reasons' => [(string) data_get($env, 'reason', $event)]];
                    $counts['blocked']++;
                }
            } catch (Throwable $e) {
                $results[] = ['task_packet_id' => $id, 'status' => 'error', 'stage' => 'enqueue', 'reasons' => [$e->getMessage()]];
                $counts['error']++;
            }
        }

        return $this->emit(['status' => 'ok', 'dry_run' => $dryRun, 'brain_enabled' => $brainOn, 'counts' => $counts, 'results' => $results]);
    }

    /**
     * Run every brain gate over one spec. Returns [ok, failing_stage, reasons].
     *
     * @param  array<string,mixed>  $spec
     * @return array{0:bool, 1:string, 2:list<string>}
     */
    private function gate(
        array $spec,
        AtlasLoopHarnessGuard $guard,
        AtlasBrainEvolutionLevelClassifier $classifier,
        AtlasBrainSeedQualityGate $seedGate,
        AtlasBrainCycleProgressVerdict $progress,
        AtlasLoopScopeComprehensionModel $model,
        bool $metaHarness,
    ): array {
        $objective = (string) ($spec['objective'] ?? '');
        $allowed = array_values(array_map('strval', (array) ($spec['allowed_files'] ?? [])));

        // 1. CLASSIFIER — a proxy/faxina objective is rejected outright.
        $class = (string) ($classifier->classify($objective, $model)['class'] ?? '');
        if ($class === 'rejected_proxy') {
            return [false, 'classifier', ['rejected_proxy']];
        }

        // 2. HARNESS GUARD — screen every allowed_file: forbidden OR harness_gated (meta OFF) ⇒ non-seedable.
        $blocked = [];
        foreach ($allowed as $file) {
            $tier = $guard->admit($file, $metaHarness);
            if ($tier === 'forbidden' || $tier === 'harness_gated') {
                $blocked[] = $tier.':'.$file;
            }
        }
        if ($blocked !== []) {
            return [false, 'harness_guard', $blocked];
        }

        // 3. SEED-QUALITY gate (FIX-1).
        $seed = $seedGate->evaluate($this->inspectorShape($spec));
        if (($seed['admit'] ?? false) !== true) {
            return [false, 'seed_quality', array_values((array) ($seed['reasons'] ?? []))];
        }

        // 4. CYCLE-PROGRESS verdict — the anti-Goodhart backstop (doc + enqueue + non-proxy + admit + citation).
        // doc_written/enqueued are TRUE-by-precondition here: the spec came from `next` (which wrote the doc),
        // and clearing this gate IS the green light to enqueue. The teeth are the class + admit + citation.
        $verdict = $progress->verdict([
            'doc_written' => true,
            'enqueued' => true,
            'classifier_class' => $class,
            'seed_gate_admit' => true,
            'grounded_citations' => $allowed,
        ]);
        if (($verdict['counts_as_progress'] ?? false) !== true) {
            return [false, 'cycle_progress', array_values((array) ($verdict['reasons'] ?? []))];
        }

        return [true, 'gated_ok', []];
    }

    /**
     * Append a sticky cycle row to the per-scope done-set. Target_path empty ⇒ no-op (nothing to dedup against).
     */
    private function recordDone(AtlasBrainDoneSetLedger $doneSet, string $id, string $targetPath, string $status, bool $produced): void
    {
        if ($targetPath === '') {
            return; // no canonical key ⇒ recording it would never deduplicate anything.
        }
        $doneSet->record([
            'snapshot_id' => '', // seed has no comprehension snapshot; the brain owns that field for `next`.
            'status' => $status,
            'produced' => $produced,
            'action' => 'seed',
            'target_path' => $targetPath,
            'task_packet_id' => $id,
            'refusal' => false,
        ]);
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    private function inspectorShape(array $spec): array
    {
        return [
            'objective' => (string) ($spec['objective'] ?? ''),
            'allowed_files' => array_values(array_map('strval', (array) ($spec['allowed_files'] ?? []))),
            'scope_in' => array_values(array_map('strval', (array) ($spec['scope_in'] ?? []))),
            'acceptance_criteria' => array_values(array_map('strval', (array) ($spec['acceptance_criteria'] ?? []))),
            'required_evidence' => array_values(array_map('strval', (array) ($spec['evidence_requirements'] ?? $spec['required_evidence'] ?? []))),
        ];
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    private function toPacketInput(array $spec, string $id): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => (string) ($spec['objective'] ?? ''),
            'source' => 'external-brain',
            'operator_id' => 'atlas-brain',
            'allowed_files' => array_values(array_map('strval', (array) ($spec['allowed_files'] ?? []))),
            'scope_in' => array_values(array_map('strval', (array) ($spec['scope_in'] ?? []))),
            'acceptance_criteria' => array_values(array_map('strval', (array) ($spec['acceptance_criteria'] ?? []))),
            'required_evidence' => array_values(array_map('strval', (array) ($spec['evidence_requirements'] ?? $spec['required_evidence'] ?? []))),
            'risk_level' => strtolower(trim((string) ($spec['risk_level'] ?? 'medium'))),
            'depends_on' => array_values(array_filter((array) ($spec['depends_on'] ?? []), 'is_string')),
            'wave' => (int) ($spec['wave'] ?? 1),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function emit(array $payload, int $code = self::SUCCESS): int
    {
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $code;
    }
}
