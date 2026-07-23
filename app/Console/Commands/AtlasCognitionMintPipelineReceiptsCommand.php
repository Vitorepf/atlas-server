<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasEngineeringCodeSymbol;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationTruthService;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasCapabilityTestExecutionService;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;

/**
 * L2-9 — sobe a dimensão MAIS FRACA medida do scorecard ACOS (pipeline: 0/73 green-run
 * receipts ⇒ 5.22/10) por EVIDÊNCIA REAL: roda os testes declarados das capabilities e
 * cunha green-run receipts (reusando AtlasCapabilityTestExecutionService::runAndRecord — real
 * PHPUnit, FQN-anchored, freshness-bound). Mede o overall do scorecard ANTES e DEPOIS,
 * provando o lift. Bounded por --limit (cada teste é segundos) para uso incremental
 * durante o soak; NUNCA fabrica receipt (verde reprovado = receipt não-verde honesto).
 */
class AtlasCognitionMintPipelineReceiptsCommand extends Command
{
    protected $signature = 'atlas:cognition:mint-pipeline-receipts
        {--limit=5 : Máximo de capabilities a verificar neste passe (cada teste custa segundos)}
        {--dry-run : Lista alvos sem executar testes nem persistir receipts}
        {--json : Saída JSON canônica}';

    protected $description = 'Cunha green-run receipts reais para subir a dimensão pipeline do scorecard ACOS (mede o lift antes/depois).';

