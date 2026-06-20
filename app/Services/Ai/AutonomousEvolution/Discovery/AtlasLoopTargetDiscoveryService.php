<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Models\AtlasLoopTarget;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The "Sources" stage at repo scale — DETERMINISTIC, PROVIDER-FREE, cheap enough to
 * re-run on every refill. Walks the configured scan roots, scores every admissible
 * file, and upserts the top candidates into the target ledger so the supervisor can
 * pull the highest-value ones first.
 *
 * The honest predictor of "a plain-`php` test can pin this" is STATIC
 * framework_reach==0 (NOT a require-probe — a documented trap: `require` of a file
 * that `use`s App\Models passes because PHP `use` is lazy, so the file looks runnable
 * when it is not). Hard admissibility gate FIRST (framework_reach==0 AND 40<=LOC<=400
 * AND `php -l` clean AND declares a class/enum/trait), then composite score =
 * 0.55*S + 0.30*I + 0.15*N. It surfaces ONLY self-contained targets the cp -R +
 * plain-`php` grind can honestly run, and never asserts the improvement is real — the
 * generator's RED-verification downstream is the authoritative "is this real work" gate.
 */
final class AtlasLoopTargetDiscoveryService
{
    public const SCHEMA = 'atlas.loop.target_discovery.v1';

    /** Static signals that make a file NON-self-contained (any hit => framework_reach > 0). */
    private const REACH_PATTERNS = [
        '/^\s*use\s+(App|Illuminate|Symfony|Laravel)\\\\/mi',
        '/\bextends\s+\w*(Model|Controller|Command|Facade|Migration|Request|Middleware|Job|Notification|Mailable|Seeder|ServiceProvider)\b/',
        '/\b(DB|Schema|Cache|Queue|Storage|Http|Route|Event|Log|Config|Auth|Gate|Mail|Bus|Redis)::/',
        '/\b(app|resolve|config|event|dispatch|cache|now|response|view|abort|report)\s*\(/',
        '/@(test|dataProvider|covers)\b/',
    ];

    public function __construct(
        private readonly AtlasLoopTargetRepository $repository,
        private readonly ?AtlasLoopEvidenceSignalService $evidence = null,
        private readonly ?AtlasLoopBacklogIntentSource $backlog = null,
        private readonly ?\App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard $harnessGuard = null,
        private readonly ?AtlasLoopWiredCallerService $wiredCallers = null,
        private readonly ?AtlasLoopSiblingTestResolver $siblingTests = null,
        private readonly ?\App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer $signalAnalyzer = null,
        private readonly ?AtlasLoopCoverageDeficitSource $coverageDeficit = null,
        private readonly ?AtlasLoopCloneDetector $cloneDetector = null,
        private readonly ?AtlasLoopFailureHandleSource $failureHandles = null,
    ) {}

    /** Lazily-built deterministic AST cyclomatic analyzer (the honest complexity signal). */
    private function analyzer(): \App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer
    {
        return $this->signalAnalyzer ?? new \App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer();
    }

    /**
     * @param  array{roots?:list<string>, limit?:int, max_files?:int, already_proposed_paths?:list<string>}  $options
     * @return array{schema_version:string, scanned:int, admissible:int, upserted:int, top:list<array{path:string,score:float}>}
     */
    public function discover(string $repoRoot, string $campaignId, array $options = []): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $roots = $options['roots'] ?? (array) config('atlas.loop.campaign.discovery_roots', ['app/Services']);
        $limit = max(1, (int) ($options['limit'] ?? 12));
        $maxFiles = max(1, (int) ($options['max_files'] ?? 1200));
        $alreadyProposed = array_flip($options['already_proposed_paths'] ?? $this->repository->alreadyProposedPaths($campaignId));
        $heartbeatCampaignId = (string) ($options['heartbeat_campaign_id'] ?? $campaignId);
        $this->touchHeartbeat($heartbeatCampaignId);

        $scanned = 0;
        $admissible = 0;
        $scoredRows = [];

        foreach ($this->phpFiles($repoRoot, $roots, $maxFiles) as $abs) {
            $scanned++;
            $rel = ltrim(str_replace($repoRoot, '', $abs), '/');
            $scored = $this->scoreCandidate($abs, $rel, ['already_proposed' => $alreadyProposed]);
            $this->touchHeartbeat($heartbeatCampaignId);
            if ($scored === null) {
                continue;
            }
            $admissible++;
            $scoredRows[] = ['path' => $rel, 'abs' => $abs, 'scored' => $scored];
        }
        $this->touchHeartbeat($heartbeatCampaignId);

        // O-2 slice (b): blend a REAL-evidence term into the structural score so the loop
        // discovers what actually breaks. evidence = recurrence in the failure corpus.
        // final = 0.72 * structural + 0.28 * evidence. Fail-open: empty corpus => structural
        // unchanged (every evidence weight 0, the 0.72 factor only rescales uniformly).
        $evidenceWeights = $this->evidence?->weights(array_column($scoredRows, 'path')) ?? [];
        foreach ($scoredRows as &$row) {
            $ev = (float) ($evidenceWeights[$row['path']] ?? 0.0);
            $row['scored']['evidence'] = round($ev, 4);
            $row['scored']['signals']['failure_evidence'] = $ev;
            $row['scored']['score'] = round(0.72 * (float) $row['scored']['score'] + 0.28 * $ev, 4);
        }
        unset($row);

