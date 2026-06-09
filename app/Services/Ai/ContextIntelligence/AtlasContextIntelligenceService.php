<?php

declare(strict_types=1);

namespace App\Services\Ai\ContextIntelligence;

use App\Services\Ai\Context\ContextPackSelfReflectionGate;
use App\Services\Ai\Context\ContextRetrievalRouter;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use Carbon\CarbonImmutable;

final class AtlasContextIntelligenceService
{
    public const SCHEMA_VERSION = 'atlas.context_intelligence.context_certification.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly ContextRetrievalRouter $retrievalRouter,
        private readonly ContextPackSelfReflectionGate $reflectionGate,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function assess(array $input): array
    {
        $now = ($input['now'] ?? null) instanceof CarbonImmutable ? $input['now'] : CarbonImmutable::now();
        $prompt = trim((string) ($input['prompt'] ?? ''));
        $payload = (array) ($input['payload'] ?? []);
        $task = AiTaskRequest::fromInput($prompt, [
            'source_type' => (string) ($input['source_type'] ?? 'acie_runtime'),
            'payload' => [
                'task_type' => (string) ($input['task_type'] ?? 'direct'),
                'atlas_workflow_mode' => (string) ($input['workflow_mode'] ?? 'auto'),
                'domain' => (string) ($input['domain'] ?? 'atlas'),
                'risk_level' => (string) ($input['risk_level'] ?? 'low'),
                'desired_mode' => (string) ($input['desired_mode'] ?? 'auto'),
            ],
        ], [
            'agent' => 'atlas_context_intelligence',
            'intent' => 'context_certification',
        ]);

        $retrieval = $this->retrievalRouter->plan($prompt, $task, $payload, [
            'include_memory_registry' => (bool) ($input['include_memory_registry'] ?? true),
        ]);

        $contextRefs = array_values((array) ($input['context_refs'] ?? []));
        $contextPack = [
            'context_refs' => $contextRefs,
            'conversation' => [
                'recent_turns' => array_values((array) ($input['recent_turns'] ?? [])),
            ],
            'memory' => [
                'recall' => array_values((array) ($input['memory_recall'] ?? [])),
                'registry' => array_values((array) ($input['memory_registry'] ?? [])),
                'verbatim' => array_values((array) ($input['memory_verbatim'] ?? [])),
                'semantic' => array_values((array) ($input['memory_semantic'] ?? [])),
            ],
            'open_questions' => array_values((array) ($input['open_questions'] ?? [])),
            'excluded_context' => array_values((array) ($input['excluded_context'] ?? [])),
            'risk_level' => (string) ($input['risk_level'] ?? 'low'),
        ];
        $reflection = $this->reflectionGate->assess($contextPack);

        $mustKeepItems = $this->mustKeepItems($input);
        $explicitEvidenceRefs = array_values(array_filter((array) ($input['evidence_refs'] ?? []), 'is_string'));
        $evidenceRefs = $this->evidenceRefs($retrieval, $contextRefs, $explicitEvidenceRefs);
        $status = $this->status($retrieval, $reflection, $mustKeepItems, $contextRefs, $explicitEvidenceRefs);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => $now->toJSON(),
            'prompt_hash' => MissionCanonicalHash::sha256(['prompt' => $prompt]),
            'mode' => $this->mode($input, $retrieval),
            'retrieval_report' => [
                'schema_version' => $retrieval['schema_version'] ?? ContextRetrievalRouter::SCHEMA_VERSION,
                'mode' => $retrieval['mode'] ?? 'unknown',
                'readiness' => $retrieval['readiness'] ?? [],
                'selected_sources' => array_values(array_map(
                    static fn (array $source): array => [
                        'type' => $source['type'] ?? 'unknown',
                        'required' => (bool) ($source['required'] ?? false),
                        'available' => (bool) ($source['available'] ?? false),
                        'status' => $source['status'] ?? 'unknown',
                    ],
                    (array) ($retrieval['selected_sources'] ?? []),
                )),
            ],
            'context_ref_count' => count($contextRefs),
            'must_keep' => [
                'count' => count($mustKeepItems),
                'items' => $mustKeepItems,
                'coverage_required' => count($mustKeepItems) > 0,
            ],
            'reflection' => [
                'status' => $reflection['status'] ?? 'unknown',
                'reasons' => $reflection['reasons'] ?? [],
                'recommended_action' => $reflection['recommended_action'] ?? null,
            ],
            'evidence_refs' => $evidenceRefs,
            'blockers' => $this->blockers($retrieval, $reflection, $mustKeepItems, $contextRefs, $explicitEvidenceRefs),
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'provider_calls_made' => false,
                'allows_external_superiority_claim' => false,
            ],
            'writes' => false,
        ];
        $payload['context_certification_hash'] = ContextIntelligencePayloadHash::forPayload($payload, 'context_certification_hash');

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function mustKeepItems(array $input): array
    {
        $items = array_values((array) ($input['must_keep_items'] ?? []));

        return array_values(array_map(static function (mixed $item, int $index): array {
            if (is_array($item)) {
                return [
                    'id' => (string) ($item['id'] ?? 'must_keep_'.$index),
                    'kind' => (string) ($item['kind'] ?? 'fact'),
                    'digest' => (string) ($item['digest'] ?? MissionCanonicalHash::sha256($item)),
                ];
            }

            return [
                'id' => 'must_keep_'.$index,
                'kind' => 'fact',
                'digest' => MissionCanonicalHash::sha256(['value' => (string) $item]),
            ];
        }, $items, array_keys($items)));
    }

    /**
     * @param  array<string,mixed>  $retrieval
     * @param  list<mixed>  $contextRefs
     * @param  list<string>  $explicitEvidenceRefs
     * @return list<string>
     */
    private function evidenceRefs(array $retrieval, array $contextRefs, array $explicitEvidenceRefs): array
    {
        $sourceRefs = array_values(array_filter(array_map(
            static fn (array $source): ?string => isset($source['owner_doc']) ? 'doc:'.(string) $source['owner_doc'] : null,
            (array) ($retrieval['selected_sources'] ?? []),
        )));
        $context = array_values(array_filter(array_map(
            static fn (mixed $ref): ?string => is_scalar($ref) ? 'context:'.(string) $ref : null,
            $contextRefs,
        )));

        return array_values(array_unique(array_merge($sourceRefs, $context, $explicitEvidenceRefs)));
    }

    /**
     * @param  array<string,mixed>  $retrieval
     * @param  array<string,mixed>  $reflection
     * @param  list<array<string,mixed>>  $mustKeepItems
     * @param  list<mixed>  $contextRefs
     * @param  list<string>  $explicitEvidenceRefs
     */
    private function status(array $retrieval, array $reflection, array $mustKeepItems, array $contextRefs, array $explicitEvidenceRefs): string
    {
        if ($this->blockers($retrieval, $reflection, $mustKeepItems, $contextRefs, $explicitEvidenceRefs) !== []) {
            return self::STATUS_BLOCKED;
        }

        if (($retrieval['readiness']['status'] ?? null) !== 'ready'
            || ($reflection['status'] ?? null) !== ContextPackSelfReflectionGate::STATUS_SUFFICIENT) {
            return self::STATUS_DEGRADED;
        }

        return self::STATUS_READY;
    }

    /**
     * @param  array<string,mixed>  $retrieval
     * @param  array<string,mixed>  $reflection
     * @param  list<array<string,mixed>>  $mustKeepItems
     * @param  list<mixed>  $contextRefs
     * @param  list<string>  $explicitEvidenceRefs
     * @return list<array<string,string>>
     */
    private function blockers(array $retrieval, array $reflection, array $mustKeepItems, array $contextRefs, array $explicitEvidenceRefs): array
    {
        $blockers = [];
        foreach ((array) ($retrieval['readiness']['required_unavailable_sources'] ?? []) as $source) {
            $blockers[] = [
                'id' => 'required_source_unavailable',
                'reason' => 'required source unavailable: '.(string) $source,
            ];
        }

        if (($reflection['status'] ?? null) === ContextPackSelfReflectionGate::STATUS_CONTRADICTORY) {
            $blockers[] = [
                'id' => 'context_contradictory',
                'reason' => 'context reflection detected contradiction',
            ];
        }

        if ($mustKeepItems !== [] && $contextRefs === [] && $explicitEvidenceRefs === []) {
            $blockers[] = [
                'id' => 'must_keep_without_evidence',
                'reason' => 'must_keep items require at least one evidence ref',
            ];
        }

        return $blockers;
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $retrieval
     */
    private function mode(array $input, array $retrieval): string
    {
        if (isset($input['mode']) && is_string($input['mode']) && $input['mode'] !== '') {
            return $input['mode'];
        }

        return (string) ($retrieval['mode'] ?? 'light');
    }

}
