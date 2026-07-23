<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrainContextPack;

use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Services\Ai\CognitiveMemory\AtlasCognitiveWorkingSetMemoryService;
use App\Services\Ai\Context\AtlasCanonicalContextRef;
use App\Services\Ai\Context\PackSufficiencyBlockBuilder;
use App\Services\Ai\Context\RetrievalAgendaComposer;
use App\Services\Ai\Context\TaskFacetExtractor;
use App\Services\Ai\SelfConstruction\Lineage\AtlasDecisionLineageLedger;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Throwable;

/**
 * GOD-DEBULK split of {@see \App\Services\Ai\AtlasOpenBrainContextPackService}.
 * Verbatim sessionworkingset family extracted from the AOBG context-pack
 * façade; behavior-preserving (private helpers -> Support; public API stays on the façade).
 */
final class SessionWorkingSetSection
{
    public function __construct(
        private readonly Support $support,
        private readonly PackSufficiencyBlockBuilder $packSufficiencyBlockBuilder,
        private readonly RetrievalAgendaComposer $retrievalAgendaComposer,
        private readonly TaskFacetExtractor $taskFacetExtractor,
    ) {}

    /** @param array<string,mixed> $opts */
    public function sessionWorkingSetScope(array $opts): ?string
    {
        $sessionId = trim((string) ($opts['session_id'] ?? data_get($opts, 'context.session_id', '')));

        return $sessionId !== '' ? 'session:'.$sessionId : null;
    }

    /**
     * @param  array<string,mixed>  $opts
     * @param  array<string,mixed>  $pack
     * @return array{schema_version:string,obra_id:?string,decision_id:?string,lineage_origin:string}
     */
    public function sessionWorkingSetLineage(array $opts, array $pack): array
    {
        $decisionId = $this->firstLineageId([
            $opts['decision_id'] ?? null,
            data_get($opts, 'context.decision_id'),
            data_get($opts, 'composed_arc.decision_id'),
            data_get($opts, 'composed_arc.source.decision_id'),
            data_get($opts, 'arc.decision_id'),
        ]);

        foreach ([
            'caller' => [$opts['obra_id'] ?? null, data_get($opts, 'context.obra_id')],
            'composed_obra_arc' => [data_get($opts, 'composed_arc.obra_id'), data_get($opts, 'arc.obra_id')],
        ] as $origin => $values) {
            $obraId = $this->firstLineageId($values);
            if ($obraId !== null) {
                return [
                    'schema_version' => 'atlas.aobg.session_working_set_lineage.v1',
                    'obra_id' => $obraId,
                    'decision_id' => $decisionId,
                    'lineage_origin' => $origin,
                ];
            }
        }

        $obraId = $this->obraIdFromDecisionLineage($decisionId);
        if ($obraId === null) {
            $obraId = $this->firstLineageId([data_get($pack, 'retomada.obra_id')]);

            return [
                'schema_version' => 'atlas.aobg.session_working_set_lineage.v1',
                'obra_id' => $obraId,
                'decision_id' => $decisionId,
                'lineage_origin' => $obraId !== null ? 'active_obra_state' : 'absent',
            ];
        }

        return [
            'schema_version' => 'atlas.aobg.session_working_set_lineage.v1',
            'obra_id' => $obraId,
            'decision_id' => $decisionId,
            'lineage_origin' => 'asi11_decision_lineage',
        ];
    }

