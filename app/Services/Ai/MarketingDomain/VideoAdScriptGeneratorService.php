<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;

/**
 * VideoAdScriptGeneratorService — the video-ad-architect skill. Generates a concrete YouTube AD
 * script (NOT the whole VSL): hook(0-5s)→amplify→bridge→CTA, each block grounded in the extracted
 * VSL fields and carrying the retention rules that stop the skip. Deterministic.
 */
class VideoAdScriptGeneratorService
{
    public function __construct(private readonly MarketingPlaybook $playbook = new MarketingPlaybook) {}

    /**
     * @return array<string,mixed>
     */
    public function generateVideoAdScript(AiMarketingVslAsset $asset, ?string $angle = null): array
    {
        $structure = $this->playbook->videoAdStructure();
        $angle = trim((string) ($angle ?? $asset->big_idea)) ?: 'o ângulo principal da oferta';
        $pain = trim((string) $asset->problem_mechanism) ?: 'a dor central do avatar';
        $mechanism = trim((string) $asset->mechanism_name.' '.(string) $asset->solution_mechanism) ?: 'o mecanismo único da solução';
        $proof = implode('; ', array_slice(array_map('strval', (array) $asset->claims), 0, 2)) ?: 'prova de terceiros';
        $cta = is_array($asset->cta) ? (string) ($asset->cta['text'] ?? 'assista agora') : 'assista agora';

        return [
            'skill' => 'video-ad-architect',
            'angle' => $angle,
            'blocks' => [
                [
                    'block' => 'hook', 'seconds' => '0-5s',
                    'text' => "Pattern-interrupt sobre: {$angle} (SEM logo/marca nos 2s)",
                    'retention_rules_applied' => ['no_brand_first_2s', 'pattern_interrupt_4s', 'lead_with_pain_or_benefit'],
                ],
                [
                    'block' => 'amplify', 'seconds' => '5-20s',
                    'text' => "Aprofundar a dor/desejo: {$pain}",
                    'retention_rules_applied' => ['double_hook', 'visual_change_few_seconds'],
                ],
                [
                    'block' => 'bridge', 'seconds' => '20-45s',
                    'text' => "Entrar o produto via o mecanismo: {$mechanism}. Prova: {$proof}",
                    'retention_rules_applied' => ['unique_mechanism_named', 'third_party_proof'],
                ],
                [
                    'block' => 'cta', 'seconds' => '10-15s final',
                    'text' => "Um único próximo passo: {$cta}",
                    'retention_rules_applied' => ['single_cta'],
                ],
            ],
            'retention_rules' => $structure['retention_rules'],
            'ugc_note' => 'UGC-style (1ª pessoa, handheld) bate polido em até 3× de completion.',
        ];
    }
}
