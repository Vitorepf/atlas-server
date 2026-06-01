<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

/**
 * Governed L7-L10 queue consumer (operator mandate, 2026-06-01).
 *
 * The L7-L10 convergence trail (slices S83-S165) lives inside a BROAD backlog
 * document that also carries earlier slices (S1-S82). The auto-runner must NOT
 * swallow the whole broad doc (see Reliable24hLoopRunnerService::planBacklogDocs,
 * which is restricted to the 9 canonical atomic docs). This class is the explicit,
 * governed, TESTED range-consumer that the operator/command layer drives to read
 * exactly the L7-L10 range out of that doc, bucket it by autonomy level, and prove
 * the queue is complete and well-formed before any supervised run.
 *
 * Pure: reuses {@see BuildPlanDocumentParser} (no filesystem/provider/mutation);
 * the caller reads the markdown. Levels are by slice-number range (the broad doc
 * carries no per-slice level marker): L7=S83-100, L8=S101-125, L9=S126-145,
 * L10=S146-165 — totalling 83. A slice is "bad" if it is missing (a gap in the
 * range), malformed (no delivery or no acceptance criterion), or falls outside
 * every declared level band.
 */
final class L7L10QueueConsumer
{
    public const SCHEMA_VERSION = 'atlas.software_company_stewardship.l7_l10_queue.v1';

    /** @var array<string,array{0:int,1:int}> default autonomy-level slice-number bands */
    public const DEFAULT_LEVELS = [
        'L7' => [83, 100],
        'L8' => [101, 125],
        'L9' => [126, 145],
        'L10' => [146, 165],
    ];

    private readonly BuildPlanDocumentParser $parser;

    public function __construct(?BuildPlanDocumentParser $parser = null)
    {
        $this->parser = $parser ?? new BuildPlanDocumentParser();
    }

    /**
     * @param  array<string,array{0:int,1:int}>  $levels
     * @return array{
     *   schema_version:string,
     *   status:'valid'|'invalid',
     *   levels:array<string,int>,
     *   total:int,
     *   bad:list<string>,
     *   range:array{min:int,max:int},
     *   ready_slices:list<string>
     * }
     */
    public function consume(string $markdown, array $levels = self::DEFAULT_LEVELS): array
    {
        $parsed = $this->parser->parse($markdown);

        $min = PHP_INT_MAX;
        $max = 0;
        foreach ($levels as [$lo, $hi]) {
            $min = min($min, $lo);
            $max = max($max, $hi);
        }
        if ($levels === []) {
            $min = 0;
            $max = -1;
        }

        $byLevel = array_fill_keys(array_keys($levels), 0);
        $seen = [];
        $ready = [];
        $bad = [];

        foreach ($parsed['slices'] as $slice) {
            $label = trim((string) ($slice['label'] ?? ''));
            if (preg_match('/^S(\d+)$/', $label, $m) !== 1) {
                continue;
            }
            $n = (int) $m[1];
            if ($n < $min || $n > $max) {
                continue; // outside the L7-L10 range (e.g. S1-S82) — not this queue
            }
            $seen[$n] = true;

            $level = $this->levelFor($n, $levels);
            if ($level === null) {
                $bad[] = $label.':outside_declared_level_band';

                continue;
            }
            if (trim((string) ($slice['delivery'] ?? '')) === '' || (array) ($slice['acceptance_criteria'] ?? []) === []) {
                $bad[] = $label.':malformed_missing_delivery_or_acceptance';

                continue;
            }
            $byLevel[$level]++;
            $ready[] = $label;
        }

        // Gaps: any slice number in the declared range that the doc does not provide.
        for ($n = $min; $n <= $max; $n++) {
            if (! isset($seen[$n])) {
                $bad[] = 'S'.$n.':missing';
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $bad === [] ? 'valid' : 'invalid',
            'levels' => $byLevel,
            'total' => array_sum($byLevel),
            'bad' => $bad,
            'range' => ['min' => $min === PHP_INT_MAX ? 0 : $min, 'max' => $max],
            'ready_slices' => $ready,
        ];
    }

    /**
     * Generate the loop-ready CHILD plan-doc containing ONLY the L7-L10 range
     * (Section 6 slice rows + intra-range Section 10 edges), re-emitted in the
     * exact BuildPlanDocumentParser format so the loop can consume it via an
     * EXPLICIT --plan-doc (never auto-captured by the index). External dependency
     * edges (one endpoint outside the range) are dropped — the child trail assumes
     * its upstream is already delivered. Round-trips: parse(child) yields the same
     * in-range slice set. The caller writes it to an operator-chosen path.
     *
     * @param  array<string,array{0:int,1:int}>  $levels
     */
    public function extractChildDoc(string $markdown, string $childId, string $childTitle, array $levels = self::DEFAULT_LEVELS): string
    {
        $parsed = $this->parser->parse($markdown);
        $min = PHP_INT_MAX;
        $max = 0;
        foreach ($levels as [$lo, $hi]) {
            $min = min($min, $lo);
            $max = max($max, $hi);
        }

        $rows = [];
        foreach ($parsed['slices'] as $slice) {
            $label = trim((string) ($slice['label'] ?? ''));
            if (preg_match('/^S(\d+)$/', $label, $m) !== 1) {
                continue;
            }
            $n = (int) $m[1];
            if ($n < $min || $n > $max) {
                continue;
            }
            $acceptance = implode(' ; ', array_map('strval', (array) ($slice['acceptance_criteria'] ?? [])));
            $rows[] = '| '.$label.' | '.trim((string) ($slice['delivery'] ?? '')).' | '.$acceptance.' | '.trim((string) ($slice['authority_guard'] ?? '')).' |';
        }

        $edges = [];
        foreach ($parsed['dependency_edges'] as $edge) {
            $from = trim((string) ($edge['from'] ?? ''));
            $to = trim((string) ($edge['to'] ?? ''));
            if (preg_match('/^S(\d+)$/', $from, $mf) !== 1 || preg_match('/^S(\d+)$/', $to, $mt) !== 1) {
                continue;
            }
            $fn = (int) $mf[1];
            $tn = (int) $mt[1];
            if ($fn >= $min && $fn <= $max && $tn >= $min && $tn <= $max) {
                $edges[] = $from.' -> '.$to;
            }
        }

        $front = "---\nid: ".$childId."\ntitle: ".$childTitle."\ndoc_schema: atlas_build_plan_doc.v1\nimplementation_state: backlog_only_no_runtime\nprovenance: derived L7-L10 range (S".$min."-S".$max.") of atlas-aaeos-loop-evolution-backlog; governed child for explicit --plan-doc consumption, NOT auto-index\n---\n";

        return $front
            ."\n## 6. Decomposicao em slices ordenados\n\n"
            ."| Slice | Entrega | Aceite | Guarda |\n| --- | --- | --- | --- |\n"
            .implode("\n", $rows)."\n"
            ."\n## 10. Sequenciamento e dependencias\n\n"
            .implode('; ', $edges)."\n";
    }

    /**
     * @param  array<string,array{0:int,1:int}>  $levels
     */
    private function levelFor(int $n, array $levels): ?string
    {
        foreach ($levels as $level => [$lo, $hi]) {
            if ($n >= $lo && $n <= $hi) {
                return $level;
            }
        }

        return null;
    }
}
