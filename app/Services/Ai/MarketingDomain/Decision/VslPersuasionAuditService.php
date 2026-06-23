<?php

namespace App\Services\Ai\MarketingDomain\Decision;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;

/**
 * VslPersuasionAuditService — turns the playbook KNOWLEDGE into a DIAGNOSTIC on a REAL extracted VSL.
 *
 * The persuasion-auditor + vsl-architect skills, made executable: given an AiMarketingVslAsset
 * (already transcribed + structured), it scores the script against the canonical VSL anatomy and
 * Cialdini's 7 principles using deterministic presence signals (structured fields + transcript
 * markers), checks the awareness/lead alignment, and emits a prioritized fix list where each weak
 * block maps to an action in the operator's 9-action space. Same asset → same audit. No LLM.
 *
 * It is a STRUCTURAL completeness audit (does each block/principle leave a detectable signal?), not
 * a semantic verdict — its job is to surface what is MISSING, which is exactly where CVR leaks.
 */
class VslPersuasionAuditService
{
    /** Anatomy block → the action (9-space) that fixes it when weak/missing. */
    private const BLOCK_ACTION = [
        'hook' => MarketingPlaybook::ACTION_EDIT_HOOK,
        'agitate' => MarketingPlaybook::ACTION_REFRESH_VSL,
        'problem_mechanism' => MarketingPlaybook::ACTION_REFRESH_VSL,
        'solution_mechanism' => MarketingPlaybook::ACTION_REFRESH_VSL,
        'proof' => MarketingPlaybook::ACTION_STRENGTHEN_CLOSE,
        'offer' => MarketingPlaybook::ACTION_STRENGTHEN_CLOSE,
        'guarantee' => MarketingPlaybook::ACTION_STRENGTHEN_CLOSE,
        'scarcity' => MarketingPlaybook::ACTION_STRENGTHEN_CLOSE,
        'cta' => MarketingPlaybook::ACTION_STRENGTHEN_CLOSE,
        'ps_objections' => MarketingPlaybook::ACTION_STRENGTHEN_CLOSE,
    ];

    /** Transcript markers per block (lowercased substring hits). EN-first; some PT. */
    private const BLOCK_MARKERS = [
        'hook' => ['what if', 'imagine', 'discovered', 'shocking', 'warning', 'secret', 'e se', 'imagine se'],
        'agitate' => ['struggle', 'pain', 'frustrat', 'tired of', 'suffer', 'embarrass', 'ashamed', 'cansad', 'sofr'],
        'problem_mechanism' => ['the real reason', 'root cause', 'because of', "it's not your fault", 'verdadeira causa', 'culpa'],
        'solution_mechanism' => ['the only', 'breakthrough', 'discovery', 'method', 'mechanism', 'protocol', 'secret to', 'mecanismo', 'método'],
        'proof' => ['study', 'studies', 'research', 'clinical', 'proven', 'results', 'testimonial', 'doctor', 'scientist', 'university', 'estudo', 'comprov', 'pesquisa'],
        'offer' => ['order', 'package', 'bottle', 'bonus', 'discount', 'today only', 'add to cart', 'get yours', 'pedido', 'desconto'],
        'guarantee' => ['guarantee', 'money-back', 'money back', 'refund', 'risk-free', 'risk free', '60-day', '90-day', 'garantia', 'reembolso'],
        'scarcity' => ['limited', 'running out', 'while supplies', 'hurry', 'deadline', 'expires', 'last chance', 'limitad', 'acabando', 'última chance'],
        'cta' => ['click', 'button below', 'order now', 'get started', 'sign up', 'claim', 'clique', 'botão abaixo'],
        'ps_objections' => ['p.s.', 'what if you', 'you might be thinking', 'maybe you', 'talvez você'],
    ];

    /** Structured fields that evidence a block independent of transcript markers. */
    private const BLOCK_FIELDS = [
        'hook' => ['big_idea', 'power_phrases'],
        'agitate' => ['problem_mechanism'],
        'problem_mechanism' => ['problem_mechanism'],
        'solution_mechanism' => ['solution_mechanism', 'mechanism_name'],
        'proof' => ['claims'],
        'offer' => ['offer'],
        'guarantee' => [],
        'scarcity' => [],
        'cta' => ['cta'],
        'ps_objections' => ['objection_rebuttals'],
    ];

