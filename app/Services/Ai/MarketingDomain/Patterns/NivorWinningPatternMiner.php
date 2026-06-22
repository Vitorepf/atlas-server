<?php

namespace App\Services\Ai\MarketingDomain\Patterns;

use App\Models\AiMarketingWinningPattern;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mines the Nivor/Blackink tracker (READ-ONLY) for the DEEP, well-structured
 * success pattern per niche: real CVR + real economics (cost/ROI/CPA), the
 * keywords that converted best, the device/network/match-type/geo/timing of
 * sales, the winning funnel profile, the winners' bidding strategy, and the
 * common denominators. Persisted as governed truth Atlas decides on.
 *
 * Read-only: every query runs on the 'nivor' connection with SELECT only.
 */
class NivorWinningPatternMiner
{
    private const CONNECTION = 'nivor';

    private const CVR_OUTLIER_CEIL = 0.30;

    private const MIN_CLICKS_FOR_CVR = 50;

    /** niche key => campaign-name ILIKE tokens (Nivor's niche column is empty; inferred from names). */
    public const NICHES = [
        'weight_loss' => ['WL', 'LIPO', 'GELATIN', 'BURNSLIM', 'MOUNJA', 'CITRUS', 'WEIGHT', 'LULUTOX', 'KEYLEAN', 'SLIM'],
        'memory' => ['MEMOR', 'MEMO ', 'NERVE', 'COGNI', 'BRAIN', 'MOBO'],
        'prostate' => ['PROSTAT', 'PROSTA'],
        'ed' => ['ANDROMAX', 'VIGOR', 'VICKED', 'ERECT', 'ED_CNT'],
        'diabetes' => ['DIAB', 'T2D', 'GLUCO', 'GLYCO'],
        'vision' => ['VISAO', 'VISÃO', 'VISION', 'VISIUM', 'OPTIVEL', 'BLURRY'],
        'tinnitus' => ['TINNITUS', 'ECHOFREE', 'NITEHUSH'],
        'copd' => ['COPD', 'PULMON'],
        'dental' => ['DENTAL'],
        'gut' => ['GUT '],
        'herpes' => ['HERPES'],
        'blood_pressure' => ['PRESSURE'],
    ];

    /**
     * @return array<int,AiMarketingWinningPattern>
     */
    public function mineAll(): array
    {
        $out = [];
        foreach (array_keys(self::NICHES) as $niche) {
            $p = $this->mineNiche($niche);
            if ($p !== null) {
                $out[] = $p;
            }
        }

        return $out;
    }

