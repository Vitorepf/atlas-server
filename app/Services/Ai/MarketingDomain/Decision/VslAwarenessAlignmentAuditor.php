<?php

namespace App\Services\Ai\MarketingDomain\Decision;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;

/**
 * VslAwarenessAlignmentAuditor — the awareness-router validator. VslPersuasionAuditService already
 * scores anatomy + Cialdini; this fills the remaining gap: does the VSL's actual OPENING LEAD TYPE
 * and MECHANISM-NAMING match what Schwartz awareness + sophistication prescribe? A unique-mechanism
 * market that doesn't NAME its mechanism is the classic "copy soa clichê e morre" failure. Deterministic.
 */
class VslAwarenessAlignmentAuditor
{
    /** Detect which Great-Leads lead type the opening actually uses, by markers. */
    private const LEAD_MARKERS = [
        'offer' => ['discount', 'price', 'order now', 'buy now', '% off', 'today only', 'desconto', 'oferta'],
        'promise' => ['you will', 'imagine', 'finally', 'transform', 'results in', 'você vai', 'finalmente'],
        'problem_solution' => ['struggle', 'tired of', 'problem', 'suffering', 'why you', 'luta', 'cansad', 'problema'],
        'big_secret' => ['secret', 'discovered', 'hidden', 'the truth about', 'they don', 'segredo', 'descobr', 'escondid'],
        'proclamation' => ['breaking', 'warning', 'shocking', 'never before', 'alerta', 'chocante'],
        'story' => ['my story', 'years ago', 'i was', 'it started', 'minha história', 'anos atrás', 'eu era'],
    ];

    public function __construct(private readonly MarketingPlaybook $playbook = new MarketingPlaybook) {}

    /**
     * @return array<string,mixed>
     */
    public function audit(AiMarketingVslAsset $asset): array
    {
        $declaredAwareness = strtolower(trim((string) $asset->awareness_level));
        $declaredSoph = strtolower(trim((string) $asset->sophistication_level));
        $route = $this->playbook->routeAwareness($declaredAwareness !== '' ? $declaredAwareness : null);

        $leadUsed = $this->detectLead($asset);
        $leadPrescribed = $route['lead_type'];

        $awarenessDeclared = $declaredAwareness !== '' && array_key_exists($declaredAwareness, $this->playbook->awarenessStates());
        $leadMatch = $leadUsed === $leadPrescribed
            ? 'yes'
            : ($leadUsed === null ? 'partial' : 'no');

        $sophPrescribed = $route['sophistication'];
        $sophMatch = $declaredSoph !== '' ? ($declaredSoph === $sophPrescribed ? 'yes' : 'no') : 'partial';

        // Sophistication mechanism/unique_mechanism REQUIRES a named mechanism.
        $needsMechanismName = in_array($sophPrescribed, ['mechanism', 'unique_mechanism'], true)
            || in_array($declaredSoph, ['mechanism', 'unique_mechanism'], true);
        $mechanismNamingOk = ! $needsMechanismName || trim((string) $asset->mechanism_name) !== '';

        $corrections = [];
        if (! $awarenessDeclared) {
            $corrections[] = 'Consciência não declarada/ inválida — classificar (Schwartz) pra travar o lead.';
        }
        if ($leadMatch === 'no') {
            $corrections[] = "Lead usado ({$leadUsed}) ≠ prescrito ({$leadPrescribed}) — reabrir a VSL com o lead certo.";
        }
        if ($sophMatch === 'no') {
            $corrections[] = "Sofisticação declarada ({$declaredSoph}) ≠ prescrita ({$sophPrescribed}).";
        }
        if (! $mechanismNamingOk) {
            $corrections[] = 'Mercado em mecanismo/único exige NOMEAR o mecanismo — mechanism_name vazio.';
        }

        return [
            'vsl_id' => $asset->id,
            'awareness_declared' => $awarenessDeclared ? $declaredAwareness : null,
            'awareness_match' => $awarenessDeclared ? 'yes' : 'no',
            'lead_type_used' => $leadUsed,
            'lead_type_prescribed' => $leadPrescribed,
            'lead_match' => $leadMatch,
            'sophistication_declared' => $declaredSoph !== '' ? $declaredSoph : null,
            'sophistication_prescribed' => $sophPrescribed,
            'sophistication_match' => $sophMatch,
            'mechanism_naming_required' => $needsMechanismName,
            'mechanism_naming_ok' => $mechanismNamingOk,
            'aligned' => $corrections === [],
            'correction_needed' => $corrections,
        ];
    }

    private function detectLead(AiMarketingVslAsset $asset): ?string
    {
        // Look at the opening: first slice of transcript + big_idea + the persuasion hook.
        $opening = strtolower(
            mb_substr((string) $asset->transcript, 0, 600).' '
            .(string) $asset->big_idea.' '
            .(is_array($asset->persuasion) ? (string) ($asset->persuasion['hook'] ?? '') : '')
        );
        if (trim($opening) === '') {
            return null;
        }

        $best = null;
        $bestHits = 0;
        foreach (self::LEAD_MARKERS as $lead => $markers) {
            $hits = 0;
            foreach ($markers as $m) {
                if (str_contains($opening, $m)) {
                    $hits++;
                }
            }
            if ($hits > $bestHits) {
                $bestHits = $hits;
                $best = $lead;
            }
        }

        return $best;
    }
}