    /** Cialdini principle key → transcript markers. */
    private const CIALDINI_MARKERS = [
        'reciprocity' => ['free', 'gift', 'no cost', 'on the house', 'grátis', 'gratuito'],
        'commitment' => ['imagine', 'picture yourself', 'small step', 'just one', 'imagine'],
        'social_proof' => ['thousands', 'millions', 'customers', 'people', 'join', 'reviews', 'testimonial', 'milhares', 'pessoas'],
        'authority' => ['doctor', 'dr.', 'expert', 'scientist', 'research', 'certified', 'professor', 'médico', 'especialista'],
        'liking' => ['my story', 'just like you', 'i was', 'i felt', 'relatable', 'minha história', 'como você'],
        'scarcity' => ['limited', 'only', 'today', 'hurry', 'deadline', 'expires', 'limitad', 'apenas'],
        'unity' => ['we ', 'us ', 'together', 'our community', 'you and i', 'nós', 'juntos'],
    ];

    public function __construct(private readonly MarketingPlaybook $playbook = new MarketingPlaybook) {}

    /**
     * @return array<string,mixed>
     */
    public function audit(AiMarketingVslAsset $asset): array
    {
        $transcript = strtolower((string) $asset->transcript);
        $hasTranscript = trim($transcript) !== '';

        $blocks = $this->auditBlocks($asset, $transcript);
        $principles = $this->auditCialdini($asset, $transcript);
        $awareness = $this->auditAwareness($asset);

        $blocksPresent = count(array_filter($blocks, static fn (array $b): bool => $b['status'] === 'present'));
        $principlesPresent = count(array_filter($principles, static fn (array $p): bool => $p['present']));

        // Score: anatomy 60% + persuasion 30% + awareness alignment 10%.
        $score = (int) round(
            ($blocksPresent / count($blocks)) * 60
            + ($principlesPresent / count($principles)) * 30
            + ($awareness['aligned'] ? 10 : 0)
        );

        return [
            'vsl_id' => $asset->id,
            'campaign_ref' => $asset->campaign_ref,
            'niche' => $asset->niche,
            'has_transcript' => $hasTranscript,
            'score' => $hasTranscript ? $score : 0,
            'score_breakdown' => [
                'anatomy_blocks_present' => "{$blocksPresent}/".count($blocks),
                'cialdini_principles_present' => "{$principlesPresent}/".count($principles),
                'awareness_aligned' => $awareness['aligned'],
            ],
            'anatomy' => $blocks,
            'cialdini' => $principles,
            'awareness' => $awareness,
            'fixes' => $this->prioritizeFixes($blocks, $principles, $awareness),
            'note' => $hasTranscript
                ? 'Auditoria estrutural determinística (sinais de presença) — aponta o que FALTA, onde o CVR vaza.'
                : 'VSL sem transcript/extração — rode o ingestion/extract antes de auditar.',
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function auditBlocks(AiMarketingVslAsset $asset, string $transcript): array
    {
        $anatomyRules = [];
        foreach ($this->playbook->vslAnatomy() as $b) {
            $anatomyRules[$b['block']] = $b;
        }

        $out = [];
        foreach (self::BLOCK_MARKERS as $block => $markers) {
            $fields = self::BLOCK_FIELDS[$block] ?? [];
            $fieldHit = $this->anyFieldFilled($asset, $fields);
            $markerHits = $this->countMarkers($transcript, $markers);

            // Blocks with a structured-field channel need both signals to be "present"; blocks
            // evidenced only by transcript markers (guarantee, scarcity) reach "present" on ≥2 hits.
            $status = $fields !== []
                ? match (true) {
                    $fieldHit && $markerHits > 0 => 'present',
                    $fieldHit || $markerHits > 0 => 'weak',
                    default => 'missing',
                }
                : match (true) {
                    $markerHits >= 2 => 'present',
                    $markerHits === 1 => 'weak',
                    default => 'missing',
                };

            $out[$block] = [
                'status' => $status,
                'field_evidence' => $fieldHit,
                'marker_hits' => $markerHits,
                'rule' => $anatomyRules[$block]['rule'] ?? '',
                'weak_symptom' => $anatomyRules[$block]['weak_symptom'] ?? '',
                'fix_action' => self::BLOCK_ACTION[$block] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function auditCialdini(AiMarketingVslAsset $asset, string $transcript): array
    {
        $persuasion = strtolower(json_encode($asset->persuasion ?? []) ?: '');
        $names = [];
        foreach ($this->playbook->persuasionPrinciples() as $p) {
            $names[$p['key']] = $p['name'];
        }

        $out = [];
        foreach (self::CIALDINI_MARKERS as $key => $markers) {
            $hits = $this->countMarkers($transcript, $markers) + $this->countMarkers($persuasion, [$key]);
            $out[$key] = [
                'name' => $names[$key] ?? $key,
                'present' => $hits > 0,
                'hits' => $hits,
            ];
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function auditAwareness(AiMarketingVslAsset $asset): array
    {
        $declared = trim((string) $asset->awareness_level);
        $route = $this->playbook->routeAwareness($declared !== '' ? $declared : null);
        $aligned = $declared !== '' && strtolower($declared) === $route['awareness'];

        return [
            'declared' => $declared !== '' ? $declared : null,
            'recommended_lead' => $route['lead_type'],
            'recommended_sophistication' => $route['sophistication'],
            'aligned' => $aligned,
            'note' => $aligned
                ? 'Consciência declarada bate com o lead recomendado.'
                : 'Consciência não declarada ou ambígua — confirmar o lead certo (Schwartz × Great Leads) antes de escalar.',
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $blocks
     * @param  array<string,array<string,mixed>>  $principles
     * @param  array<string,mixed>  $awareness
     * @return array<int,array<string,mixed>>
     */
    private function prioritizeFixes(array $blocks, array $principles, array $awareness): array
    {
        $fixes = [];

        // Missing blocks first (hard leaks), then weak, in canonical funnel order.
        foreach (['missing', 'weak'] as $severity) {
            foreach ($blocks as $block => $b) {
                if ($b['status'] === $severity) {
                    $fixes[] = [
                        'priority' => $severity === 'missing' ? 'high' : 'medium',
                        'target' => "vsl_block:{$block}",
                        'issue' => "bloco '{$block}' {$severity} — ".$b['weak_symptom'],
                        'action' => $b['fix_action'],
                        'rule' => $b['rule'],
                    ];
                }
            }
        }

        $missingPrinciples = array_keys(array_filter($principles, static fn (array $p): bool => ! $p['present']));
        if ($missingPrinciples !== []) {
            $fixes[] = [
                'priority' => 'medium',
                'target' => 'persuasion:cialdini',
                'issue' => 'princípios ausentes: '.implode(', ', $missingPrinciples),
                'action' => MarketingPlaybook::ACTION_REFRESH_VSL,
                'rule' => 'inserir os gatilhos faltantes nos blocos correspondentes (prova/escassez/autoridade perto do CTA).',
            ];
        }

        if (! $awareness['aligned']) {
            $fixes[] = [
                'priority' => 'medium',
                'target' => 'awareness',
                'issue' => (string) $awareness['note'],
                'action' => MarketingPlaybook::ACTION_EDIT_VSL_HEADLINE,
                'rule' => "lead recomendado: {$awareness['recommended_lead']} (sofisticação {$awareness['recommended_sophistication']}).",
            ];
        }

        return $fixes;
    }

    /**
     * @param  array<int,string>  $fields
     */
    private function anyFieldFilled(AiMarketingVslAsset $asset, array $fields): bool
    {
        foreach ($fields as $f) {
            $v = $asset->getAttribute($f);
            if (is_array($v) ? $v !== [] : trim((string) $v) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,string>  $markers
     */
    private function countMarkers(string $haystack, array $markers): int
    {
        if ($haystack === '') {
            return 0;
        }
        $n = 0;
        foreach ($markers as $m) {
            if ($m !== '' && str_contains($haystack, $m)) {
                $n++;
            }
        }

        return $n;
    }
}