        // S3 FAILURE-HANDLE STAMP: the REAL bug-fix work-supply seam. A target whose path matches a
        // harvested DETERMINISTIC-RED handle gets the runnable failure handle stamped onto its
        // discovery signals (failure_test_path / failure_command / failing_assertion / failure_message).
        // Those signals persist via repository->upsert(...$row['scored']) into atlas_loop_targets.signals,
        // which is EXACTLY what the already-wired AtlasLoopQueueRefiller::tryBugReproduction reads to
        // enqueue a FIRST-CLASS bug_fix (red_required + revert_recheck). Without this stamp that lane is
        // STARVED (documented at the refiller gap): nothing else populates those signals on the live path.
        // Flag-gated + fail-open: no flag / no source / no matching handle => NO source call, signals
        // untouched, byte-identical to today.
        if ((bool) config('atlas.loop.discovery_failure_handle_stamp_enabled', false)) {
            $handleSource = $this->failureHandles ?? new AtlasLoopFailureHandleSource();
            $handlesByPath = $handleSource->handlesByPath(array_column($scoredRows, 'path'));
            if ($handlesByPath !== []) {
                foreach ($scoredRows as &$row) {
                    $handle = $handlesByPath[(string) $row['path']] ?? null;
                    if ($handle === null) {
                        continue;
                    }
                    $signals = is_array($row['scored']['signals'] ?? null) ? $row['scored']['signals'] : [];
                    $signals['failure_test_path'] = $handle['failure_test_path'];
                    if ($handle['failure_command'] !== null) {
                        $signals['failure_command'] = $handle['failure_command'];
                    }
                    if ($handle['failing_assertion'] !== null) {
                        $signals['failing_assertion'] = $handle['failing_assertion'];
                    }
                    if ($handle['failure_message'] !== null) {
                        $signals['failure_message'] = $handle['failure_message'];
                    }
                    $signals['failure_handle_recurrence'] = $handle['recurrence_count'];
                    $row['scored']['signals'] = $signals;
                }
                unset($row);
            }
        }

        // L3-2: backlog REAL guiando os intents — em vez de só varrer arquivos ao acaso,
        // injeta alvos com OBJETIVO ESPECÍFICO (manifesto curado + corpus de falhas) e os
        // promove no ranking. É o que ataca os 94% de waste: o provider recebe "corrija X
        // em Y" em vez de "melhore este arquivo". Flag-gated; fail-open (fonte vazia ⇒ no-op).
        if ((bool) config('atlas.loop.discovery_backlog_intents', false) && $this->backlog !== null) {
            $byPath = [];
            foreach ($scoredRows as $i => $row) {
                $byPath[$row['path']] = $i;
            }
            foreach ($this->backlog->candidates($repoRoot, $limit) as $bk) {
                $rel = $bk['path'];
                if (isset($alreadyProposed[$rel])) {
                    continue;
                }
                $abs = $repoRoot.'/'.$rel;
                $signals = ['backlog_reach' => 1.0, 'backlog_objective' => $bk['objective'], 'backlog_source' => $bk['source']];
                // ACDE S1 — carry the self-improvement marker the backlog row preserved into the discovery
                // signals (hop 2 of 3) so it survives to the task payload and ultimately the proposal. Self-
                // gated + additive: an ordinary backlog candidate (no marker) keeps the exact 3-key signals.
                if (($bk['is_self_improvement'] ?? false) === true) {
                    $signals['is_self_improvement'] = true;
                    if (isset($bk['quality_bar'])) {
                        $signals['quality_bar'] = $bk['quality_bar'];
                    }
                }
                // Score de backlog domina o estrutural (mínimo do candidato + prioridade) —
                // o backlog real vem primeiro, mas nunca acima de 1.0.
                $score = min(1.0, 0.85 + 0.15 * (float) $bk['priority']);
                if (isset($byPath[$rel])) {
                    $idx = $byPath[$rel];
                    $scoredRows[$idx]['scored']['score'] = max((float) $scoredRows[$idx]['scored']['score'], $score);
                    $scoredRows[$idx]['scored']['signals'] = array_merge($scoredRows[$idx]['scored']['signals'] ?? [], $signals);
                } elseif (is_file($abs)) {
                    $scoredRows[] = ['path' => $rel, 'abs' => $abs, 'scored' => [
                        'score' => $score, 'self_contained' => 0.0, 'improvement' => (float) $bk['priority'],
                        'novelty' => 1.0, 'evidence' => 0.0, 'signals' => $signals,
                    ]];
                }
            }
        }

