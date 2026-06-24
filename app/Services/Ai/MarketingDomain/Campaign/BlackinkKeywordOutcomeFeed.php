<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use Illuminate\Support\Facades\DB;

/**
 * BlackinkKeywordOutcomeFeed (L9→L10) — fecha o loop ANTIFRÁGIL: lê o resultado REAL do Blackink
 * (term → clicks, conversions; conn `nivor` READ-ONLY, NUNCA escreve — pétreo [[blackink-nivor-production-safety]])
 * e produz a calibração que o pipeline consome sozinho, sem ninguém passar o peso à mão. Assim a PRECISÃO
 * (o flywheel L10) fica AUTOMÁTICA: a cada venda real, o OS fica mais certeiro.
 *
 * Separado em (a) núcleo PURO e testável (calibrationFromRows) e (b) pull read-only de runtime — o que faz
 * a query é o único ponto que toca o banco, e só com SELECT/count.
 */
class BlackinkKeywordOutcomeFeed
{
    private const CONNECTION = 'nivor';

    public function __construct(
        private readonly KeywordOutcomeCalibrator $calibrator = new KeywordOutcomeCalibrator,
    ) {}

    /**
     * Núcleo puro: dadas as linhas (term/clicks/conversions), devolve a calibração. Determinístico, testável.
     *
     * @param  array<int,array{term:string,clicks:int|float,conversions:int|float}>  $rows
     * @return array{baseline_cvr:float,prior:int,terms:int,weights:array<string,float>}
     */
    public function calibrationFromRows(array $rows): array
    {
        return $this->calibrator->calibrate($rows);
    }

    /**
     * Pull READ-ONLY do Blackink: top termos por cliques + suas conversões reais → calibração pronta pro
     * pipeline (opts['outcome_calibration']). Só SELECT/count; nunca escreve.
     *
     * @return array{baseline_cvr:float,prior:int,terms:int,weights:array<string,float>}
     */
    public function pull(int $topTerms = 2000): array
    {
        return $this->calibrationFromRows($this->readOutcomes($topTerms));
    }

    /**
     * Pull READ-ONLY PER-NICHE: agrupa os outcomes pelo nicho (inferido do nome da campanha, já que
     * campaigns.niche é NULL no Blackink) e calibra cada termo vs o baseline do SEU nicho — conserta a
     * distorção cross-nicho que o ciclo 26 provou. Só SELECT; nunca escreve.
     *
     * @return array{weights:array<string,float>,niches:array<string,mixed>,terms:int}
     */
    public function pullByNiche(int $minClicks = 5): array
    {
        $db = DB::connection(self::CONNECTION);
        $rows = $db->table('tracking_sessions as ts')
            ->join('campaigns as c', 'c.id_campaign', '=', 'ts.campaign_id')
            ->whereNotNull('ts.utm_term')->where('ts.utm_term', '<>', '')
            ->select('c.name as cname', 'ts.utm_term', DB::raw('count(*) as clicks'), DB::raw('sum((ts.is_converted)::int) as conv'))
            ->groupBy('c.name', 'ts.utm_term')->get();

        $byNiche = [];
        foreach ($rows as $r) {
            $clicks = (int) $r->clicks;
            if ($clicks < $minClicks) {
                continue;
            }
            $byNiche[$this->inferNiche((string) $r->cname)][] = [
                'term' => (string) $r->utm_term, 'clicks' => $clicks, 'conversions' => (int) $r->conv,
            ];
        }

        return $this->calibrator->calibrateByNiche($byNiche);
    }

    /** Nicho heurístico pelo nome da campanha (campaigns.niche vem NULL no Blackink). Determinístico. */
    private function inferNiche(string $name): string
    {
        $n = mb_strtolower($name);
        return match (true) {
            (bool) preg_match('/weight|gelatin|skinny|fat |lean|emagre|peso|jello/', $n) => 'weight_loss',
            (bool) preg_match('/memor|brain|mente|cogni|alzhe|visao|vision|eye|olho/', $n) => 'memory_vision',
            (bool) preg_match('/prosta/', $n) => 'prostate',
            (bool) preg_match('/copd|lung|pulm|respir/', $n) => 'copd',
            (bool) preg_match('/tinnitus|ear|ouvido|zumbido/', $n) => 'tinnitus',
            (bool) preg_match('/sugar|glucos|diabet|a1c|blood/', $n) => 'blood_sugar',
            default => 'other',
        };
    }

    /** @return array<int,array{term:string,clicks:int,conversions:int}> */
    private function readOutcomes(int $topTerms): array
    {
        $db = DB::connection(self::CONNECTION);

        $sessions = $db->table('tracking_sessions')
            ->whereNotNull('utm_term')->where('utm_term', '<>', '')
            ->select('utm_term', DB::raw('count(*) as clicks'))
            ->groupBy('utm_term')->orderByDesc('clicks')->limit($topTerms)->get();

        $conversions = $db->table('conversions as cv')
            ->join('tracking_sessions as ts', 'ts.id_tracking_session', '=', 'cv.tracking_session_id')
            ->where('cv.status', 'completed')->whereNotNull('ts.utm_term')->where('ts.utm_term', '<>', '')
            ->select('ts.utm_term', DB::raw('count(*) as c'))
            ->groupBy('ts.utm_term')->pluck('c', 'utm_term');

        $rows = [];
        foreach ($sessions as $s) {
            $rows[] = ['term' => (string) $s->utm_term, 'clicks' => (int) $s->clicks, 'conversions' => (int) ($conversions[$s->utm_term] ?? 0)];
        }

        return $rows;
    }
}