    public function mineNiche(string $niche): ?AiMarketingWinningPattern
    {
        $tokens = self::NICHES[$niche] ?? null;
        if ($tokens === null) {
            return null;
        }

        $db = DB::connection(self::CONNECTION);

        $ids = $db->table('campaigns')->where(function ($q) use ($tokens): void {
            foreach ($tokens as $t) {
                $q->orWhere('name', 'ILIKE', '%'.$t.'%');
            }
        })->pluck('id_campaign')->all();
        if ($ids === []) {
            return null;
        }

        $sales = $db->table('conversions')->select('campaign_id', DB::raw('count(*) as n'))
            ->whereIn('campaign_id', $ids)->where('status', 'completed')->groupBy('campaign_id')
            ->pluck('n', 'campaign_id')->all();
        $ids = array_values(array_filter($ids, static fn ($id): bool => ((int) ($sales[$id] ?? 0)) > 0));
        if ($ids === []) {
            return null;
        }

        $clicks = $db->table('tracking_sessions')->select('campaign_id', DB::raw('count(distinct visitor_id) as n'))
            ->whereIn('campaign_id', $ids)->groupBy('campaign_id')->pluck('n', 'campaign_id')->all();
        $names = $db->table('campaigns')->whereIn('id_campaign', $ids)->pluck('name', 'id_campaign')->all();

        $cvrSet = [];
        $sample = [];
        $totalSales = 0;
        $totalClicks = 0;
        foreach ($ids as $id) {
            $s = (int) ($sales[$id] ?? 0);
            $c = (int) ($clicks[$id] ?? 0);
            $totalSales += $s;
            $totalClicks += $c;
            $cvr = $c > 0 ? $s / $c : null;
            $sample[] = ['id' => $id, 'name' => (string) ($names[$id] ?? ''), 'sales' => $s, 'clicks' => $c, 'cvr_pct' => $cvr !== null ? round($cvr * 100, 2) : null];
            if ($cvr !== null && $c >= self::MIN_CLICKS_FOR_CVR && $cvr <= self::CVR_OUTLIER_CEIL) {
                $cvrSet[] = $cvr;
            }
        }
        usort($sample, static fn ($a, $b): int => $b['sales'] <=> $a['sales']);

        $robust = $this->median($cvrSet);
        $blended = $totalClicks > 0 ? $totalSales / $totalClicks : null;
        $realCvr = $robust ?? $blended;
        $realCvrPct = $realCvr !== null ? round($realCvr * 100, 2) : null;

        $keywords = $db->table('conversions as cv')
            ->join('tracking_sessions as ts', 'ts.id_tracking_session', '=', 'cv.tracking_session_id')
            ->whereIn('cv.campaign_id', $ids)->where('cv.status', 'completed')
            ->whereNotNull('ts.utm_term')->where('ts.utm_term', '<>', '')
            ->select('ts.utm_term', DB::raw('count(*) as conv'))
            ->groupBy('ts.utm_term')->orderByDesc('conv')->limit(50)->get()
            ->map(static fn ($r): array => ['term' => (string) $r->utm_term, 'conversions' => (int) $r->conv])->all();

        $pageRows = $db->table('conversions as cv')
            ->join('tracking_sessions as ts', 'ts.id_tracking_session', '=', 'cv.tracking_session_id')
            ->whereIn('cv.campaign_id', $ids)->where('cv.status', 'completed')
            ->whereNotNull('ts.url')->where('ts.url', '<>', '')
            ->select('ts.url', DB::raw('count(*) as conv'))
            ->groupBy('ts.url')->orderByDesc('conv')->limit(40)->get();
        $byDomain = [];
        foreach ($pageRows as $p) {
            $host = parse_url((string) $p->url, PHP_URL_HOST) ?: 'unknown';
            $byDomain[$host] = ($byDomain[$host] ?? 0) + (int) $p->conv;
        }
        arsort($byDomain);
        $funnels = [];
        foreach ($byDomain as $host => $conv) {
            $funnels[] = ['domain' => $host, 'conversions' => $conv];
        }

        // --- deep dimensions ---
        $device = $this->splitByDimension($db, $ids, 'device');
        $network = $this->splitByDimension($db, $ids, 'network');
        $match = $this->splitByDimension($db, $ids, 'match_type');
        $keywordPerf = $this->keywordPerformance($db, $ids, $keywords);
        $economics = $this->economicsReal($db, $ids);
        $funnelProfile = $this->funnelProfile($db, $ids);
        $timing = $this->timing($db, $ids);
        $geo = $this->geoSplit($db, $ids);
        $bidding = $this->biddingOfWinners($db, $ids);

        $commonalities = $this->winnerCommonalities([
            'device' => $device, 'network' => $network, 'match' => $match,
            'funnels' => $funnels, 'keywords' => $keywords, 'cvr_pct' => $realCvrPct,
            'economics' => $economics, 'funnel' => $funnelProfile,
        ]);

        return AiMarketingWinningPattern::query()->updateOrCreate(
            ['niche' => $niche],
            [
                'source' => 'nivor',
                'real_cvr' => $realCvr !== null ? round($realCvr, 5) : null,
                'cvr_stats' => [
                    'robust_cvr_pct' => $robust !== null ? round($robust * 100, 2) : null,
                    'blended_cvr_pct' => $blended !== null ? round($blended * 100, 2) : null,
                    'method' => $cvrSet !== [] ? 'median_per_campaign_outliers_excluded' : 'blended_fallback',
                    'campaigns_in_cvr_baseline' => count($cvrSet),
                    'outlier_ceiling_pct' => self::CVR_OUTLIER_CEIL * 100,
                ],
                'converting_keywords' => array_slice($keywords, 0, 40),
                'winning_pages' => $pageRows->take(15)->map(static fn ($p): array => ['url' => (string) $p->url, 'conversions' => (int) $p->conv])->values()->all(),
                'winning_funnels' => array_slice($funnels, 0, 10),
                'campaigns_sample' => array_slice($sample, 0, 15),
                'campaigns_count' => count($ids),
                'sales_total' => $totalSales,
                'clicks_total' => $totalClicks,
                'economics_real' => $economics,
                'keyword_performance' => $keywordPerf,
                'device_split' => $device,
                'network_split' => $network,
                'match_type_split' => $match,
                'geo' => $geo,
                'timing' => $timing,
                'funnel_profile' => $funnelProfile,
                'bidding_of_winners' => $bidding,
                'winner_commonalities' => $commonalities,
                'computed_at' => now(),
            ],
        );
    }