        // §11.6 COVERAGE-DEFICIT: score every admissible row on its OWN mutation-survival
        // density (un-killed frozen mutants with NO sibling characterization test = coverage
        // debt). De-parasitizes characterization-test work from the refactor lane. Flag-gated
        // + fail-open: no flag / no source / no sibling resolver => no-op (byte-identical).
        if ((bool) config('atlas.loop.discovery_coverage_deficit_enabled', true)
            && $this->coverageDeficit !== null
            && $this->siblingTests !== null) {
            foreach ($scoredRows as &$row) {
                $rel = (string) $row['path'];
                if (isset($alreadyProposed[$rel])) {
                    continue;
                }
                $hasSibling = $this->siblingTests->hasSibling($rel);
                $cov = $this->coverageDeficit->score((string) $row['abs'], $hasSibling);
                $signals = is_array($row['scored']['signals'] ?? null) ? $row['scored']['signals'] : [];
                $signals['coverage_deficit'] = (float) $cov['deficit'];
                $signals['coverage_deficit_mutants'] = (int) $cov['mutants'];
                $signals['has_sibling_test'] = (bool) $cov['has_sibling_test'];
                $obj = $this->coverageDeficit->deficitObjective($rel, $cov);
                if ($obj !== null) {
                    $signals['coverage_objective'] = $obj['objective'];
                    $signals['shape'] = $obj['shape'];
                    $row['scored']['score'] = round(max(
                        (float) $row['scored']['score'],
                        min(1.0, 0.55 + 0.40 * (float) $cov['deficit']),
                    ), 4);
                }
                $row['scored']['signals'] = $signals;
            }
            unset($row);
        }

        // §11.6 CLONE-DEDUP: detect cross-file structural duplication (name/whitespace-
        // insensitive token Jaccard) so the loop can propose a real dedupe (extract shared
        // logic) instead of letting copy-paste rot. Targets the FIRST file of each pair.
        // Flag-gated + fail-open: no flag / no detector / no clone pairs => no-op.
        if ((bool) config('atlas.loop.discovery_clone_dedup_enabled', true)
            && $this->cloneDetector !== null) {
            $byPath = [];
            foreach ($scoredRows as $i => $row) {
                $byPath[$row['path']] = $i;
            }
            $sources = [];
            foreach ($scoredRows as $row) {
                $src = @file_get_contents((string) $row['abs']);
                if ($src !== false) {
                    $sources[(string) $row['path']] = $src;
                }
            }
            $threshold = max(0.5, min(1.0, (float) config('atlas.loop.clone_similarity_threshold', 0.9)));
            foreach ($this->cloneDetector->detectClones($sources, $threshold) as $pair) {
                $relA = (string) $pair['a'];
                if (isset($alreadyProposed[$relA])) {
                    continue;
                }
                $similarity = (float) ($pair['similarity'] ?? 0.0);
                $signals = [
                    'clone_dedup' => 1.0,
                    'clone_partner' => (string) $pair['b'],
                    'clone_similarity' => round($similarity, 4),
                    'dedup_objective' => $this->cloneDetector->dedupObjective($pair)['objective'],
                    'work_shape' => 'dedup',
                ];
                $promoted = min(1.0, 0.85 + 0.15 * $similarity);
                if (isset($byPath[$relA])) {
                    $idx = $byPath[$relA];
                    $scoredRows[$idx]['scored']['signals'] = array_merge(
                        $scoredRows[$idx]['scored']['signals'] ?? [],
                        $signals,
                    );
                    $scoredRows[$idx]['scored']['score'] = round(max(
                        (float) $scoredRows[$idx]['scored']['score'],
                        $promoted,
                    ), 4);
                } else {
                    $absA = $repoRoot.'/'.$relA;
                    if (is_file($absA)) {
                        $scoredRows[] = ['path' => $relA, 'abs' => $absA, 'scored' => [
                            'score' => round($promoted, 4), 'self_contained' => 0.0, 'improvement' => 1.0,
                            'novelty' => 1.0, 'evidence' => 0.0, 'signals' => $signals,
                        ]];
                        $byPath[$relA] = array_key_last($scoredRows);
                    }
                }
            }
        }

        // L4-1: anti-Goodhart ranking. Structural self-containedness is still the base
        // gate, but the final order now gets a bounded IMPACT boost from real read-model
        // signal (code-indexed symbol surface, failure evidence, and backlog reach).
        if ((bool) config('atlas.loop.impact_ranking_enabled', true)) {
            $this->applyImpactRanking($scoredRows, $heartbeatCampaignId);
        }

        // L?-impact: orphan gate. A target with ZERO real production callers AND no
        // failure evidence AND no backlog reach is dead/orphan scaffolding — improving
        // it is provably zero real-world utility (the measured 54%-orphan defect). Demote
        // it hard so the provider budget flows to code that runs. Fail-open: only fires
        // when the wired-caller service supplied real caller data (signals.orphan set).
        if ((bool) config('atlas.loop.orphan_gate_enabled', true)) {
            $this->applyOrphanGate($scoredRows);
        }

