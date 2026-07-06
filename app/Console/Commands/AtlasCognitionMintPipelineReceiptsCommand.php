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
        $capabilityById = [];
        foreach ($capabilities as $cap) {
            $capabilityById[(string) $cap['capability_id']] = $cap;
        }

        $minted = [];
        $green = 0;
        $processed = 0;
        $mintedKeys = [];

        // Obra #14 H1: MIRA os subsistemas `partial` PELO MESMO candidato que o resolver
        // aceita. Um subsistema fica partial quando o símbolo <Short>Test existe no índice
        // mas não há receipt verde keyed no owner doc — e o passe antigo só cunhava os
        // `test:` DECLARADOS do doc, que nesses casos não existem/não resolvem: o minter
        // nunca produzia o receipt que o resolver procura (35 greens → +0.05). Agora, para
        // cada partial, cunha (owner capability_id, <Short>Test | test refs declarados) —
        // exatamente os candidateTestRefs de resolvePipelineStatus.
        foreach ((array) data_get($card, 'subsystems', []) as $sub) {
            if ($processed >= $limit) {
                break;
            }
            if (($sub['pipeline_status'] ?? '') !== AtlasCognitionScoreCardService::STATUS_PARTIAL) {
                continue;
            }
            $fqn = (string) ($sub['service_class'] ?? '');
            $short = class_basename($fqn);
            foreach ($resolver->ownerCapabilityIdsForFqn($fqn) as $capabilityId) {
                $cap = $capabilityById[$capabilityId] ?? null;
                if ($cap === null || $processed >= $limit) {
                    continue;
                }
                $candidateRefs = [$short.'Test'];
                foreach ((array) ($cap['test_refs'] ?? []) as $testRef) {
                    if (($testRef['index_resolved'] ?? false) === true) {
                        $candidateRefs[] = (string) $testRef['ref'];
                    }
                }
                foreach (array_values(array_unique($candidateRefs)) as $ref) {
                    if (isset($mintedKeys[$capabilityId.'|'.$ref])) {
                        continue 2; // já cunhado neste passe (docs multi-subsistema)
                    }
                    $hashes = $truth->freshnessHashes($cap['evidence_refs'], $ref);
                    $receipt = $execution->runAndRecord(
                        $capabilityId,
                        $ref,
                        null,
                        $hashes['test_file_hash'] ?? null,
                        $hashes['impl_files_hash'] ?? null,
                    );
                    $ran = (int) ($receipt['tests_run'] ?? 0);
                    if ($ran === 0) {
                        continue; // candidato não roda nada (símbolo stale) — tenta o próximo
                    }
                    $passed = (bool) ($receipt['passed'] ?? false);
                    $green += $passed ? 1 : 0;
                    $minted[] = [
                        'subsystem' => (string) ($sub['acronym'] ?? ''),
                        'capability_id' => $capabilityId,
                        'test_ref' => $ref,
                        'green' => $passed,
                        'tests_run' => $ran,
                    ];
                    $mintedKeys[$capabilityId.'|'.$ref] = true;
                    $processed++;
                    break; // um receipt por (subsistema, owner) por passe (bounded)
                }
            }
        }

        // Sobra de orçamento: varredura das capabilities declaradas (comportamento
        // original) — mantém o passe útil quando não há mais partials a mirar.
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
                if (isset($mintedKeys[$capabilityId.'|'.$ref])) {
                    break; // já cunhado no passe direcionado
                }
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
                $mintedKeys[$capabilityId.'|'.$ref] = true;
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