    /**
     * Sales (converting sessions) + clicks (all sessions) + CVR, grouped by a session dimension.
     *
     * @param  array<int,mixed>  $ids
     * @return array<int,array<string,mixed>>
     */
    private function splitByDimension(ConnectionInterface $db, array $ids, string $col): array
    {
        $sales = $db->table('conversions as cv')
            ->join('tracking_sessions as ts', 'ts.id_tracking_session', '=', 'cv.tracking_session_id')
            ->whereIn('cv.campaign_id', $ids)->where('cv.status', 'completed')
            ->whereNotNull("ts.{$col}")->where("ts.{$col}", '<>', '')
            ->select("ts.{$col} as v", DB::raw('count(*) as n'))->groupBy("ts.{$col}")->pluck('n', 'v')->all();
        $clicks = $db->table('tracking_sessions')
            ->whereIn('campaign_id', $ids)->whereNotNull($col)->where($col, '<>', '')
            ->select("{$col} as v", DB::raw('count(distinct visitor_id) as n'))->groupBy($col)->pluck('n', 'v')->all();

        // Normalize inconsistent tracker coding (e.g. 'm'/'Mobile', 'g'/'google search') and re-aggregate.
        $labels = $this->dimLabels($col);
        $norm = static fn (string $v): string => $labels[strtolower(trim($v))] ?? strtolower(trim($v));
        $agg = [];
        foreach ($sales as $v => $s) {
            $k = $norm((string) $v);
            $agg[$k]['sales'] = ($agg[$k]['sales'] ?? 0) + (int) $s;
            $agg[$k]['clicks'] = $agg[$k]['clicks'] ?? 0;
        }
        foreach ($clicks as $v => $c) {
            $k = $norm((string) $v);
            $agg[$k]['clicks'] = ($agg[$k]['clicks'] ?? 0) + (int) $c;
            $agg[$k]['sales'] = $agg[$k]['sales'] ?? 0;
        }

        $out = [];
        foreach ($agg as $value => $d) {
            $s = (int) $d['sales'];
            $c = (int) $d['clicks'];
            // CVR only when there are enough clicks AND it's not the impossible sales>clicks artifact.
            $cvr = ($c >= 20 && $s <= $c) ? round($s / $c * 100, 2) : null;
            $out[] = ['value' => $value, 'sales' => $s, 'clicks' => $c, 'cvr_pct' => $cvr];
        }
        usort($out, static fn ($a, $b): int => $b['sales'] <=> $a['sales']);

        return array_slice($out, 0, 10);
    }