        // SUBSTANTIVE-GRIND: prefer WIRED files that already carry a convention sibling
        // test — those are the only targets where a canary can RUN and go GREEN, the hard
        // requirement for NON_TRIVIAL credit. Bounded boost, default OFF, fail-open (no
        // resolver / no test-backed candidate => ordering unchanged, queue never starves).
        if ((bool) config('atlas.loop.prefer_test_backed_targets', false)) {
            $this->applyTestBackedRanking($scoredRows);
        }

        // L4-1: target cooldown. A recent task/proposal for the same path means the loop
        // already spent attention there; strongly down-rank it so one lucky target cannot
        // farm the queue. Fail-open: missing runtime tables => no cooldown.
        if ((bool) config('atlas.loop.target_cooldown_enabled', true)) {
            $cooldownHours = max(1, (int) config('atlas.loop.target_cooldown_hours', 24));
            $this->applyTargetCooldown(
                $scoredRows,
                $this->repository->recentTargetActivity($campaignId, $cooldownHours),
                $cooldownHours,
            );
        }

        // L3-12: guardrail do meta-loop. O conjunto PROIBIDO (frozen judge, gates,
        // never-merge, este guard) NUNCA é alvo — pétreo, ignora flags/backlog/score (o loop
        // não edita a própria fechadura). Arquivos do harness não-segurança só passam com a
        // flag meta-harness ON. Aplicado no chokepoint final, depois do backlog, antes do upsert.
        $guard = $this->harnessGuard ?? new \App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard();
        $metaHarness = (bool) config('atlas.loop.meta_harness_targets', false);
        $scoredRows = array_values(array_filter(
            $scoredRows,
            static fn (array $row): bool => $guard->admit((string) $row['path'], $metaHarness) === 'admissible',
        ));

        // Highest score first; upsert the top-N as candidates. Crucially, choose from rows
        // that can become claimable now. A long propose-only soak otherwise keeps selecting
        // the same already-consumed top scorers (queued/quarantined with identical content),
        // reports "upserted", and then claimTop() finds zero candidates while lower-ranked
        // untouched files never enter the ledger.
        $scoredRows = $this->claimableRows($campaignId, $scoredRows);
        usort($scoredRows, static fn (array $a, array $b): int => $b['scored']['score'] <=> $a['scored']['score']);
        $top = array_slice($scoredRows, 0, $limit);
        $upserted = 0;
        foreach ($top as $row) {
            $contentHash = (string) ($row['content_hash'] ?? hash('sha256', (string) @file_get_contents($row['abs'])));
            $this->repository->upsert($campaignId, $row['path'], $contentHash, $row['scored'], ['origin' => 'discovery']);
            $upserted++;
        }
        $this->touchHeartbeat($heartbeatCampaignId);