    public function handle(
        AtlasImplementationTruthService $truth,
        AtlasCapabilityTestExecutionService $execution,
        AtlasCognitionScoreCardService $scorecard,
        \App\Services\Ai\Cognition\AtlasCognitionEvidenceResolver $resolver,
    ): int {
        $limit = max(1, min(73, (int) $this->option('limit')));
        $dryRun = (bool) $this->option('dry-run');
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
        $bornStale = [];
        $dryRunTargets = [];
        $bindingRejections = [];

        $recordMint = function (
            array $meta,
            string $capabilityId,
            string $ref,
            array $evidenceRefs,
            array $receipt,
        ) use (
            &$minted,
            &$green,
            &$processed,
            &$mintedKeys,
            &$bornStale,
            $execution,
        ): void {
            $mintedKeys[$capabilityId.'|'.$ref] = true;
            $processed++;

            $ran = (int) ($receipt['tests_run'] ?? 0);
            if ($ran === 0) {
                return;
            }

            $passed = (bool) ($receipt['passed'] ?? false);
            $seal = null;
            if ($passed) {
                $seal = $execution->verifyGreenMintSeal($capabilityId, $ref, $evidenceRefs);
                if (($seal['born_stale'] ?? false) === true) {
                    $passed = false;
                    $bornStale[] = [
                        'capability_id' => $capabilityId,
                        'test_ref' => $ref,
                        'seal' => $seal,
                    ];
                }
            }

            $green += $passed ? 1 : 0;
            $minted[] = array_merge($meta, [
                'capability_id' => $capabilityId,
                'test_ref' => $ref,
                'green' => $passed,
                'born_stale' => ($seal['born_stale'] ?? false) === true,
                'tests_run' => $ran,
                'seal' => $seal,
            ]);
        };

        foreach ((array) data_get($card, 'v4.modules', []) as $module) {
            if (($dryRun ? count($dryRunTargets) : $processed) >= $limit) {
                break;
            }
            if (($module['pipeline_status'] ?? '') !== AtlasCognitionScoreCardService::STATUS_PARTIAL
                || (int) ($module['supplemental_count'] ?? 0) < 1) {
                continue;
            }
            foreach ((array) ($module['service_classes'] ?? []) as $serviceClass) {
                if (($dryRun ? count($dryRunTargets) : $processed) >= $limit) {
                    break;
                }
                $serviceClass = trim((string) $serviceClass);
                if ($serviceClass === '') {
                    continue;
                }
                $short = class_basename($serviceClass);
                foreach ($resolver->ownerCapabilityIdsForFqn($serviceClass) as $capabilityId) {
                    if (($dryRun ? count($dryRunTargets) : $processed) >= $limit) {
                        break;
                    }
                    $cap = $capabilityById[$capabilityId] ?? $this->syntheticCapFromDocs($truth, $capabilityId);
                    if ($cap === null) {
                        continue;
                    }
                    foreach ($this->candidateRefsWithMeta($cap, $short) as $candidate) {
                        $ref = $candidate['ref'];
                        if (isset($mintedKeys[$capabilityId.'|'.$ref])) {
                            continue;
                        }
                        $binding = $this->testRefBinding($ref, $serviceClass, $candidate['meta']);
                        if (! $binding['bound']) {
                            $bindingRejections[] = [
                                'module' => (string) ($module['acronym'] ?? ''),
                                'service_class' => $serviceClass,
                                'capability_id' => $capabilityId,
                                'test_ref' => $ref,
                                'reason' => 'test_ref_not_bound_to_service_class',
                                'binding' => $binding,
                            ];
                            $mintedKeys[$capabilityId.'|'.$ref] = true;

                            continue;
                        }

                        if ($dryRun) {
                            $mintedKeys[$capabilityId.'|'.$ref] = true;
                            $dryRunTargets[] = [
                                'module' => (string) ($module['acronym'] ?? ''),
                                'service_class' => $serviceClass,
                                'capability_id' => $capabilityId,
                                'owner_doc' => (string) ($cap['owner_doc'] ?? ''),
                                'test_ref' => $ref,
                                'binding' => $binding,
                            ];

                            break;
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
                            continue;
                        }
                        $passedBeforeLimit = (bool) ($receipt['passed'] ?? false);
                        $recordMint(
                            [
                                'module' => (string) ($module['acronym'] ?? ''),
                                'service_class' => $serviceClass,
                                'binding' => $binding,
                            ],
                            $capabilityId,
                            $ref,
                            $cap['evidence_refs'],
                            $receipt,
                        );
                        if ($passedBeforeLimit || $processed >= $limit) {
                            break;
                        }
                    }
                }
            }
        }

        // Obra #14 H1: MIRA os subsistemas `partial` PELO MESMO candidato que o resolver
        // aceita. Um subsistema fica partial quando o símbolo <Short>Test existe no índice
        // mas não há receipt verde keyed no owner doc — e o passe antigo só cunhava os
        // `test:` DECLARADOS do doc, que nesses casos não existem/não resolvem: o minter
        // nunca produzia o receipt que o resolver procura (35 greens → +0.05). Agora, para
        // cada partial, cunha (owner capability_id, <Short>Test | test refs declarados) —
        // exatamente os candidateTestRefs de resolvePipelineStatus.
        foreach ($dryRun ? [] : (array) data_get($card, 'subsystems', []) as $sub) {
            if ($processed >= $limit) {
                break;
            }
            if (($sub['pipeline_status'] ?? '') !== AtlasCognitionScoreCardService::STATUS_PARTIAL) {
                continue;
            }
            $fqn = (string) ($sub['service_class'] ?? '');
            $short = class_basename($fqn);
            foreach ($resolver->ownerCapabilityIdsForFqn($fqn) as $capabilityId) {
                if ($processed >= $limit) {
                    break;
                }
                // Owner docs often declare symbol/command without a `test:` evidence_ref —
                // capabilityTestRefs() then omits them. Still mint via <Short>Test when the
                // owner doc exists in docsWithEvidence (symbol-bound ownership).
                $cap = $capabilityById[$capabilityId] ?? $this->syntheticCapFromDocs($truth, $capabilityId);
                if ($cap === null) {
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
                        continue; // já tentado neste passe — tenta o próximo candidato
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
                    $passedBeforeLimit = (bool) ($receipt['passed'] ?? false);
                    $recordMint(
                        ['subsystem' => (string) ($sub['acronym'] ?? '')],
                        $capabilityId,
                        $ref,
                        $cap['evidence_refs'],
                        $receipt,
                    );
                    // Verde fecha o (subsistema, owner). Vermelho: tenta o próximo
                    // candidato declarado (ex.: GuardTest quebrado → HardeningTest vivo).
                    if ($passedBeforeLimit || $processed >= $limit) {
                        break;
                    }
                }
            }
        }

        // Sobra de orçamento: varredura das capabilities declaradas (comportamento
        // original) — mantém o passe útil quando não há mais partials a mirar.
        foreach ($dryRun ? [] : $capabilities as $cap) {
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
                $recordMint([], $capabilityId, $ref, $cap['evidence_refs'], $receipt);
                break; // um teste por capability por passe (bounded)
            }
        }

        $after = $dryRun ? $before : (float) data_get($scorecard->build(), 'score.dimensions.pipeline.score_out_of_10', 0.0);

