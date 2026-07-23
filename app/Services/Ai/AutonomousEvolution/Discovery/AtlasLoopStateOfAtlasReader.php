<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use App\Services\Ai\Kernel\Domain\AtlasAiDomainCatalogService;
use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use Throwable;

/**
 * Reads the Atlas brain ONCE per cycle into a {@see StateOfAtlas}. Every brain read is fail-open:
 * a service that errors or returns an unexpected shape degrades that one signal to empty rather
 * than throwing, so the reader never blocks a cycle. The richer the brain, the sharper the state;
 * a blind read still yields a usable (conservative) state.
 *
 * Builds ON existing brain services — memory recall (decisions/goals = where Atlas is going), the
 * domain catalog (areas + maturity), the reality graph (what actually runs) — plus the static
 * petreo set (forbidden self-targets the loop must never originate work on).
 */
final class AtlasLoopStateOfAtlasReader
{
    /**
     * Petreo / FORBIDDEN_SELF_TARGETS — the loop's own decision/gate/merge organs. The rédea must
     * never originate work here (the loop editing its own prioritizer/judge is the anti-pattern).
     */
    private const FORBIDDEN_FRAGMENTS = [
        'AtlasEvolutionFrozenJudge',
        'AtlasLoopSemanticImplementationCertifier',
        'AtlasLoopAutoMergeService',
        'AtlasLoopProposalMaterializer',
        'AtlasLoopNextWorkDecider',
        'AtlasEngineeringHonestyGate',
        'AtlasLoopObjectiveProducer',
        'AtlasLoopLeverageScorer',
        'AtlasLoopStateOfAtlasReader',
        'AtlasLoopOriginationBuilder',
        'AtlasLoopHypothesisTreeProducer',
    ];

    public function __construct(
        private readonly ?AtlasHybridMemoryRetrievalService $memory = null,
        private readonly ?AtlasRealityGraphQueryService $reality = null,
        private readonly ?AtlasAiDomainCatalogService $domains = null,
    ) {}

    public function read(string $repoRoot, array $opts = []): StateOfAtlas
    {
        $t0 = microtime(true);

        $strategic = $this->readStrategic();
        $maturity = $this->readAreaMaturity();
        $proven = $this->readRealityProven();

        return new StateOfAtlas(
            $strategic,
            $maturity,
            $proven,
            self::FORBIDDEN_FRAGMENTS,
            (int) ((microtime(true) - $t0) * 1000),
            $this->readDeliveredCapabilities(),
        );
    }

    /**
     * L2/L5 — the COMPOUNDING frontier: target paths of capabilities the loop already MERGED to main
     * (proposals.merged_to_main — the governed gain surface). This is what lets cycle n+1 PERCEIVE cycle n's
     * gains in what is selectable, instead of re-discovering an exhausted scope (the ARBOR feedback was
     * advisory-prompt-only). Fail-OPEN empty (no table / DB hiccup). Flag default-OFF => empty => byte-identical.
     *
     * @return list<string>
     */
    private function readDeliveredCapabilities(): array
    {
        if (! (bool) config('atlas.loop.compounding_frontier_enabled', false)) {
            return [];
        }
        if (! \App\Services\Ai\Support\DatabaseTableAvailability::has('atlas_loop_proposals')) {
            return [];
        }
        try {
            return \App\Models\AtlasLoopProposal::query()
                ->where('merged_to_main', true)
                ->whereNotNull('target_path')
                ->orderByDesc('updated_at')
                ->limit(500)
                ->pluck('target_path')
                ->filter(static fn ($p): bool => is_string($p) && $p !== '')
                ->unique()
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Active goals + decisions = where Atlas is going. Each becomes {text, keywords} so the State
     * can score a path's strategic alignment.
     *
     * @return list<array{text:string, keywords:list<string>}>
     */
    private function readStrategic(): array
    {
        try {
            $svc = $this->memory ?? app(AtlasHybridMemoryRetrievalService::class);
            $res = $svc->recall('strategic goal decision priority objective evolution', [], [], ['limit' => 40]);
            $items = is_array($res['recall'] ?? null) ? $res['recall'] : [];
            $out = [];
            foreach ($items as $it) {
                $text = '';
                foreach (['text', 'content', 'summary', 'body', 'title', 'name'] as $k) {
                    if (is_string($it[$k] ?? null) && trim($it[$k]) !== '') {
                        $text = trim((string) $it[$k]);
                        break;
                    }
                }
                if ($text === '') {
                    continue;
                }
                $out[] = ['text' => mb_substr($text, 0, 400), 'keywords' => $this->keywordsOf($text)];
            }

            return array_slice($out, 0, 40);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Area maturity from the domain catalog (lower maturity = bigger opportunity). Defensive about
     * the exact shape; any unparseable entry is skipped.
     *
     * @return array<string,float>
     */
    private function readAreaMaturity(): array
    {
        try {
            $svc = $this->domains ?? app(AtlasAiDomainCatalogService::class);
            $res = $svc->inspect([]);
            $domains = is_array($res['domains'] ?? null) ? $res['domains'] : [];
            $out = [];
            foreach ($domains as $d) {
                $prefix = '';
                foreach (['path', 'path_prefix', 'root', 'namespace_path', 'dir'] as $k) {
                    if (is_string($d[$k] ?? null) && trim($d[$k]) !== '') {
                        $prefix = trim((string) $d[$k]);
                        break;
                    }
                }
                if ($prefix === '') {
                    continue;
                }
                $maturity = null;
                foreach (['maturity', 'maturity_score', 'stage_fraction', 'readiness'] as $k) {
                    if (is_numeric($d[$k] ?? null)) {
                        $maturity = (float) $d[$k];
                        break;
                    }
                }
                if ($maturity === null) {
                    continue;
                }
                // normalize a 0..N stage onto 0..1 if it looks like a stage count
                if ($maturity > 1.0) {
                    $maturity = min(1.0, $maturity / 10.0);
                }
                $out[ltrim($prefix, '/')] = max(0.0, min(1.0, $maturity));
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Paths the reality graph proves actually run (vs merely claimed). Used by the reality floor.
     *
     * @return list<string>
     */
    private function readRealityProven(): array
    {
        try {
            $svc = $this->reality ?? app(AtlasRealityGraphQueryService::class);
            $res = $svc->query('proven running implemented', ['limit' => 120]);
            $paths = is_array($res['paths'] ?? null) ? $res['paths'] : [];
            $out = [];
            foreach ($paths as $p) {
                if (is_string($p) && trim($p) !== '') {
                    $out[] = trim($p);
                } elseif (is_array($p) && is_string($p['path'] ?? null)) {
                    $out[] = trim((string) $p['path']);
                }
            }

            return array_slice(array_values(array_unique($out)), 0, 120);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Significant keywords from a text (lowercased, length>=4, de-stopworded, unique, capped).
     *
     * @return list<string>
     */
    private function keywordsOf(string $text): array
    {
        $stop = ['this', 'that', 'with', 'from', 'para', 'pelo', 'pela', 'como', 'mais', 'todo',
            'toda', 'tudo', 'sobre', 'esse', 'essa', 'isso', 'cada', 'deve', 'must', 'have',
            'will', 'should', 'into', 'over', 'when', 'then', 'than', 'antes', 'depois'];
        preg_match_all('/[A-Za-zÀ-ÿ]{4,}/u', strtolower($text), $m);
        $words = array_values(array_unique(array_diff($m[0] ?? [], $stop)));

        return array_slice($words, 0, 10);
    }
}
