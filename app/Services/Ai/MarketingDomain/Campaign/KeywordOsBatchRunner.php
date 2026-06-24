<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use App\Models\AiMarketingVslAsset;

/**
 * KeywordOsBatchRunner (L12 — escala/ledger) — roda o KeywordIntelligencePipeline sobre N ofertas como
 * um lote DETERMINÍSTICO e IDEMPOTENTE, produzindo um LEDGER que prova cada veredito (offer_fingerprint →
 * run_hash). É o que o operador chamou de "escalar MILHÕES = engenharia de SISTEMA": o fluxo inteiro
 * repetível sobre milhares de ofertas, com auditoria por execução.
 *
 * Determinismo por construção: mesmas ofertas (em qualquer ordem) → MESMO batch_hash + MESMO ledger
 * (ordenado por fingerprint). Provider-free, sem I/O/clock/random no caminho. A persistência do ledger
 * (DB) é follow-up; o núcleo determinístico vive aqui.
 */
class KeywordOsBatchRunner
{
    public function __construct(
        private readonly KeywordIntelligencePipeline $pipeline = new KeywordIntelligencePipeline,
    ) {}

    /**
     * @param  array<int,array{asset:AiMarketingVslAsset,econ?:array<string,mixed>}>  $offers
     * @return array{count:int,ledger:array<int,array<string,mixed>>,batch_hash:string,knowledge_version:string}
     */
    public function runBatch(array $offers): array
    {
        $ledger = [];
        foreach ($offers as $offer) {
            $asset = $offer['asset'] ?? null;
            if (! $asset instanceof AiMarketingVslAsset) {
                continue;
            }
            $run = $this->pipeline->run($asset, (array) ($offer['econ'] ?? []));
            $launch = (array) ($run['launch_selection'] ?? []);
            $ledger[] = [
                'offer_fingerprint' => $run['offer_fingerprint'],
                'run_hash' => $run['run_hash'],
                'universe_count' => $run['universe']['count'] ?? 0,
                'scored_count' => count((array) ($run['scored'] ?? [])),
                'recommended_count' => count((array) ($launch['recommended'] ?? [])),
                'high_risk_count' => count((array) ($launch['high_risk'] ?? [])),
                'rejected_count' => count((array) ($launch['rejected'] ?? [])),
                'enough' => (bool) ($launch['enough'] ?? false),
            ];
        }

        // deterministic order: sort the ledger by fingerprint so order-of-input never changes the batch.
        usort($ledger, static fn ($a, $b): int => strcmp((string) $a['offer_fingerprint'], (string) $b['offer_fingerprint']));

        $batchSeed = implode(';', array_map(
            static fn ($row): string => $row['offer_fingerprint'].':'.$row['run_hash'],
            $ledger,
        ));

        return [
            'count' => count($ledger),
            'ledger' => $ledger,
            'batch_hash' => sha1($batchSeed.'|'.KeywordKnowledgeCore::VERSION),
            'knowledge_version' => KeywordKnowledgeCore::VERSION,
        ];
    }
}
