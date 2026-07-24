<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingArtifact;
use Illuminate\Support\Str;
use Throwable;

/**
 * MarketingSkillRegistry — the self-improvement loop. When a generated page clears a high-quality bar
 * (no keyword crime, policy-safe, strong page audit + keyword coverage), the registry distills the
 * WINNING PATTERN (niche + gender + the headline shape + the triggers that worked + the scores) and
 * stores it as a learned skill. Future generations for the same niche/audience recall those winners
 * and build on them — so quality compounds instead of resetting every run.
 *
 * Persisted as an AiMarketingArtifact (artifact_type='learned_skill') — no new table; the metadata
 * carries niche/gender/score for fast recall. Best-effort: learning never breaks a generation.
 */
class MarketingSkillRegistry
{
    private const MIN_AUDIT = 80;

    private const MIN_COVERAGE = 40;

    /**
     * Learn from a composer result if it cleared the quality bar. Returns the skill id or null.
     *
     * @param  array<string,mixed>  $result
     */
    public function learn(array $result): ?string
    {
        $v = is_array($result['validation'] ?? null) ? $result['validation'] : [];
        $g = is_array($result['grounding'] ?? null) ? $result['grounding'] : [];
        $bridge = is_array($result['bridge'] ?? null) ? $result['bridge'] : [];

        if (! $this->isHighQuality($v)) {
            return null;
        }

        $niche = (string) ($g['niche'] ?? $g['pattern_niche'] ?? '');
        $gender = (string) ($g['audience_gender'] ?? 'neutral');
        $score = $this->qualityScore($v);

        $skill = [
            'niche' => $niche,
            'gender' => $gender,
            'awareness' => $g['awareness_target'] ?? null,
            'angle' => $g['angle'] ?? null,
            'winning_headline' => (string) ($bridge['headline'] ?? ''),
            'winning_kicker' => (string) ($bridge['kicker'] ?? ''),
            'fold_count' => $g['fold_count'] ?? null,
            'scores' => [
                'quality' => $score,
                'anchor_rate' => $v['keyword_relevance']['anchor_rate'] ?? null,
                'keyword_coverage' => $v['keyword_coverage']['score'] ?? null,
                'page_audit' => $v['page_audit']['overall_score'] ?? null,
            ],
        ];

        try {
            $artifact = AiMarketingArtifact::create([
                'schema_version' => 'atlas.vsl.learned_skill.v1',
                'uuid' => (string) Str::uuid(),
                'artifact_type' => 'learned_skill',
                'title' => Str::limit('Winning pattern · '.$niche.' · '.$gender, 180, ''),
                'payload' => $skill,
                'status' => 'active',
                'artifact_hash' => hash('sha256', $niche.'|'.$gender.'|'.$skill['winning_headline']),
                'metadata' => ['niche' => $niche, 'gender' => $gender, 'quality' => $score],
            ]);

            return $artifact->id;
        } catch (Throwable) {
            return null; // learning is best-effort
        }
    }

    /**
     * Recall the top winning patterns for a niche/gender, best score first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function recall(?string $niche, ?string $gender, int $limit = 3): array
    {
        try {
            $rows = AiMarketingArtifact::query()
                ->where('artifact_type', 'learned_skill')
                ->when($niche, fn ($q) => $q->where('metadata->niche', $niche))
                ->when($gender, fn ($q) => $q->where('metadata->gender', $gender))
                ->orderByDesc('metadata->quality')
                ->limit($limit)
                ->get();
        } catch (Throwable) {
            return [];
        }

        return $rows->map(static fn (AiMarketingArtifact $a): array => is_array($a->payload) ? $a->payload : [])
            ->filter()
            ->values()
            ->all();
    }

    /**
     * A compact prompt block of prior winners for this niche/audience (kept short — bloat kills output).
     */
    public function recallPromptBlock(?string $niche, ?string $gender): string
    {
        $skills = $this->recall($niche, $gender, 2);
        if ($skills === []) {
            return '';
        }
        $lines = [];
        foreach ($skills as $s) {
            $hl = trim((string) ($s['winning_headline'] ?? ''));
            $q = (int) ($s['scores']['quality'] ?? 0);
            if ($hl !== '') {
                $lines[] = '• (qualidade '.$q.'/100) "'.Str::limit($hl, 140).'"';
            }
        }
        if ($lines === []) {
            return '';
        }

        return "\n=== PADRÕES QUE JÁ VENCERAM NESTE NICHO/PÚBLICO (aprendidos pelo Atlas — siga o que funcionou, supere) ===\n".implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string,mixed>  $v  validation block
     */
    private function isHighQuality(array $v): bool
    {
        $noCrime = ($v['keyword_relevance']['verdict'] ?? null) === 'ok';
        $cleanCopy = ($v['copy_quality']['verdict'] ?? 'ok') === 'ok';   // never learn meta/generic copy
        $safe = (bool) ($v['policy']['safe_to_publish'] ?? false);
        $audit = (int) ($v['page_audit']['overall_score'] ?? 0);
        $coverage = (int) ($v['keyword_coverage']['score'] ?? 0);

        return $noCrime && $cleanCopy && $safe && $audit >= self::MIN_AUDIT && $coverage >= self::MIN_COVERAGE;
    }

    /**
     * @param  array<string,mixed>  $v
     */
    private function qualityScore(array $v): int
    {
        $audit = (int) ($v['page_audit']['overall_score'] ?? 0);
        $coverage = (int) ($v['keyword_coverage']['score'] ?? 0);
        $anchor = (int) ($v['keyword_relevance']['anchor_rate'] ?? 0);

        return (int) round($audit * 0.5 + $coverage * 0.25 + $anchor * 0.25);
    }
}