        $result = [
            'schema_version' => 'atlas.cognition.mint_pipeline_receipts.v1',
            'dry_run' => $dryRun,
            'capabilities_processed' => $processed,
            'green_receipts_minted' => $green,
            'born_stale_count' => count($bornStale),
            'born_stale' => $bornStale,
            'dry_run_target_count' => count($dryRunTargets),
            'dry_run_targets' => $dryRunTargets,
            'binding_rejection_count' => count($bindingRejections),
            'binding_rejections' => $bindingRejections,
            'pipeline_score_before' => round($before, 2),
            'pipeline_score_after' => round($after, 2),
            'pipeline_lift' => round($after - $before, 2),
            'minted' => $minted,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $bornStale === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->components->twoColumnDetail('Capabilities verificadas', (string) $processed);
        $this->components->twoColumnDetail('Green receipts cunhados', (string) $green);
        if ($bornStale !== []) {
            $this->components->twoColumnDetail('Born-stale selo', (string) count($bornStale));
        }
        $this->components->twoColumnDetail('Pipeline score', $result['pipeline_score_before'].' → '.$result['pipeline_score_after'].' (Δ '.($result['pipeline_lift'] >= 0 ? '+' : '').$result['pipeline_lift'].')');

        return $bornStale === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<array{ref:string,meta:array<string,mixed>}>
     */
    private function candidateRefsWithMeta(array $cap, string $short): array
    {
        $candidates = [];
        foreach ((array) ($cap['test_refs'] ?? []) as $testRef) {
            if (($testRef['index_resolved'] ?? false) === true) {
                $candidates[] = [
                    'ref' => (string) $testRef['ref'],
                    'meta' => (array) $testRef,
                ];
            }
        }
        $candidates[] = ['ref' => $short.'Test', 'meta' => []];

        $seen = [];
        $out = [];
        foreach ($candidates as $candidate) {
            $ref = trim((string) $candidate['ref']);
            if ($ref === '' || isset($seen[$ref])) {
                continue;
            }
            $seen[$ref] = true;
            $out[] = ['ref' => $ref, 'meta' => $candidate['meta']];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $testRefMeta
     * @return array{bound:bool,basis:string,path:?string}
     */
    private function testRefBinding(string $testRef, string $serviceClass, array $testRefMeta = []): array
    {
        $path = $this->testRefPath($testRef, $testRefMeta);
        if ($path === null || ! is_file(base_path($path))) {
            return ['bound' => false, 'basis' => 'test_file_not_found', 'path' => $path];
        }

        $content = (string) file_get_contents(base_path($path));
        $short = class_basename($serviceClass);
        $fqnClassPattern = preg_quote(ltrim($serviceClass, '\\'), '/');
        $shortPattern = preg_quote($short, '/');

        $bound = str_contains($content, ltrim($serviceClass, '\\').'::class')
            || str_contains($content, '\\'.ltrim($serviceClass, '\\').'::class')
            || preg_match('/\b'.$shortPattern.'\s*::class\b/', $content) === 1
            || preg_match('/\bnew\s+'.$shortPattern.'\b/', $content) === 1
            || preg_match('/\b'.$shortPattern.'::[A-Za-z_]/', $content) === 1
            || preg_match('/'.$fqnClassPattern.'/', $content) === 1;

        return [
            'bound' => $bound,
            'basis' => $bound ? 'test_file_references_service_class' : 'test_file_missing_service_class_reference',
            'path' => $path,
        ];
    }

    /**
     * @param  array<string,mixed>  $testRefMeta
     */
    private function testRefPath(string $testRef, array $testRefMeta): ?string
    {
        $path = trim((string) ($testRefMeta['file_path'] ?? ''));
        if ($path !== '') {
            return $path;
        }

        if (! DatabaseTableAvailability::has('atlas_engineering_code_symbols')) {
            return null;
        }

        return AtlasEngineeringCodeSymbol::query()
            ->where('symbol_name', 'like', '%'.$testRef.'%')
            ->orderBy('file_path')
            ->value('file_path');
    }

    /**
     * When an owner doc has symbol/command evidence but no `test:` refs, capabilityTestRefs()
     * omits it. Rebuild a minimal cap from docsWithEvidence so mint can still run <Short>Test.
     *
     * @return array{capability_id:string, owner_doc:string, evidence_refs:array<int,array{kind:string,ref:string}>, test_refs:array}|null
     */
    private function syntheticCapFromDocs(AtlasImplementationTruthService $truth, string $capabilityId): ?array
    {
        foreach ($truth->docsWithEvidence() as $doc) {
            if ((string) ($doc['id'] ?? '') !== $capabilityId) {
                continue;
            }

            return [
                'capability_id' => $capabilityId,
                'owner_doc' => (string) ($doc['path'] ?? ''),
                'evidence_refs' => (array) ($doc['evidence_refs'] ?? []),
                'test_refs' => [],
            ];
        }

        return null;
    }
}