    /**
     * @return array<string,string>
     */
    private function dimLabels(string $col): array
    {
        return match ($col) {
            'device' => ['m' => 'mobile', 'mobile' => 'mobile', 't' => 'tablet', 'tablet' => 'tablet', 'desktop' => 'desktop'],
            'network' => ['g' => 'google_search', 's' => 'search', 'search' => 'search', 'd' => 'display', 'display' => 'display', 'google search' => 'google_search'],
            default => [],
        };
    }

    /**
     * @param  array<int,mixed>  $ids
     * @param  array<int,array<string,mixed>>  $convKw
     * @return array<string,mixed>
     */
    private function keywordPerformance(ConnectionInterface $db, array $ids, array $convKw): array
    {
        $terms = array_values(array_unique(array_filter(array_map(static fn ($k): string => (string) ($k['term'] ?? ''), $convKw), static fn ($t): bool => $t !== '')));
        if ($terms === []) {
            return ['by_cvr' => [], 'note' => 'sem utm_term nas sessões convertidas'];
        }
        $terms = array_slice($terms, 0, 60);

        $clicks = $db->table('tracking_sessions')
            ->whereIn('campaign_id', $ids)->whereIn('utm_term', $terms)
            ->select('utm_term', DB::raw('count(distinct visitor_id) as n'))->groupBy('utm_term')->pluck('n', 'utm_term')->all();
        $salesByTerm = [];
        foreach ($convKw as $k) {
            $salesByTerm[(string) ($k['term'] ?? '')] = (int) ($k['conversions'] ?? 0);
        }

        $rows = [];
        foreach ($terms as $t) {
            $s = (int) ($salesByTerm[$t] ?? 0);
            $c = (int) ($clicks[$t] ?? 0);
            $rows[] = ['term' => $t, 'sales' => $s, 'clicks' => $c, 'cvr_pct' => $c > 0 ? round($s / $c * 100, 2) : null];
        }
        $best = array_values(array_filter($rows, static fn ($r): bool => $r['sales'] >= 3 && $r['cvr_pct'] !== null));
        usort($best, static fn ($a, $b): int => $b['cvr_pct'] <=> $a['cvr_pct']);

        return ['by_cvr' => array_slice($best, 0, 20)];
    }

    /**
     * @param  array<int,mixed>  $ids
     * @return array<string,mixed>
     */
    private function economicsReal(ConnectionInterface $db, array $ids): array
    {
        if (! Schema::connection(self::CONNECTION)->hasTable('campaign_daily_snapshots')) {
            return ['available' => false];
        }
        $rows = $db->table('campaign_daily_snapshots')->whereIn('campaign_id', $ids)->get();
        if ($rows->isEmpty()) {
            return ['available' => false, 'note' => 'sem snapshots reconciliados para este nicho'];
        }

        $cost = 0.0;
        $rev = 0.0;
        $profit = 0.0;
        $purch = 0;
        $conv = 0.0;
        $covered = [];
        foreach ($rows as $r) {
            $cost += (float) $r->cost;
            $rev += (float) $r->conversions_value;
            $profit += (float) $r->profit;
            $purch += (int) $r->purchases;
            $conv += (float) $r->conversions;
            $covered[$r->campaign_id] = true;
        }

        return [
            'available' => true,
            'currency' => 'BRL',
            'cost' => round($cost, 2),
            'revenue' => round($rev, 2),
            'profit' => round($profit, 2),
            'roas' => $cost > 0 ? round($rev / $cost, 2) : null,
            'real_cpa' => $purch > 0 ? round($cost / $purch, 2) : ($conv > 0 ? round($cost / $conv, 2) : null),
            'snapshot_days' => $rows->count(),
            'campaigns_covered' => count($covered),
            'low_confidence' => $profit < 0 || count($covered) < (int) ceil(count($ids) * 0.5),
            'low_confidence_reason' => 'snapshots reconciliados esparsos e receita neles subcapturada — usar contagem de vendas + custo do Google como verdade, NÃO este ROI.',
        ];
    }