    /**
     * @param  list<mixed>  $values
     */
    public function firstLineageId(array $values): ?string
    {
        foreach ($values as $value) {
            $id = $this->safeLineageId($value);
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    public function safeLineageId(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $id = trim((string) $value);
        if ($id === '') {
            return null;
        }

        $id = preg_replace('/[^A-Za-z0-9._:-]/', '-', $id) ?? '';
        $id = trim($id, '-');

        return $id !== '' ? mb_substr($id, 0, 120) : null;
    }

    public function obraIdFromDecisionLineage(?string $decisionId): ?string
    {
        if ($decisionId === null) {
            return null;
        }

        try {
            $closure = app(AtlasDecisionLineageLedger::class)->closure($decisionId);

            return $this->safeLineageId($closure['obra_id'] ?? null);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $lineage
     * @return array<string,mixed>
     */
    public function obraWorkingSetSection(string $scope, array $lineage): array
    {
        $obraId = $this->safeLineageId($lineage['obra_id'] ?? null);
        if ($obraId === null) {
            return [
                'schema_version' => 'atlas.aobg.obra_working_set.v1',
                'present' => false,
                'reason' => 'obra_id_absent',
                'items' => [],
            ];
        }

        $soak = [
            'status' => 'pending_window',
            'basis' => 'real_retomadas_only',
            'synthetic_retomada_used' => false,
        ];

        try {
            $state = (new AtlasCognitiveWorkingSetMemoryService(AtlasCognitiveWorkingSetMemoryService::sharedPath()))
                ->obraWorkingSet($obraId, $scope, AtlasCognitiveWorkingSetMemoryService::MODE_PERFORMANCE, 32);

            return [
                'schema_version' => 'atlas.aobg.obra_working_set.v1',
                'present' => (bool) ($state['present'] ?? false),
                'obra_id' => $obraId,
                'session_scope' => $scope,
                'items' => (array) ($state['items'] ?? []),
                'count' => (int) ($state['count'] ?? 0),
                'source_scope_count' => (int) ($state['source_scope_count'] ?? 0),
                'lineage' => array_filter($lineage, static fn ($value): bool => $value !== null && $value !== ''),
                'soak' => $soak,
            ];
        } catch (Throwable) {
            return [
                'schema_version' => 'atlas.aobg.obra_working_set.v1',
                'present' => false,
                'obra_id' => $obraId,
                'session_scope' => $scope,
                'items' => [],
                'count' => 0,
                'source_scope_count' => 0,
                'reason' => 'working_set_unavailable',
                'soak' => $soak,
            ];
        }
    }

    /**
     * MAXC-04 — attach the honest sufficiency block to the provider pack only
     * when deterministic facet retrieval is explicitly enabled.
     *
     * @param  array<string,mixed>  $pack
     * @return array<string,mixed>
     */
    public function sufficiencySection(string $task, array $pack): array
    {
        $facetExtraction = $this->taskFacetExtractor->extract($task);

        return $this->packSufficiencyBlockBuilder->build(
            (array) ($facetExtraction['facets'] ?? []),
            (array) ($pack['code_graph'] ?? []),
            (array) ($pack['memory'] ?? []),
            (array) ($pack['reality_graph_paths'] ?? []),
        ) + [
            'schema_version' => 'atlas.aobg.pack_sufficiency.v1',
        ];
    }

    /**
     * ESP-11 — attach the epistemic retrieval agenda only when claims or unknowns
     * are present. Tasks without claims keep the pack byte-identical to MAXC-04.
     *
     * @param  array<string,mixed>  $pack
     * @return array<string,mixed>
     */
    public function retrievalAgendaSection(string $task, array $pack): array
    {
        $facetExtraction = $this->taskFacetExtractor->extract($task);

        return $this->retrievalAgendaComposer->compose($task, [
            'facets' => (array) ($facetExtraction['facets'] ?? []),
            'facet_coverage' => (array) data_get($pack, 'sufficiency.facets', []),
            'wired_into_packfor' => true,
        ]);
    }

    /** @return list<string> */
    public function sessionWorkingSetDemoteRefs(?string $scope): array
    {
        if ($scope === null) {
            return [];
        }

        try {
            $state = (new AtlasCognitiveWorkingSetMemoryService(AtlasCognitiveWorkingSetMemoryService::sharedPath()))
                ->workingSet($scope, AtlasCognitiveWorkingSetMemoryService::MODE_PERFORMANCE);

            return AtlasCanonicalContextRef::uniqueStrings(array_map(
                static fn (array $item): string => (string) ($item['content_hash'] ?? ''),
                (array) ($state['items'] ?? []),
            ));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string,mixed>  $pack
     * @param  array<string,mixed>  $lineage
     */
    public function recordSessionWorkingSetDelivery(?string $scope, array $pack, array $lineage = []): void
    {
        if ($scope === null) {
            return;
        }

        try {
            $obraId = $this->safeLineageId($lineage['obra_id'] ?? data_get($pack, 'context_delivery_policy.session_working_set.lineage.obra_id'));
            $decisionId = $this->safeLineageId($lineage['decision_id'] ?? data_get($pack, 'context_delivery_policy.session_working_set.lineage.decision_id'));
            $workingSet = new AtlasCognitiveWorkingSetMemoryService(AtlasCognitiveWorkingSetMemoryService::sharedPath());
            foreach (AtlasCanonicalContextRef::deliveredFromPack($pack) as $ref) {
                $item = [
                    'content_hash' => $ref,
                    'content' => $ref,
                    'type' => 'context_ref',
                    'scope_ref' => $scope,
                    'origin' => 'context_pack_delivery',
                    'must_keep' => false,
                    'recorded_at' => now()->toJSON(),
                    'last_used_at' => now()->toJSON(),
                ];
                if ($obraId !== null) {
                    $item['obra_id'] = $obraId;
                }
                if ($decisionId !== null) {
                    $item['decision_id'] = $decisionId;
                }

                $workingSet->track($scope, $item);
            }
        } catch (Throwable) {
            // MAXE-06 is an optimization: context delivery must fail open.
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function compactedSection(): ?array
    {
        if (! DatabaseTableAvailability::has('atlas_long_horizon_compaction_receipts')) {
            return null;
        }

        try {
            $receipt = AtlasLongHorizonCompactionReceipt::query()->latest('created_at')->first();
        } catch (Throwable) {
            return null;
        }
        if (! $receipt instanceof AtlasLongHorizonCompactionReceipt) {
            return null;
        }

        $recoveryQueries = $this->support->stringList($receipt->recovery_queries);

        return [
            'schema_version' => 'atlas.aobg.compaction_context.v1',
            'present' => true,
            'scope_type' => (string) $receipt->scope_type,
            'scope_id' => (string) $receipt->scope_id,
            'receipt_hash' => (string) $receipt->receipt_hash,
            'must_keep_coverage' => AiValueNormalizer::finiteFloatOrNull($receipt->must_keep_coverage),
            'context_retention_score' => AiValueNormalizer::finiteFloatOrNull($receipt->context_retention_score),
            'loss_risk' => (string) $receipt->loss_risk,
            'unresolved_loss_count' => count((array) $receipt->unresolved_loss),
            'recovery_queries' => array_slice($recoveryQueries, 0, 8),
            'workflow' => $recoveryQueries === []
                ? 'no_recovery_query_available'
                : 'run_recovery_queries_before_claiming_compacted_context_absent',
        ];
    }
}
