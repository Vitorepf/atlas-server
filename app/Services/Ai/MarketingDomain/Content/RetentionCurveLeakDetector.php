<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Services\Ai\MarketingDomain\Knowledge\VideoCreativeAnatomyLibrary;

/**
 * RetentionCurveLeakDetector — the cure for the "flat middle", the biggest watch-through lever after the
 * 3-second hook.
 *
 * The hook-at-open is one structural fact; the next is the CURVE: a video/VSL bleeds most of its audience
 * in the middle stretch where it stops opening new loops / re-hooks / escalating stakes, and the viewer
 * slides to skip. This segments the transcript into thirds (or N) and, modeled byte-for-byte on
 * WatchThroughLeakDetector's philosophy (true-positive WARNINGS, ZERO quality score — because a vocabulary
 * score inverts on adversarial copy, as the dead NarrativeTensionScorer proved), flags any non-opening,
 * non-closing segment that opens NO loop/re-hook as a flat-middle leak. Reuses VideoCreativeAnatomyLibrary's
 * re_hook + curiosity-gap markers (no regex reimplemented). Structural, provider-free, niche-agnostic.
 */
class RetentionCurveLeakDetector
{
    public function __construct(private readonly VideoCreativeAnatomyLibrary $anatomy = new VideoCreativeAnatomyLibrary) {}

    /**
     * @return array{segments:array<int,array{index:int,has_loop:bool}>,flaws:array<int,array{type:string,segment:int}>,segment_count:int,note:string}
     */
    public function detect(string $copy, int $segmentCount = 3): array
    {
        $segmentCount = max(2, $segmentCount);
        $sentences = array_values(array_filter(array_map('trim', preg_split('/(?<![0-9])[.!?]+(?![0-9])|\n+/u', $copy) ?: []), static fn ($s) => $s !== ''));
        if (count($sentences) < $segmentCount) {
            return ['segments' => [], 'flaws' => [], 'segment_count' => $segmentCount, 'note' => 'Copy curta demais pra avaliar a curva de retenção.'];
        }

        $loopMarkers = $this->loopMarkers();
        $chunks = array_chunk($sentences, (int) ceil(count($sentences) / $segmentCount));

        $segments = [];
        $flaws = [];
        foreach ($chunks as $i => $chunk) {
            $text = mb_strtolower(implode(' ', $chunk));
            $hasLoop = false;
            foreach ($loopMarkers as $m) {
                if ($m !== '' && str_contains($text, $m)) {
                    $hasLoop = true;
                    break;
                }
            }
            $segments[] = ['index' => $i, 'has_loop' => $hasLoop];
            // The opening segment carries the hook and the last can be the CTA/close; the MIDDLE stretch is
            // where retention dies if it opens no new loop.
            if ($i > 0 && $i < count($chunks) - 1 && ! $hasLoop) {
                $flaws[] = ['type' => 'flat_middle', 'segment' => $i];
            }
        }

        $note = $flaws === []
            ? 'Curva de retenção ok: o meio reabre loops/re-hooks — sem flat-middle.'
            : count($flaws).' segmento(s) do meio sem re-hook/open-loop (flat-middle) — onde a audiência desliza pro skip. Reabrir um loop maior ("mas espera, fica pior…" / "em um segundo eu te mostro…") ali.';

        return ['segments' => $segments, 'flaws' => $flaws, 'segment_count' => $segmentCount, 'note' => $note];
    }

    /** Loop/re-hook markers pulled from the anatomy library (re_hook category + the curiosity-gap hook). */
    private function loopMarkers(): array
    {
        $markers = [];
        foreach ($this->anatomy->all() as $p) {
            if (($p['category'] ?? '') === 're_hook' || ($p['key'] ?? '') === 'vc_hook_curiosity_gap') {
                foreach ((array) ($p['markers'] ?? []) as $m) {
                    $markers[] = mb_strtolower((string) $m);
                }
            }
        }

        return array_values(array_unique($markers));
    }
}
