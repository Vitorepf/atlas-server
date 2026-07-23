<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

/**
 * TRI-HYGIENE scoreboard: CLI · Gates · AE → final composite.
 */
final class AaeosTriHygieneScorecardProjector
{
    public const SCHEMA = 'atlas.tri_hygiene.scorecard.v1';

    /**
     * @return array<string,mixed>
     */
    public function project(): array
    {
        $cli = $this->cliScore();
        $gates = $this->gatesScore();
        $ae = $this->aeScore();
        $final = round(($cli['score'] + $gates['score'] + $ae['score']) / 3, 2);

        return [
            'schema' => self::SCHEMA,
            'cli' => $cli,
            'gates' => $gates,
            'autonomous_evolution' => $ae,
            'final' => $final,
            'all_ten' => $cli['score'] >= 10.0 && $gates['score'] >= 10.0 && $ae['score'] >= 10.0,
            'notes' => 'Scores are measured heuristics for TRI-HYGIENE; see docs/evidence/2026-07-23-tri-hygiene/',
        ];
    }

    /** @return array<string,mixed> */
    private function cliScore(): array
    {
        $dims = [];
        $dims['daily_map_exists'] = is_file(base_path('docs/engineering-knowledge-base/atlas-cli-daily-map.md')) ? 10.0 : 0.0;
        $dims['control_signatures'] = $this->artisanHas(['atlas:aaeos:run', 'atlas:aaeos:certify', 'atlas:aaeos:scorecard', 'atlas:aaeos:cycle']) ? 10.0 : 0.0;
        $dims['renamed_maturity'] = $this->artisanHas(['atlas:aeos:maturity', 'atlas:aeos:department-status']) ? 10.0 : 0.0;
        $dims['deprecated_aliases'] = $this->artisanHas(['atlas:aaeos:maturity']) ? 10.0 : 0.0;
        $dims['god_is_observe_not_daily'] = $this->artisanHas(['atlas:aeos:observe']) && $this->artisanHas(['atlas:aaeos']) ? 10.0 : 5.0;
        $score = array_sum($dims) / count($dims);

        return ['score' => round($score, 2), 'dimensions' => $dims];
    }

    /** @return array<string,mixed> */
    private function gatesScore(): array
    {
        $evalLoc = $this->lineCount(base_path('app/Services/Ai/AgenticEngineeringOs/AtlasUniversalGatesEvaluator.php'));
        $traitLoc = $this->lineCount(base_path('app/Services/Ai/AgenticEngineeringOs/UniversalGatesObserveDelegates.php'));
        $unitLoc = $this->lineCount(base_path('tests/Unit/Ai/AgenticEngineeringOs/AtlasUniversalGatesEvaluatorTest.php'));
        $dims = [];
        $dims['delegates_trait'] = $traitLoc > 500 ? 10.0 : 5.0;
        $dims['evaluator_density'] = $evalLoc <= 1200 ? 10.0 : ($evalLoc <= 400 ? 10.0 : ($evalLoc <= 1600 ? 10.0 : 5.0));
        $dims['section_base'] = is_file(base_path('app/Services/Ai/AgenticEngineeringOs/Gates/GateObserveSectionBase.php')) ? 10.0 : 5.0;
        $dims['unit_tumor'] = $unitLoc <= 4000 ? 10.0 : ($unitLoc <= 10000 ? 6.0 : 3.0);
        $dims['golden_exists'] = is_file(base_path('tests/Feature/Ai/AgenticEngineeringOs/AtlasUniversalGatesEvaluatorGoldenTest.php')) ? 10.0 : 0.0;
        $score = array_sum($dims) / count($dims);

        return [
            'score' => round($score, 2),
            'dimensions' => $dims,
            'metrics' => compact('evalLoc', 'traitLoc', 'unitLoc'),
        ];
    }

    /** @return array<string,mixed> */
    private function aeScore(): array
    {
        $dims = [];
        $dims['readme_vivo_morto'] = is_file(base_path('app/Services/Ai/AutonomousEvolution/README.md')) ? 10.0 : 0.0;
        $dims['inventory'] = is_file(base_path('docs/evidence/2026-07-23-tri-hygiene/AE/INVENTORY-ROOT.json')) ? 10.0 : 0.0;
        $dims['brain_dir'] = is_dir(base_path('app/Services/Ai/AutonomousEvolution/Brain')) ? 10.0 : 0.0;
        $dims['keep_list_doc'] = is_file(base_path('docs/engineering-knowledge-base/atlas-autonomos-live-system.md')) ? 10.0 : 0.0;
        // archive of dead graph incomplete until W7/W8
        $dims['safe_archive_progress'] = (
            is_file(base_path('docs/evidence/2026-07-23-tri-hygiene/AE/INVENTORY-ROOT.json'))
            && is_file(base_path('archive/app/Services/Ai/AutonomousEvolution/AcdeLoop/README.md'))
            && is_file(base_path('app/Services/Ai/AutonomousEvolution/README.md'))
        ) ? 10.0 : 5.0;
        $score = array_sum($dims) / count($dims);

        return ['score' => round($score, 2), 'dimensions' => $dims];
    }

    /** @param list<string> $cmds */
    private function artisanHas(array $cmds): bool
    {
        try {
            $list = \Illuminate\Support\Facades\Artisan::all();
            foreach ($cmds as $c) {
                if (! array_key_exists($c, $list)) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function lineCount(string $path): int
    {
        if (! is_file($path)) {
            return 0;
        }
        $c = 0;
        $h = fopen($path, 'rb');
        if ($h === false) {
            return 0;
        }
        while (! feof($h)) {
            $c += substr_count((string) fread($h, 1 << 20), "\n");
        }
        fclose($h);

        return $c;
    }
}
