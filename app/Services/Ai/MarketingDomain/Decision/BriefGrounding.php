<?php

declare(strict_types=1);

namespace App\Services\Ai\MarketingDomain\Decision;

use App\Models\AiMarketingWinningPattern;

/**
 * Centralizes pattern→brief injection so generic playbook briefs get proven
 * angles/keywords/device priorities grafted into suggested_* fields. Deterministic.
 *
 * Full-pass honesty rename: was BriefGroundingHelper.
 */
final class BriefGrounding
{
    /**
     * @return array<string, mixed>
     */
    public function groundBriefFromPattern(?AiMarketingWinningPattern $pattern, string $briefType = 'generic'): array
    {
        if ($pattern === null) {
            return ['grounded' => false, 'brief_type' => $briefType];
        }

        $commonalities = is_array($pattern->winner_commonalities) ? $pattern->winner_commonalities : [];

        return [
            'grounded' => true,
            'brief_type' => $briefType,
            'source' => 'nivor:'.$pattern->niche,
            'real_cvr' => (float) $pattern->real_cvr,
            'suggested_keywords' => array_slice((array) $pattern->converting_keywords, 0, 20),
            'suggested_funnels' => array_slice((array) $pattern->winning_funnels, 0, 8),
            'device_priority' => $commonalities['dominant_device'] ?? ($pattern->device_split ?? null),
            'network_priority' => $commonalities['dominant_network'] ?? null,
            'keyword_angle' => $commonalities['keyword_angle'] ?? null,
            'pattern_summary' => $commonalities['pattern_summary'] ?? null,
        ];
    }
}

