<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * VslPageGuard — enforces the elite-VSL-page doctrine: attention ratio 1:1 (one page, one goal = WATCH,
 * zero exit paths). On a VSL page that feeds a long video whose CTA is revealed by the player at the
 * pitch, almost everything people ADD because they BELIEVE it converts actually DESTROYS conversion by
 * stealing attention from the video. This guard catches those crimes deterministically so a page can be
 * proven clean before launch — the VSL-page analogue of BridgeSpoilerDetector. Provider-free.
 */
class VslPageGuard
{
    /** Action words that betray a page-level CTA (the player owns the CTA, timed to the pitch). */
    private const CTA_WORDS = [
        'buy now', 'buy ', 'order now', 'order your', 'add to cart', 'checkout', 'check out',
        'get yours', 'get it now', 'get instant', 'claim your', 'claim now', 'reserve your',
        'add to bag', 'shop now', 'purchase', 'subscribe now', 'sign up', 'start your order',
    ];

    /**
     * @return array{crimes:array<int,array<string,mixed>>,n:int,verdict:string,attention_ratio:string}
     */
    public function inspect(string $html): array
    {
        $crimes = [];

        // Strip the deliberately-hidden pitch container before scanning for "visible" violations:
        // content inside .hide / .vsl-pitch is revealed only at the pitch, so it's allowed.
        $visible = (string) preg_replace('#<[^>]*class="[^"]*\bhide\b[^"]*"[^>]*>.*?</[^>]+>#is', ' ', $html);

        // CRIME 1 — page-level CTA (anchors/buttons with order/buy intent). The player reveals the CTA.
        if (preg_match('/<(a|button)\b[^>]*>(.*?)<\/\1>/is', $visible, $allBtns)) {
            foreach ($this->matchAll('/<(?:a|button)\b[^>]*>(.*?)<\/(?:a|button)>/is', $visible) as $btnText) {
                $t = mb_strtolower(trim((string) preg_replace('/<[^>]+>/', ' ', $btnText)));
                foreach (self::CTA_WORDS as $w) {
                    if (str_contains($t, $w)) {
                        $crimes[] = ['key' => 'own_cta', 'severity' => 'critical', 'evidence' => mb_strimwidth($t, 0, 50, ''),
                            'fix' => 'Remove the page CTA. The player (vTurb) reveals the CTA at the exact pitch second — a page CTA offers an exit before buying motivation exists.'];
                        break 2;
                    }
                }
            }
        }

        // CRIME 2 — exit links: any anchor leaving to another page/site = an attention leak.
        foreach ($this->matchAll('/<a\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $visible) as $href) {
            $href = trim($href);
            if ($href === '' || $href === '#' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
                continue;
            }
            $crimes[] = ['key' => 'exit_link', 'severity' => 'critical', 'evidence' => mb_strimwidth($href, 0, 50, ''),
                'fix' => 'Remove every outbound link. Attention ratio must be 1:1 — the only interaction on the page is the video.'];
        }

        // CRIME 3 — navigation / menu (multiple links grouped, or a <nav>).
        if (preg_match('/<nav\b/i', $visible)) {
            $crimes[] = ['key' => 'nav_menu', 'severity' => 'critical', 'evidence' => '<nav> present',
                'fix' => 'A VSL page has no navigation. Delete the nav/menu entirely.'];
        }

        // CRIME 4 — competing videos / iframes (only ONE player allowed).
        $players = preg_match_all('/<(?:vturb-smartplayer|video|iframe)\b/i', $visible);
        if ($players > 1) {
            $crimes[] = ['key' => 'multiple_players', 'severity' => 'high', 'evidence' => $players.' players/embeds',
                'fix' => 'Keep exactly one video. A second video or iframe splits attention and lowers watch-through.'];
        }

        // CRIME 5 — visible sales copy BEFORE the pitch (lets them read instead of watch → they bounce).
        // Use the shared extractor so <script>/<style> bodies are removed FIRST (otherwise inline JS,
        // like the headline schedule array, would be miscounted as visible body copy).
        $textOnly = HtmlCopyExtractor::plainText($visible);
        $words = $textOnly === '' ? 0 : count(preg_split('/\s+/u', $textOnly));
        if ($words > 140) {
            $crimes[] = ['key' => 'sales_copy_before_pitch', 'severity' => 'high', 'evidence' => $words.' visible words',
                'fix' => 'Strip the body copy above the pitch. Visible long copy competes with the video — viewers read the gist and leave. Keep only the rotating headline; reveal anything else at the pitch via vTurb displayHiddenElements.'];
        }

        $hasPlayer = $players >= 1;
        $worst = $this->worst($crimes);

        return [
            'crimes' => $crimes,
            'n' => count($crimes),
            'attention_ratio' => $hasPlayer && count($crimes) === 0 ? '1:1' : 'broken',
            'verdict' => ! $hasPlayer ? 'no_player'
                : (count($crimes) === 0 ? 'clean'
                : ($worst === 'critical' ? 'distracting' : 'leaky')),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function matchAll(string $pattern, string $subject): array
    {
        return preg_match_all($pattern, $subject, $m) ? $m[1] : [];
    }

    /**
     * @param  array<int,array<string,mixed>>  $crimes
     */
    private function worst(array $crimes): string
    {
        $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        $best = 9;
        $worst = 'none';
        foreach ($crimes as $c) {
            $r = $rank[$c['severity']] ?? 9;
            if ($r < $best) {
                $best = $r;
                $worst = (string) $c['severity'];
            }
        }

        return $worst;
    }
}