    /**
     * @param  array<int,mixed>  $ids
     * @return array<string,mixed>
     */
    private function funnelProfile(ConnectionInterface $db, array $ids): array
    {
        if (! Schema::connection(self::CONNECTION)->hasTable('campaign_daily_snapshots')) {
            return ['available' => false];
        }
        $rows = $db->table('campaign_daily_snapshots')->whereIn('campaign_id', $ids)->get();
        if ($rows->isEmpty()) {
            return ['available' => false];
        }

        $sum = static fn (string $f): float => (float) $rows->sum(static fn ($r) => (float) ($r->{$f} ?? 0));
        $visits = $sum('funnel_visits');
        $vslViews = $sum('vsl_views');
        $vslPlays = $sum('vsl_plays');
        $vsl100 = $sum('vsl_100_percent');
        $checkout = $sum('checkout_visits');
        $purch = $sum('purchases');
        $rate = static fn (float $a, float $b): ?float => $b > 0 ? round($a / $b * 100, 2) : null;

        return [
            'available' => true,
            'funnel_visits' => (int) $visits,
            'vsl_views' => (int) $vslViews,
            'vsl_plays' => (int) $vslPlays,
            'checkout_visits' => (int) $checkout,
            'purchases' => (int) $purch,
            'vsl_play_rate_pct' => $rate($vslPlays, $vslViews),
            'vsl_completion_rate_pct' => $rate($vsl100, $vslPlays),
            'vsl_to_checkout_pct' => $rate($checkout, $vslPlays),
            'checkout_conversion_pct' => $rate($purch, $checkout),
            'funnel_conversion_pct' => $rate($purch, $visits),
        ];
    }

    /**
     * @param  array<int,mixed>  $ids
     * @return array<string,mixed>
     */
    private function timing(ConnectionInterface $db, array $ids): array
    {
        $rows = $db->table('conversions')->whereIn('campaign_id', $ids)->where('status', 'completed')
            ->whereNotNull('converted_at')->pluck('converted_at');
        $byHour = array_fill(0, 24, 0);
        $byDow = array_fill(0, 7, 0);
        foreach ($rows as $v) {
            $t = strtotime((string) $v);
            if ($t === false) {
                continue;
            }
            $byHour[(int) date('G', $t)]++;
            $byDow[(int) date('w', $t)]++;
        }
        arsort($byHour);
        $dows = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sab'];
        $byDowOut = [];
        foreach ($byDow as $i => $n) {
            $byDowOut[$dows[$i]] = $n;
        }

        return ['top_hours' => array_slice($byHour, 0, 6, true), 'by_dow' => $byDowOut, 'sample' => $rows->count()];
    }

    /**
     * @param  array<int,mixed>  $ids
     * @return array<int,array<string,mixed>>
     */
    private function geoSplit(ConnectionInterface $db, array $ids): array
    {
        if (! Schema::connection(self::CONNECTION)->hasColumn('tracking_sessions', 'traffic_country')) {
            return [];
        }

        return $db->table('conversions as cv')
            ->join('tracking_sessions as ts', 'ts.id_tracking_session', '=', 'cv.tracking_session_id')
            ->whereIn('cv.campaign_id', $ids)->where('cv.status', 'completed')
            ->whereNotNull('ts.traffic_country')->where('ts.traffic_country', '<>', '')
            ->select('ts.traffic_country', DB::raw('count(*) as n'))
            ->groupBy('ts.traffic_country')->orderByDesc('n')->limit(8)->get()
            ->map(static fn ($r): array => ['country' => (string) $r->traffic_country, 'sales' => (int) $r->n])->all();
    }

