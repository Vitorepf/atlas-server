<?php

namespace App\Services\Ai\MarketingDomain\Scoring;

use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;

/**
 * PageAuditScorer — the page-architect skill made executable. Audits a landing page against the 5
 * highest-impact conversion levers (headline=ad, fewer form fields, <2.5s load, trust below hero,
 * 1 goal) plus messaging focus, scores each 0-100, names the bottleneck and emits recommendations.
 * Headline congruency is delegated to MessageMatchScorer. Deterministic; no DB.
 */
class PageAuditScorer
{
    private const LOAD_FLOOR_MS = 2500;

    public function __construct(
        private readonly MessageMatchScorer $messageMatch = new MessageMatchScorer,
        private readonly MarketingPlaybook $playbook = new MarketingPlaybook,
    ) {}

    /**
     * @param  array<string,mixed>  $page  title, form_fields[], cta_placements[], load_time_ms,
     *                                      ad_headline, vsl_promise, trust_near_top(bool), conversion_goals
     * @return array<string,mixed>
     */
    public function auditPage(array $page): array
    {
        $formFields = max(0, (int) count((array) ($page['form_fields'] ?? [])));
        $ctaCount = (int) count((array) ($page['cta_placements'] ?? []));
        $loadMs = (int) ($page['load_time_ms'] ?? 0);
        $goals = (int) ($page['conversion_goals'] ?? 1);

        $headlineMatch = $this->messageMatch->score(
            (string) ($page['ad_headline'] ?? ''),
            (string) ($page['title'] ?? ''),
            (string) ($page['vsl_promise'] ?? ''),
        )['dimension_scores']['message_match'];

        $levers = [
            'headline_match' => $headlineMatch,
            'form_fields' => $formFields <= 1 ? 100 : max(0, 100 - ($formFields - 1) * 20),
            'load_time' => $loadMs <= 0 ? 50 : (int) max(0, min(100, 100 - max(0, $loadMs - self::LOAD_FLOOR_MS) / 35)),
            'trust_position' => ! empty($page['trust_near_top']) ? 100 : 40,
            'cta_clarity' => ($ctaCount >= 2 && $ctaCount <= 4) ? 100 : ($ctaCount === 1 || $ctaCount === 5 ? 60 : 20),
            'messaging_focus' => $goals <= 1 ? 100 : max(0, 100 - ($goals - 1) * 40),
        ];

        $overall = (int) round(array_sum($levers) / count($levers));
        asort($levers);
        $bottleneck = (string) array_key_first($levers);
        arsort($levers);

        return [
            'overall_score' => $overall,
            'lever_scores' => $levers,
            'bottleneck_lever' => $bottleneck,
            'benchmarks' => $this->playbook->pageAnatomy()['benchmarks'],
            'recommendations' => $this->recommend($bottleneck, $loadMs, $formFields),
            'confidence' => $loadMs > 0 && ($page['ad_headline'] ?? '') !== '' ? 'high' : 'medium',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function recommend(string $bottleneck, int $loadMs, int $formFields): array
    {
        return match ($bottleneck) {
            'headline_match' => ['Alinhar o H1 da página à headline do anúncio (alavanca nº1) — congruência pode ~dobrar o EPC.'],
            'form_fields' => ["Reduzir campos do form (atual {$formFields}) — menos campos = mais conversão."],
            'load_time' => ["Acelerar a página (atual {$loadMs}ms; alvo <2.500ms) — <2s converte +47%; usar builder rápido."],
            'trust_position' => ['Subir prova social (logos/estrelas/depoimentos) pra ANTES do scroll.'],
            'cta_clarity' => ['Usar 2-4 CTAs por contraste (topo/meio/fim), action-focused.'],
            'messaging_focus' => ['1 objetivo de conversão por página — remover saídas/concorrência de atenção.'],
            default => ['Página dentro dos benchmarks.'],
        };
    }
}
