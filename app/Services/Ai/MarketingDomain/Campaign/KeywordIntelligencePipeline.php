<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use App\Models\AiMarketingVslAsset;

/**
 * KeywordIntelligencePipeline (L12 — orquestração) — costura os motores soltos em UM SISTEMA determinístico
 * que roda o fluxo inteiro sobre uma oferta: comprehension (L1) → DESCOBERTA do universo (L2) → grade de
 * intenção/qualidade/investimento/risco (L3/L4/L11) → negativas (L6) → seleção launch-ready + Decision-
 * Receipt por keyword (L12). É o que transforma "12 peças" em "INFRAESTRUTURA completa" capaz de escalar.
 *
 * Idempotente e provider-free: mesma oferta + mesma economia → MESMA saída (run_hash bit-a-bit), com
 * proveniência por keyword. Determinismo por construção — sem I/O, clock ou randomness no caminho.
 */
class KeywordIntelligencePipeline
{
    public function __construct(
        private readonly KeywordUniverseEnumerator $enumerator = new KeywordUniverseEnumerator,
        private readonly KeywordQualityIndex $qualityIndex = new KeywordQualityIndex,
        private readonly NegativeKeywordForge $negativeForge = new NegativeKeywordForge,
        private readonly QualifiedKeywordDossier $dossier = new QualifiedKeywordDossier,
    ) {}

    /**
     * @param  array<string,mixed>  $econ  payout, refund, margin, cvr (enables the investment gate)
     * @return array{offer_fingerprint:string,universe:array<string,mixed>,scored:array<int,array<string,mixed>>,launch_selection:array<string,mixed>,negatives:array<string,mixed>,knowledge_version:string,run_hash:string}
     */
    public function run(AiMarketingVslAsset $asset, array $econ = [], array $opts = []): array
    {
        $roots = $this->ownedRoots($asset);
        $product = $this->product($asset);

        // L2 — origina o universo (sem buracos, determinístico)
        $universe = $this->enumerator->enumerate($roots);

        // L3/L4/L11 — pontua + intenção + investimento + mente + risco-de-conta (elimina lixo antes)
        $quality = $this->qualityIndex->scoreEngineResult(
            $this->enumerator->asScorableTier($universe, $product),
            $asset,
            $econ,
        );

        // L6 — negativas (protege os owned roots: anti-campeã)
        $negatives = $this->negativeForge->forge(['protect' => $roots]);

        // L12 — seleção launch-ready + Decision-Receipt por keyword
        $launch = $this->dossier->select($quality['scored']);

        $fingerprint = $this->fingerprint($asset, $econ);

        return [
            'offer_fingerprint' => $fingerprint,
            'universe' => ['count' => $universe['count'], 'complete' => $universe['complete'], 'roots' => $universe['roots']],
            'scored' => $quality['scored'],
            'launch_selection' => $launch,
            'negatives' => $negatives,
            'knowledge_version' => KeywordKnowledgeCore::VERSION,
            'run_hash' => sha1($fingerprint.'|'.KeywordKnowledgeCore::VERSION),
        ];
    }

    /** @return array<int,string> owned roots = coined mechanism/trick (post-VSL-exposure). */
    private function ownedRoots(AiMarketingVslAsset $asset): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($s) => mb_strtolower(trim((string) $s)), [$asset->mechanism_name, $asset->trick]),
            fn ($s) => $s !== '',
        )));
    }

    private function product(AiMarketingVslAsset $asset): string
    {
        $offer = (array) ($asset->offer ?? []);

        return (string) ($offer['product_name'] ?? '');
    }

    /** Canonical, deterministic fingerprint of the offer + economics → idempotency key. */
    private function fingerprint(AiMarketingVslAsset $asset, array $econ): string
    {
        ksort($econ);
        $parts = [
            'mechanism:'.mb_strtolower((string) $asset->mechanism_name),
            'trick:'.mb_strtolower((string) $asset->trick),
            'product:'.mb_strtolower($this->product($asset)),
        ];
        foreach ($econ as $k => $v) {
            $parts[] = $k.'='.(is_scalar($v) ? (string) $v : '');
        }

        return sha1(implode('|', $parts));
    }
}