        return [
            'schema_version' => self::SCHEMA,
            'scanned' => $scanned,
            'admissible' => $admissible,
            'upserted' => $upserted,
            'top' => array_map(static fn (array $r): array => ['path' => $r['path'], 'score' => round($r['scored']['score'], 4)], $top),
        ];
    }

    /**
     * @param  list<array{path:string,abs:string,scored:array<string,mixed>}>  $scoredRows
     * @return list<array{path:string,abs:string,scored:array<string,mixed>,content_hash?:string}>
     */
    private function claimableRows(string $campaignId, array $scoredRows): array
    {
        if ($scoredRows === []) {
            return [];
        }

        $paths = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['path'],
            $scoredRows,
        )));
        $existing = AtlasLoopTarget::query()
            ->where('campaign_id', $campaignId)
            ->whereIn('target_path', $paths)
            ->get(['target_path', 'content_hash', 'status', 'attempts', 'max_attempts'])
            ->keyBy('target_path');

        $claimable = [];
        foreach ($scoredRows as $row) {
            $hash = hash('sha256', (string) @file_get_contents($row['abs']));
            $row['content_hash'] = $hash;
            $prior = $existing->get((string) $row['path']);
            if (! $prior instanceof AtlasLoopTarget) {
                $claimable[] = $row;

                continue;
            }
            if ((string) $prior->content_hash !== $hash) {
                $claimable[] = $row;

                continue;
            }
            if ($prior->status === AtlasLoopTarget::STATUS_CANDIDATE && (int) $prior->attempts < (int) $prior->max_attempts) {
                $claimable[] = $row;
            }
        }

        return $claimable;
    }

    /**
     * @param  list<array{path:string,abs:string,scored:array<string,mixed>}>  $scoredRows
     */
    private function applyImpactRanking(array &$scoredRows, ?string $heartbeatCampaignId = null): void
    {
        if ($scoredRows === []) {
            return;
        }

        $paths = array_values(array_unique(array_column($scoredRows, 'path')));
        $symbolCounts = $this->codeGraphSymbolCounts($paths);
        $maxSymbols = max(8, (int) max($symbolCounts ?: [0]));

        // REAL consumer proxy: how many PRODUCTION files actually call this target
        // (grep UNION scoped code-graph, tests excluded). This replaces the old defect
        // where consumer_proxy counted the file's OWN symbols, which rewarded big orphan
        // scaffolds. Fail-open: when the service is absent OR returns nothing (graph+grep
        // both unavailable), fall back to the symbol-count proxy = byte-identical legacy
        // behaviour, and the orphan flag is never set (no false orphan on missing data).
        // callerCounts returns ONLY measured paths (tri-state: an unmeasured path is
        // absent, never a key with 0). So measurement is decided PER PATH — an absent
        // path falls back to the symbol proxy and is NEVER flagged orphan on missing data.
        //
        // COST GUARD (24h-soak keystone fix): callerCounts runs a per-path grep across the
        // production tree (~0.4s/path). Resolving it for the WHOLE scanned universe (up to
        // max_files=1200) makes a single refill take many MINUTES, so the supervisor never
        // finishes a cycle inside its budget — it stops with cycles=0 / time_budget_reached
        // and the loop never grinds (the observed 24h idle). Only the highest pre-ranked
        // candidates can win the top-N upsert slots, so resolve real callers for a BOUNDED
        // top-K by the cheap structural score; every other path keeps the symbol-proxy
        // fallback — the EXACT branch an "unmeasured" path already takes (fail-open, never
        // falsely flagged orphan). Correctness of the top-N order is preserved; only the
        // certain-losers skip the expensive grep.
        // ACDE T2 (supply-rate coupling): when scenario fan-out widens throughput, the discovery resolve
        // caps must widen in LOCKSTEP or width starves on too few candidates (supply, not width, is the
        // bottleneck). discovery_supply_widen_factor multiplies both caps; default 1 => byte-identical
        // (60/40). Arm it alongside scenario_fanout so width always has real work to chew.
        $supplyWiden = max(1, (int) config('atlas.loop.discovery_supply_widen_factor', 1));
        $callerCap = max(1, (int) config('atlas.loop.discovery_caller_resolve_cap', 60) * $supplyWiden);
        $resolvePaths = $paths;
        if (count($paths) > $callerCap) {
            $byScore = $scoredRows;
            usort($byScore, static fn (array $a, array $b): int => ((float) ($b['scored']['score'] ?? 0)) <=> ((float) ($a['scored']['score'] ?? 0)));
            $resolveSet = array_slice(array_map(
                static fn (array $r): string => (string) $r['path'],
                $byScore,
            ), 0, $callerCap);

            // REFACTOR-SUPPLY FIX (work-supply keystone): the heavy-refactor promotion (:287-293)
            // AND the +0.25 test-backed boost (:350) both require MEASURED callers, but measurement
            // was capped to the top-N by cheap STRUCTURAL score. A genuinely complex, wired,
            // test-backed file ranking BELOW that cut never gets its callers measured => never earns
            // the promotion => never reaches the tiny claim window => is NEVER refactored. That
            // chicken-and-egg drained the refactor supply to ~0 and flooded the queue with vanilla
            // edge-fixes (the framework-edge-fix fallback) until it went dry (queue_starved). The
            // cyclomatic AST signal is ALREADY computed here (no grep to SELECT the set), so ALSO
            // measure the most COMPLEX candidates — exactly the files the heavy-refactor lane wants
            // wired-checked. Bounded by its own cap to respect the per-path grep cost guard; set the
            // cap to 0 to restore byte-identical legacy behaviour.
            $refactorResolveCap = max(0, (int) config('atlas.loop.discovery_refactor_resolve_cap', 40) * $supplyWiden);
            if ($refactorResolveCap > 0) {
                $refMinCx = max(1, (int) config('atlas.loop.framework_refactor_min_cyclomatic', 10));
                $byCx = array_values(array_filter(
                    $scoredRows,
                    static fn (array $r): bool => (int) ($r['scored']['signals']['cyclomatic'] ?? 0) >= $refMinCx,
                ));
                usort($byCx, static fn (array $a, array $b): int => ((int) ($b['scored']['signals']['cyclomatic'] ?? 0)) <=> ((int) ($a['scored']['signals']['cyclomatic'] ?? 0)));
                foreach (array_slice($byCx, 0, $refactorResolveCap) as $r) {
                    $resolveSet[] = (string) $r['path'];
                }
            }
            $resolvePaths = array_values(array_unique($resolveSet));
        }
        $wiredCallers = $this->wiredCallers;
        if ($wiredCallers !== null && is_string($heartbeatCampaignId) && $heartbeatCampaignId !== '') {
            $wiredCallers = $wiredCallers->withProgressCallback(function () use ($heartbeatCampaignId): void {
                $this->touchHeartbeat($heartbeatCampaignId);
            });
        }
        $callerCounts = $wiredCallers?->callerCounts($resolvePaths) ?? [];
        $maxCallers = max(4, (int) max($callerCounts ?: [0]));

        foreach ($scoredRows as &$row) {
            $signals = is_array($row['scored']['signals'] ?? null) ? $row['scored']['signals'] : [];
            $symbols = (int) ($symbolCounts[$row['path']] ?? 0);
            $measured = array_key_exists($row['path'], $callerCounts);
            $callers = (int) ($callerCounts[$row['path']] ?? 0);
            $consumerProxy = $measured
                ? ($callers > 0 ? min(1.0, log(1 + $callers) / log(1 + $maxCallers)) : 0.0)
                : ($symbols > 0 ? min(1.0, log(1 + $symbols) / log(1 + $maxSymbols)) : 0.0);
            $failureEvidence = $this->clamp01((float) ($row['scored']['evidence'] ?? $signals['failure_evidence'] ?? 0.0));
            $backlogReach = $this->clamp01((float) ($signals['backlog_reach'] ?? 0.0));
            $impact = $this->clamp01(0.50 * $consumerProxy + 0.30 * $failureEvidence + 0.20 * $backlogReach);

            $signals['impact_rank'] = round($impact, 4);
            $signals['impact_code_symbols'] = $symbols;
            $signals['impact_real_callers'] = $measured ? $callers : null;
            $signals['impact_consumer_proxy'] = round($consumerProxy, 4);
            $signals['impact_failure_evidence'] = round($failureEvidence, 4);
            $signals['impact_backlog_reach'] = round($backlogReach, 4);
            // Orphan ONLY when THIS path was measured: 0 callers AND 0 evidence AND 0 reach.
            $signals['orphan'] = $measured && $callers === 0 && $failureEvidence <= 0.0 && $backlogReach <= 0.0;

            // REFACTOR LEVERAGE (default-inert): high callers x high complexity = the file a
            // simplification pays back the most. Computed from signals ALREADY resolved this
            // pass (no extra grep): the cyclomatic AST signal from scoreCandidate + the same
            // log-scaled caller count the impact term uses. The composite is ALWAYS stamped
            // for audit, but the bounded (<=0.12) score boost is applied ONLY when the
            // refactoring_targets_enabled flag is ON — with it OFF the ranking is byte-identical
            // (the signal is present but never moves the score).
            $cyclomatic = (int) ($signals['cyclomatic'] ?? 0);
            $callerLeverage = $callers > 0 ? min(1.0, log(1 + $callers) / log(1 + $maxCallers)) : 0.0;
            $complexityLeverage = min(1.0, $cyclomatic / 10.0);
            $refactorLeverage = $this->clamp01(0.6 * $callerLeverage + 0.4 * $complexityLeverage);
            $signals['refactor_leverage'] = round($refactorLeverage, 4);
            $row['scored']['signals'] = $signals;
            $row['scored']['score'] = round(min(1.0, (float) $row['scored']['score'] + 0.18 * $impact), 4);
            if ((bool) config('atlas.loop.refactoring_targets_enabled', false)) {
                $row['scored']['score'] = round(min(1.0, (float) $row['scored']['score'] + 0.12 * $refactorLeverage), 4);

                // HEAVY-REFACTOR PRIORITIZATION (gated on framework_refactor_enabled, default
                // OFF): when heavy refactoring is ON, a genuinely high-complexity WIRED file is a
                // PRIMARY target (the operator wants big refactors, not small fixes) — the small
                // +0.12 boost above never beats a backlog/evidence edge-gap (~0.85), so such a
                // file never reached the claimed top-N where the framework refactor synthesizer
                // turns it into a refactor objective (measured: refactoring never fired). Promote
                // it to top-tier so it surfaces. With the framework flag OFF the ranking is
                // unchanged. The synthesizer + complexity-drop cert still gate certification.
                $refMinCx = (int) config('atlas.loop.framework_refactor_min_cyclomatic', 10);
                if ((bool) config('atlas.loop.framework_refactor_enabled', false)
                    && $cyclomatic >= $refMinCx
                    && $callers >= 1) {
                    $row['scored']['score'] = round(max((float) $row['scored']['score'], min(1.0, 0.80 + 0.18 * $refactorLeverage)), 4);
                    $signals['heavy_refactor_candidate'] = true;
                    $row['scored']['signals'] = $signals;
                }
            }
        }
        unset($row);
    }

    /**
     * Orphan gate: demote (or exclude) targets with no production callers, no failure
     * evidence and no backlog reach. Only acts on rows the impact stage flagged orphan
     * (i.e. real caller data was available); fail-open otherwise.
     *
     * @param  list<array{path:string,abs:string,scored:array<string,mixed>}>  $scoredRows
     */
    private function applyOrphanGate(array &$scoredRows): void
    {
        $penalty = $this->clamp01((float) config('atlas.loop.orphan_score_penalty', 0.15));
        $hardExclude = (bool) config('atlas.loop.orphan_gate_hard_exclude', false);

        $kept = [];
        foreach ($scoredRows as $row) {
            $orphan = (bool) ($row['scored']['signals']['orphan'] ?? false);
            if ($orphan) {
                if ($hardExclude) {
                    continue; // never even propose dead scaffolding
                }
                $row['scored']['score'] = round((float) $row['scored']['score'] * $penalty, 4);
            }
            $kept[] = $row;
        }
        $scoredRows = array_values($kept);
    }

    /**
     * Bounded boost for WIRED, test-backed targets — where a canary can run green and a
     * substantive change can earn NON_TRIVIAL credit. Stamps has_sibling_test/sibling
     * signals so the downstream test-gap objective + the grind reuse the SAME sibling.
     *
     * @param  list<array{path:string,abs:string,scored:array<string,mixed>}>  $scoredRows
     */
    private function applyTestBackedRanking(array &$scoredRows): void
    {
        if ($this->siblingTests === null) {
            return; // fail-open: no resolver => ordering untouched
        }
        $minCallers = max(0, (int) config('atlas.loop.test_gap_min_callers', 1));

        foreach ($scoredRows as &$row) {
            $signals = is_array($row['scored']['signals'] ?? null) ? $row['scored']['signals'] : [];
            $sib = $this->siblingTests->resolve((string) $row['path']);
            $signals['has_sibling_test'] = $sib['has_sibling'];
            $signals['sibling_test_path'] = $sib['sibling_path'];

            // Real callers measured by applyImpactRanking (null = unmeasured => treat as 0
            // for the wired requirement, never block).
            $callers = $signals['impact_real_callers'];
            $callers = is_int($callers) ? $callers : 0;

            if ($sib['has_sibling'] && $callers >= $minCallers) {
                $row['scored']['score'] = round(min(1.0, (float) $row['scored']['score'] + 0.25), 4);
                $signals['test_backed_boost'] = true;
            }
            $row['scored']['signals'] = $signals;
        }
        unset($row);
    }

    /**
     * @param  list<string>  $paths
     * @return array<string,int>
     */
    private function codeGraphSymbolCounts(array $paths): array
    {
        if ($paths === [] || ! DatabaseTableAvailability::has('atlas_engineering_code_symbols')) {
            return [];
        }

        try {
            $query = DB::table('atlas_engineering_code_symbols')
                ->select('file_path', DB::raw('count(*) as symbols'))
                ->whereIn('file_path', $paths);
            if (DatabaseTableAvailability::hasColumn('atlas_engineering_code_symbols', 'status')) {
                $query->where('status', 'active');
            }
            if (DatabaseTableAvailability::hasColumn('atlas_engineering_code_symbols', 'workspace_id')) {
                $query->where('workspace_id', (string) config('atlas.code_graph.default_workspace_id', 'atlas-server'));
            }

            $counts = [];
            foreach ($query->groupBy('file_path')->get() as $row) {
                $counts[(string) $row->file_path] = (int) $row->symbols;
            }

            return $counts;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<array{path:string,abs:string,scored:array<string,mixed>}>  $scoredRows
     * @param  array<string,int>  $recentActivity
     */
    private function applyTargetCooldown(array &$scoredRows, array $recentActivity, int $cooldownHours): void
    {
        foreach ($scoredRows as &$row) {
            $signals = is_array($row['scored']['signals'] ?? null) ? $row['scored']['signals'] : [];
            $hits = (int) ($recentActivity[$row['path']] ?? 0);
            $signals['target_cooldown'] = $hits > 0 ? 1.0 : 0.0;
            $signals['target_cooldown_hits'] = $hits;
            $signals['target_cooldown_hours'] = $cooldownHours;
            if ($hits > 0) {
                $row['scored']['score'] = round(max(0.0, (float) $row['scored']['score'] * 0.35), 4);
            }
            $row['scored']['signals'] = $signals;
        }
        unset($row);
    }

    private function clamp01(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    private function touchHeartbeat(?string $campaignId): void
    {
        if (! is_string($campaignId) || $campaignId === '') {
            return;
        }

        try {
            $dir = storage_path('atlas-loop/campaign/'.$campaignId);
            if (! is_dir($dir)) {
                @mkdir($dir, 0o755, true);
            }
            @file_put_contents($dir.'/heartbeat', (string) time());

            DB::table('atlas_loop_campaigns')
                ->where('id', $campaignId)
                ->update(['heartbeat_at' => now()]);
        } catch (Throwable) {
            // Liveness ping is best-effort; discovery stays fail-open.
        }
    }

    /**
     * Pure, side-effect-free scoring. Returns null if inadmissible (the cp -R + plain-`php`
     * grind could not honestly run it), else the S/I/N composite + signals.
     *
     * @param  array{already_proposed: array<string,int>}  $context
     * @return array{score:float,self_contained:float,improvement:float,novelty:float,signals:array<string,mixed>}|null
     */
    public function scoreCandidate(string $absPath, string $repoRelPath, array $context = []): ?array
    {
        $text = @file_get_contents($absPath);
        if ($text === false || $text === '') {
            return null;
        }

        $loc = substr_count($text, "\n") + 1;
        if ($loc < 40 || $loc > 400) {
            return null; // too trivial / too big for an honest single-file grind
        }
        if (preg_match('/\b(final\s+|abstract\s+)*(class|enum|trait)\s+\w/', $text) !== 1) {
            return null; // must declare a unit
        }
        $frameworkReach = $this->frameworkReach($text);
        // L2-2 (breadth): targets framework-reach são admitidos quando a flag está ON —
        // eles seguem o caminho framework-materializer + intent-verifier + certificação
        // adversarial universal no grinder (não o plain-`php` grind). Penalizados no
        // score (mais caros de moer), mas elegíveis: é onde moram as melhorias REAIS.
        $frameworkEligible = (bool) config('atlas.loop.discovery_framework_targets', false);
        if ($frameworkReach > 0 && ! $frameworkEligible) {
            return null; // NOT self-contained — the plain-`php` grind cannot pin it
        }
        if (! $this->phpLintClean($absPath)) {
            return null; // already broken / unparseable
        }

        $selfContained = $frameworkReach > 0 ? 0.45 : 1.0;
        $improvement = $this->improvementSignal($text);
        $novelty = isset($context['already_proposed'][$repoRelPath]) ? 0.0 : 1.0;
        $score = 0.55 * $selfContained + 0.30 * $improvement + 0.15 * $novelty;

        // HONEST complexity signal (refactor capability): the deterministic AST cyclomatic
        // measure — NOT the regex branch_density above (which counts the words 'if'/'&&' in
        // prose too). max_per_method drives refactor leverage; total guards "extract method
        // that balloons the file". Fail-open: an unparseable unit yields measured=false, so
        // the signals are simply omitted and every downstream reader takes its 0 default.
        $signals = [
            'framework_reach' => $frameworkReach,
            'loc' => $loc,
            'public_methods' => $this->publicMethodCount($text),
            'branch_density' => round($this->branchDensity($text), 4),
            'todos' => $this->todoCount($text),
            'deprecated' => $this->isDeprecated($text),
            'throws' => $this->throwsCount($text),
            'edge_gaps' => $this->edgeGaps($text),
        ];
        $complexity = $this->analyzer()->fileComplexity($text);
        if ($complexity['measured']) {
            $signals['cyclomatic'] = $complexity['max_per_method'];
            $signals['cyclomatic_total'] = $complexity['total'];
        }

        return [
            'score' => round($score, 4),
            'self_contained' => $selfContained,
            'improvement' => round($improvement, 4),
            'novelty' => $novelty,
            'signals' => $signals,
        ];
    }

    private function frameworkReach(string $text): int
    {
        $reach = 0;
        foreach (self::REACH_PATTERNS as $pattern) {
            $reach += preg_match_all($pattern, $text);
        }

        return $reach;
    }

    /**
     * Normalized [0,1] "this file has improvable surface" signal: real edge-case gaps,
     * branchiness, deprecation markers and TODOs all raise it. Bounded heuristic, not AST.
     */
    private function improvementSignal(string $text): float
    {
        $raw = $this->todoCount($text) * 1.5
            + ($this->isDeprecated($text) ? 3.0 : 0.0)
            + $this->edgeGaps($text) * 2.0
            + min(6.0, $this->branchDensity($text) * 20.0)
            + min(3.0, $this->throwsCount($text) * 0.5);

        return min(1.0, $raw / 10.0);
    }

    private function publicMethodCount(string $text): int
    {
        return preg_match_all('/\bpublic\s+(static\s+)?function\s+\w/', $text);
    }

    private function branchDensity(string $text): float
    {
        $branches = preg_match_all('/\b(if|elseif|foreach|for|while|switch|case|catch|\?\?|&&|\|\|)\b/', $text);
        $loc = max(1, substr_count($text, "\n") + 1);

        return $branches / $loc;
    }

    private function todoCount(string $text): int
    {
        return preg_match_all('/\b(TODO|FIXME|HACK|XXX|BUG)\b/i', $text);
    }

    private function isDeprecated(string $text): bool
    {
        return preg_match('/@deprecated\b/i', $text) === 1;
    }

    private function throwsCount(string $text): int
    {
        return preg_match_all('/\bthrow\s+new\b/', $text);
    }

    /**
     * Cheap "missing-guard" greps — the kind of edge case a frozen RED test can pin:
     * a switch without default, an unguarded division, an array access without a guard,
     * an unvalidated cast. Line-based, not AST (bounded blast radius — a mis-score only
     * mis-orders; the frozen judge stays authoritative and cannot be fooled into a false proposal).
     */
    private function edgeGaps(string $text): int
    {
        $gaps = 0;
        if (preg_match('/switch\s*\(/', $text) === 1 && preg_match('/\bdefault\s*:/', $text) !== 1) {
            $gaps++;
        }
        $gaps += min(3, preg_match_all('#/\s*\$#', $text));            // division by a variable (possible /0)
        $gaps += min(2, preg_match_all('/\(int\)\s*\$|\(float\)\s*\$/', $text)); // unvalidated numeric cast

        return $gaps;
    }

    private function phpLintClean(string $absPath): bool
    {
        $process = new Process([PHP_BINARY, '-l', $absPath]);
        $process->setTimeout(max(1.0, (float) config('atlas.loop.discovery_php_lint_timeout_seconds', 20.0)));
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * @param  list<string>  $roots
     * @return iterable<string>
     */
    private function phpFiles(string $repoRoot, array $roots, int $maxFiles): iterable
    {
        $count = 0;
        foreach ($roots as $root) {
            $base = $repoRoot.'/'.trim((string) $root, '/');
            if (! is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($count >= $maxFiles) {
                    return;
                }
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $count++;
                    yield $file->getPathname();
                }
            }
        }
    }
}
