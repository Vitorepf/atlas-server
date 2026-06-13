<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

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
    ) {}

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

        $scanned = 0;
        $admissible = 0;
        $scoredRows = [];

        foreach ($this->phpFiles($repoRoot, $roots, $maxFiles) as $abs) {
            $scanned++;
            $rel = ltrim(str_replace($repoRoot, '', $abs), '/');
            $scored = $this->scoreCandidate($abs, $rel, ['already_proposed' => $alreadyProposed]);
            if ($scored === null) {
                continue;
            }
            $admissible++;
            $scoredRows[] = ['path' => $rel, 'abs' => $abs, 'scored' => $scored];
        }

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

        // L4-1: anti-Goodhart ranking. Structural self-containedness is still the base
        // gate, but the final order now gets a bounded IMPACT boost from real read-model
        // signal (code-indexed symbol surface, failure evidence, and backlog reach).
        if ((bool) config('atlas.loop.impact_ranking_enabled', true)) {
            $this->applyImpactRanking($scoredRows);
        }

        // L?-impact: orphan gate. A target with ZERO real production callers AND no
        // failure evidence AND no backlog reach is dead/orphan scaffolding — improving
        // it is provably zero real-world utility (the measured 54%-orphan defect). Demote
        // it hard so the provider budget flows to code that runs. Fail-open: only fires
        // when the wired-caller service supplied real caller data (signals.orphan set).
        if ((bool) config('atlas.loop.orphan_gate_enabled', true)) {
            $this->applyOrphanGate($scoredRows);
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

        // Highest score first; upsert the top-N as candidates.
        usort($scoredRows, static fn (array $a, array $b): int => $b['scored']['score'] <=> $a['scored']['score']);
        $top = array_slice($scoredRows, 0, $limit);
        $upserted = 0;
        foreach ($top as $row) {
            $contentHash = hash('sha256', (string) @file_get_contents($row['abs']));
            $this->repository->upsert($campaignId, $row['path'], $contentHash, $row['scored'], ['origin' => 'discovery']);
            $upserted++;
        }

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
     */
    private function applyImpactRanking(array &$scoredRows): void
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
        $callerCounts = $this->wiredCallers?->callerCounts($paths) ?? [];
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
            $row['scored']['signals'] = $signals;
            $row['scored']['score'] = round(min(1.0, (float) $row['scored']['score'] + 0.18 * $impact), 4);
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

        return [
            'score' => round($score, 4),
            'self_contained' => $selfContained,
            'improvement' => round($improvement, 4),
            'novelty' => $novelty,
            'signals' => [
                'framework_reach' => $frameworkReach,
                'loc' => $loc,
                'public_methods' => $this->publicMethodCount($text),
                'branch_density' => round($this->branchDensity($text), 4),
                'todos' => $this->todoCount($text),
                'deprecated' => $this->isDeprecated($text),
                'throws' => $this->throwsCount($text),
                'edge_gaps' => $this->edgeGaps($text),
            ],
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
        $process->setTimeout(20.0);
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
