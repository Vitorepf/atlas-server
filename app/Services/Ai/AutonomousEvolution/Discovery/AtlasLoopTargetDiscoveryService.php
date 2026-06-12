<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use Symfony\Component\Process\Process;

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
