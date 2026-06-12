<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use App\Services\Ai\Aaeos\AtlasAaeosTestExecutionService;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use Illuminate\Console\Command;

/**
 * L2-9 — sobe a dimensão MAIS FRACA medida do scorecard ACOS (pipeline: 0/73 green-run
 * receipts ⇒ 5.22/10) por EVIDÊNCIA REAL: roda os testes declarados das capabilities e
 * cunha green-run receipts (reusando AtlasAaeosTestExecutionService::runAndRecord — real
 * PHPUnit, FQN-anchored, freshness-bound). Mede o overall do scorecard ANTES e DEPOIS,
 * provando o lift. Bounded por --limit (cada teste é segundos) para uso incremental
 * durante o soak; NUNCA fabrica receipt (verde reprovado = receipt não-verde honesto).
 */
class AtlasCognitionMintPipelineReceiptsCommand extends Command
{
    protected $signature = 'atlas:cognition:mint-pipeline-receipts
        {--limit=5 : Máximo de capabilities a verificar neste passe (cada teste custa segundos)}
        {--json : Saída JSON canônica}';

    protected $description = 'Cunha green-run receipts reais para subir a dimensão pipeline do scorecard ACOS (mede o lift antes/depois).';

    public function handle(
        AtlasAaeosImplementationTruthService $truth,
        AtlasAaeosTestExecutionService $execution,
        AtlasCognitionScoreCardService $scorecard,
        \App\Services\Ai\Cognition\AtlasCognitionEvidenceResolver $resolver,
    ): int {
        $limit = max(1, min(73, (int) $this->option('limit')));
        $card = $scorecard->build();
        $before = (float) data_get($card, 'score.dimensions.pipeline.score_out_of_10', 0.0);

        $capabilities = $truth->capabilityTestRefs();

        // L3-11: MIRA os subsistemas `partial` — cada receipt verde de um capability que
        // OWNa um subsistema partial o flipa partial→ready (conversão ~100%). Sem isto o
        // mint varria todas as capabilities e a maioria não era owner de subsistema partial
        // (20 mints → +0.05). Constrói o conjunto de capability_ids que governam partials e
        // ordena-os primeiro; fail-open: sem partials mapeados, mantém a ordem original.
        $partialCapabilityIds = [];
        foreach ((array) data_get($card, 'subsystems', []) as $sub) {
            if (($sub['pipeline_status'] ?? '') !== AtlasCognitionScoreCardService::STATUS_PARTIAL) {
                continue;
            }
            foreach ($resolver->ownerCapabilityIdsForFqn((string) ($sub['service_class'] ?? '')) as $cid) {
                $partialCapabilityIds[$cid] = true;
            }
        }
        if ($partialCapabilityIds !== []) {
            usort($capabilities, static function (array $a, array $b) use ($partialCapabilityIds): int {
                $aw = isset($partialCapabilityIds[(string) ($a['capability_id'] ?? '')]) ? 0 : 1;
                $bw = isset($partialCapabilityIds[(string) ($b['capability_id'] ?? '')]) ? 0 : 1;

                return $aw <=> $bw;
            });
        }
        $minted = [];
        $green = 0;
        $processed = 0;

        foreach ($capabilities as $cap) {
            if ($processed >= $limit) {
                break;
            }
            $capabilityId = (string) $cap['capability_id'];
            foreach ($cap['test_refs'] as $testRef) {
                if (($testRef['index_resolved'] ?? false) !== true) {
                    continue; // ref que não resolve no índice nunca vira receipt verde — pula
                }
                $ref = (string) $testRef['ref'];
                $hashes = $truth->freshnessHashes($cap['evidence_refs'], $ref);
                $receipt = $execution->runAndRecord(
                    $capabilityId,
                    $ref,
                    null,
                    $hashes['test_file_hash'] ?? null,
                    $hashes['impl_files_hash'] ?? null,
                );
                $passed = (bool) ($receipt['passed'] ?? false);
                $green += $passed ? 1 : 0;
                $minted[] = [
                    'capability_id' => $capabilityId,
                    'test_ref' => $ref,
                    'green' => $passed,
                    'tests_run' => $receipt['tests_run'] ?? 0,
                ];
                $processed++;
                break; // um teste por capability por passe (bounded)
            }
        }

        $after = (float) data_get($scorecard->build(), 'score.dimensions.pipeline.score_out_of_10', 0.0);

        $result = [
            'schema_version' => 'atlas.cognition.mint_pipeline_receipts.v1',
            'capabilities_processed' => $processed,
            'green_receipts_minted' => $green,
            'pipeline_score_before' => round($before, 2),
            'pipeline_score_after' => round($after, 2),
            'pipeline_lift' => round($after - $before, 2),
            'minted' => $minted,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Capabilities verificadas', (string) $processed);
        $this->components->twoColumnDetail('Green receipts cunhados', (string) $green);
        $this->components->twoColumnDetail('Pipeline score', $result['pipeline_score_before'].' → '.$result['pipeline_score_after'].' (Δ '.($result['pipeline_lift'] >= 0 ? '+' : '').$result['pipeline_lift'].')');

        return self::SUCCESS;
    }
}