    /**
     * @param  array<int,mixed>  $ids
     * @return array<int,array<string,mixed>>
     */
    private function biddingOfWinners(ConnectionInterface $db, array $ids): array
    {
        if (! Schema::connection(self::CONNECTION)->hasTable('google_campaigns')) {
            return [];
        }

        return $db->table('google_campaigns')->whereIn('campaign_id', $ids)
            ->select('bidding_strategy_type', DB::raw('count(*) as n'))
            ->groupBy('bidding_strategy_type')->orderByDesc('n')->limit(8)->get()
            ->map(static fn ($r): array => ['strategy' => (string) ($r->bidding_strategy_type ?? 'unknown'), 'campaigns' => (int) $r->n])->all();
    }

    /**
     * Synthesize the structured "this is what works" pattern from the dimensions.
     *
     * @param  array<string,mixed>  $p
     * @return array<string,mixed>
     */
    private function winnerCommonalities(array $p): array
    {
        $top = static fn (array $arr, string $key) => isset($arr[0]) ? ($arr[0][$key] ?? null) : null;
        $angle = $this->inferAngle($p['keywords'] ?? []);
        $eco = is_array($p['economics'] ?? null) ? $p['economics'] : [];
        $fun = is_array($p['funnel'] ?? null) ? $p['funnel'] : [];
        $ecoOk = ($eco['available'] ?? false) && ! ($eco['low_confidence'] ?? false);

        return [
            'dominant_device' => $top($p['device'] ?? [], 'value'),
            'dominant_network' => $top($p['network'] ?? [], 'value'),
            'dominant_match_type' => $top($p['match'] ?? [], 'value'),
            'top_funnel_domain' => $top($p['funnels'] ?? [], 'domain'),
            'top_keyword' => $top($p['keywords'] ?? [], 'term'),
            'keyword_angle' => $angle,
            'real_cvr_pct' => $p['cvr_pct'] ?? null,
            'real_roas' => $ecoOk ? ($eco['roas'] ?? null) : null,
            'real_cpa' => $ecoOk ? ($eco['real_cpa'] ?? null) : null,
            'economics_note' => $ecoOk ? null : 'ROI confiável indisponível (snapshots esparsos) — verdade = contagem de vendas',
            'vsl_play_rate_pct' => $fun['vsl_play_rate_pct'] ?? null,
            'checkout_conversion_pct' => $fun['checkout_conversion_pct'] ?? null,
            'pattern_summary' => sprintf(
                'Search frio (%s, %s) → advertorial %s → VSL; ângulo de keyword = %s; CVR real ~%s%%%s.',
                $top($p['network'] ?? [], 'value') ?? '?',
                $top($p['device'] ?? [], 'value') ?? '?',
                $top($p['funnels'] ?? [], 'domain') ?? '?',
                $angle ?? '?',
                $p['cvr_pct'] ?? '?',
                $ecoOk && isset($eco['real_cpa']) && $eco['real_cpa'] !== null ? '; CPA real ~'.$eco['real_cpa'].' BRL' : '',
            ),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $keywords
     */
    private function inferAngle(array $keywords): ?string
    {
        $terms = strtolower(implode(' ', array_map(static fn ($k): string => (string) ($k['term'] ?? ''), array_slice($keywords, 0, 15))));
        $map = ['recipe' => 'receita', 'trick' => 'truque', 'dr ' => 'autoridade-medica', 'dr.' => 'autoridade-medica', 'natural' => 'natural', 'how to' => 'how-to', 'review' => 'review', 'alternative' => 'alternativa', 'injection' => 'anti-injecao'];
        $tags = [];
        foreach ($map as $needle => $label) {
            if (str_contains($terms, $needle)) {
                $tags[$label] = true;
            }
        }

        return $tags !== [] ? implode('+', array_keys($tags)) : null;
    }

    /**
     * @param  array<int,float>  $vals
     */
    private function median(array $vals): ?float
    {
        if ($vals === []) {
            return null;
        }
        sort($vals);
        $n = count($vals);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $vals[$mid] : ($vals[$mid - 1] + $vals[$mid]) / 2;
    }
}
