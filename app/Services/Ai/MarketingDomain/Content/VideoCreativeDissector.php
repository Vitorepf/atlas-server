<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Services\Ai\MarketingDomain\Knowledge\VideoCreativeAnatomyLibrary;

/**
 * VideoCreativeDissector — dissects a YT/Meta video creative (from its transcript/description) against the
 * VideoCreativeAnatomyLibrary: which beats, formats and malícias it runs, whether it actually opens with a
 * 3-second hook (a structural FACT — a hook buried at second 30 is not a hook), and the #1 gap costing it
 * performance. The video analog of ConversionLeverageDiagnostic, ready for the operator's creative library.
 *
 * Honest labeling: pattern PRESENCE is a heuristic prior (catalog match), not proven performance — the
 * real winners come from dissecting the library and feeding the outcome ledger. The one hard structural
 * truth here is hook-at-the-open. Provider-free, no moral brake (the malícias are surfaced as capability).
 */
class VideoCreativeDissector
{
    /** Roughly the words spoken/shown in the first ~3 seconds of a video ad. */
    private const HOOK_WINDOW_CHARS = 160;

    public function __construct(
        private readonly PatternLibraryScorer $scorer = new PatternLibraryScorer,
        private readonly VideoCreativeAnatomyLibrary $anatomy = new VideoCreativeAnatomyLibrary,
    ) {}

    /**
     * @return array{present_by_category:array<string,array<int,string>>,hook_at_open:bool,formats:array<int,string>,malicias:array<int,string>,gaps:array<int,string>,bottleneck:?string,summary:string}
     */
    public function dissect(string $transcript): array
    {
        $present = $this->scorer->score($this->anatomy, $transcript)['present'] ?? [];
        $catOf = [];
        foreach ($this->anatomy->all() as $p) {
            $catOf[$p['key']] = $p['category'];
        }
        $byCat = [];
        foreach ($present as $key) {
            $byCat[$catOf[$key] ?? 'other'][] = $key;
        }

        // A 3-second hook must live at the OPEN — score only the first window and keep hook_3s hits.
        $openPresent = $this->scorer->score($this->anatomy, mb_substr(trim($transcript), 0, self::HOOK_WINDOW_CHARS))['present'] ?? [];
        $hookAtOpen = array_values(array_filter($openPresent, static fn ($k) => ($catOf[$k] ?? '') === 'hook_3s'));

        $has = static fn (string $cat): bool => ! empty($byCat[$cat] ?? []);

        // Gaps ordered by leverage: the opening hook is #1, then proof, then a clear CTA, then format.
        $gaps = [];
        if ($hookAtOpen === []) {
            $gaps[] = 'sem hook nos 3 primeiros segundos — o maior lever do vídeo (abre com cena/claim/curiosidade, não com setup)';
        }
        if (! $has('proof_device')) {
            $gaps[] = 'sem prova visual (depoimento UGC / antes-depois / demo)';
        }
        if (! $has('cta')) {
            $gaps[] = 'sem CTA claro (assistir/clicar)';
        }
        if (! $has('format')) {
            $gaps[] = 'formato indefinido (UGC / talking-head / native-disguise)';
        }

        return [
            'present_by_category' => $byCat,
            'hook_at_open' => $hookAtOpen !== [],
            'formats' => $byCat['format'] ?? [],
            'malicias' => $byCat['malicia'] ?? [],
            'gaps' => $gaps,
            'bottleneck' => $gaps[0] ?? null,
            'summary' => $gaps === []
                ? 'Criativo completo na anatomia: hook no open + '.implode(' + ', array_keys($byCat)).'.'
                : 'GARGALO #1: '.$gaps[0],
        ];
    }
}
