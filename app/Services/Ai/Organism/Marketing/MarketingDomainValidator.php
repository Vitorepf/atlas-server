<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism\Marketing;

use App\Services\Ai\Organism\DomainProposal;
use App\Services\Ai\Organism\DomainValidator;

/**
 * AOBG N4.F4 — the MARKETING {@see DomainValidator}: a REAL, deterministic content-quality
 * HEURISTIC — never a vanity metric.
 *
 * The honest-metric contract (the same one finance honours with DSR/PBO) says: a proposal is
 * NEVER self-declared a success; it is scored on a real metric that cannot be gamed by the
 * domain's textbook fake-green. For marketing the textbook fake-green is VANITY ENGAGEMENT —
 * raw impressions / likes / clicks / click-bait. Those are FORBIDDEN here (the analogue of
 * win-rate for finance). Instead the draft is scored on a deterministic content-QUALITY rubric:
 *
 *   - clarity: a non-empty, reasonably-bounded headline (not absurdly long, not empty);
 *   - call-to-action present;
 *   - audience specified (the draft targets someone, not "everyone");
 *   - body substance: enough copy to be a real draft, not a stub;
 *   - anti-click-bait: the headline avoids hype tokens / ALL-CAPS shouting / excessive "!!!".
 *
 * The score is the fraction of the rubric satisfied in [0,1]; `passed` requires a materially
 * high bar (not a >0 sign test). HONEST-EMPTY when there is no scorable draft (value null,
 * passed false) — never a fabricated green.
 *
 * COST: pure in-process string math — zero provider, zero network, sqlite-safe.
 */
final class MarketingDomainValidator implements DomainValidator
{
    public const DOMAIN = 'marketing';

    /** A materially-high quality bar — NOT a >0 sign test, NOT a vanity engagement count. */
    private const QUALITY_FLOOR = 0.8;

    /** Hype/click-bait tokens that mark a vanity headline (the marketing fake-green). */
    private const CLICKBAIT_TOKENS = [
        'you won\'t believe', 'shocking', 'one weird trick', 'click here', 'act now',
        'limited time', 'guaranteed', 'miracle', '100% free', 'secret they don\'t want',
    ];

    public function validate(DomainProposal $proposal): array
    {
        $payload = $proposal->payload;

        $headline = trim((string) ($payload['headline'] ?? ''));
        $body = trim((string) ($payload['body'] ?? $proposal->content));
        $audience = trim((string) ($payload['audience'] ?? ''));
        $hasCta = (bool) ($payload['has_cta'] ?? $this->detectCta($body));

        // HONEST-EMPTY: no scorable draft (no headline AND no body) ⇒ never invent a pass.
        if ($headline === '' && $body === '') {
            return [
                'metric' => 'content_quality',
                'value' => null,
                'passed' => false,
                'method' => 'marketing.content_quality.in_process(vanity_metrics_forbidden)',
                'reasons' => ['no_draft'],
            ];
        }

        $checks = [
            'clarity_headline' => $headline !== '' && mb_strlen($headline) <= 100,
            'has_cta' => $hasCta,
            'audience_specified' => $audience !== '' && mb_strtolower($audience) !== 'everyone',
            'body_substance' => mb_strlen($body) >= 60,
            'anti_clickbait' => ! $this->isClickbait($headline),
        ];

        $passedCount = count(array_filter($checks));
        $total = count($checks);
        $score = $total > 0 ? round($passedCount / $total, 6) : 0.0;

        $reasons = [];
        foreach ($checks as $name => $ok) {
            if (! $ok) {
                $reasons[] = 'failed:'.$name;
            }
        }
        $passed = $score >= self::QUALITY_FLOOR && $reasons === [];

        return [
            'metric' => 'content_quality',
            'value' => $score,
            'passed' => $passed,
            // Names the heuristic + that vanity engagement metrics are forbidden. Never "likes".
            'method' => 'marketing.content_quality.in_process(vanity_metrics_forbidden)',
            'reasons' => $reasons === [] ? ['quality_cleared_floor'] : $reasons,
            'detail' => [
                'quality_floor' => self::QUALITY_FLOOR,
                'checks_passed' => $passedCount,
                'checks_total' => $total,
            ],
        ];
    }

    public function domain(): string
    {
        return self::DOMAIN;
    }

    private function detectCta(string $body): bool
    {
        $lower = mb_strtolower($body);
        foreach (['call-to-action', 'learn more', 'sign up', 'get started', 'subscribe', 'cta'] as $cue) {
            if (mb_strpos($lower, $cue) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isClickbait(string $headline): bool
    {
        if ($headline === '') {
            return false;
        }
        $lower = mb_strtolower($headline);
        foreach (self::CLICKBAIT_TOKENS as $token) {
            if (mb_strpos($lower, $token) !== false) {
                return true;
            }
        }
        // Shouting: excessive exclamation or majority ALL-CAPS words.
        if (substr_count($headline, '!') >= 2) {
            return true;
        }
        $words = preg_split('/\s+/u', $headline) ?: [];
        $caps = 0;
        $alpha = 0;
        foreach ($words as $w) {
            if (preg_match('/[A-Za-z]/', $w)) {
                $alpha++;
                if ($w === mb_strtoupper($w) && mb_strlen($w) >= 3) {
                    $caps++;
                }
            }
        }

        return $alpha > 0 && ($caps / $alpha) > 0.5;
    }
}
