<?php

declare(strict_types=1);

namespace App\Services\Ai\ContextIntelligence;

use App\Services\Ai\ConversationOps\AtlasConversationOperationsService;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Throwable;

final class AtlasContextOperationsRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.context_intelligence.operations_runtime.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_WATCH = 'watch';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly AtlasContextIntelligenceService $contextIntelligence,
        private readonly AtlasConversationOperationsService $conversationOps,
        private readonly AtlasVerifiedCompactionService $verifiedCompaction,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input): array
    {
        $now = ($input['now'] ?? null) instanceof CarbonImmutable ? $input['now'] : CarbonImmutable::now();
        $prompt = trim((string) ($input['prompt'] ?? ''));
        $flowId = AiValueNormalizer::trimmedScalarStringOrNull($input['flow_id'] ?? null) ?? 'atlas_conversation';
        $domain = AiValueNormalizer::trimmedScalarStringOrNull($input['domain'] ?? null) ?? 'atlas';
        $contextRefs = array_values(array_filter((array) ($input['context_refs'] ?? []), 'is_string'));
        $evidenceRefs = array_values(array_filter((array) ($input['evidence_refs'] ?? []), 'is_string'));
        $mustKeepItems = $this->mustKeepItems($input, $flowId);
        $handoffTarget = is_array($input['handoff_target'] ?? null) ? (array) $input['handoff_target'] : null;
        $turns = array_values((array) ($input['turns'] ?? []));
        if ($turns === [] && $prompt !== '') {
            $turns[] = ['role' => 'user', 'content' => $prompt];
        }

        $context = $this->safeContextAssessment($input, $prompt, $domain, $contextRefs, $evidenceRefs, $mustKeepItems);
        $conversation = $this->safeConversationHealth($domain, $flowId, $turns, $contextRefs);
        $janitor = $this->conversationOps->contextJanitorReceipt(['turns' => $turns]);
        $curator = $this->conversationOps->memoryCuratorReceipt([
            'decisions' => (array) data_get($conversation, 'decision_ledger.decisions', []),
            'blockers' => (array) data_get($conversation, 'decision_ledger.blockers', []),
        ]);
        $verified = $this->verifiedCompactionReport($input, $flowId, $contextRefs, $evidenceRefs, $mustKeepItems);
        $critic = $this->conversationOps->compressionCriticReport([
            'must_keep_coverage' => data_get($verified, 'compaction_receipt.must_keep_coverage', 1.0),
            'missing_must_keep_ids' => data_get($verified, 'semantic_diff.missing_must_keep_ids', []),
        ]);
        $handoff = $handoffTarget === null ? null : $this->conversationOps->handoffPacket([
            'role' => $handoffTarget['kind'] === 'atlas_forge' ? 'forge_intake' : 'dev_runtime',
            'task' => 'execute '.$domain.' flow '.$flowId.' with ACIE/ACOL context contract',
            'allowed_scope' => array_values(array_filter([
                'domain:'.$domain,
                'flow:'.$flowId,
                AiValueNormalizer::trimmedScalarStringOrNull($handoffTarget['kind'] ?? null) ? 'handoff:'.$handoffTarget['kind'] : null,
            ])),
            'evidence_refs' => $evidenceRefs,
            'context_budget' => $handoffTarget['kind'] === 'atlas_forge' ? 'large' : 'medium',
            'ttl_minutes' => $handoffTarget['kind'] === 'atlas_forge' ? 120 : 45,
        ]);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $this->status($context, $conversation, $verified, $critic),
            'generated_at' => $now->toJSON(),
            'flow_binding' => [
                'domain' => $domain,
                'flow_id' => $flowId,
                'flow_profile' => AiValueNormalizer::trimmedScalarStringOrNull($input['flow_profile'] ?? null),
                'runtime_mode' => AiValueNormalizer::trimmedScalarStringOrNull($input['runtime_mode'] ?? null),
                'required_gates' => array_values((array) ($input['required_gates'] ?? [])),
            ],
            'context_intelligence' => $context,
            'conversation_ops' => $conversation,
            'context_janitor' => $janitor,
            'memory_curator' => $curator,
            'verified_compaction' => $verified,
            'compression_critic' => $critic,
            'handoff_packet' => $handoff,
            'integration_policy' => [
                'context_required_for_flow' => $this->contextRequired($input, $handoffTarget),
                'verified_compaction_required' => $this->requiresVerifiedCompaction($input, $handoffTarget, $contextRefs),
                'handoff_required' => $handoffTarget !== null,
                'subagent_return_audit_required' => $handoffTarget !== null,
                'memory_promotion_requires_review' => true,
            ],
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'provider_calls_made' => false,
                'allows_external_superiority_claim' => false,
            ],
            'writes' => (bool) data_get($verified, 'writes', false),
        ];
        $payload['operations_runtime_hash'] = ContextIntelligencePayloadHash::forPayload($payload, 'operations_runtime_hash');

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  list<string>  $contextRefs
     * @param  list<string>  $evidenceRefs
     * @param  list<array<string,mixed>>  $mustKeepItems
     * @return array<string,mixed>
     */
    private function safeContextAssessment(array $input, string $prompt, string $domain, array $contextRefs, array $evidenceRefs, array $mustKeepItems): array
    {
        try {
            return $this->contextIntelligence->assess([
                'prompt' => $prompt,
                'task_type' => (string) ($input['flow_profile'] ?? $input['flow_id'] ?? 'direct'),
                'domain' => $domain,
                'risk_level' => $this->contextRequired($input, is_array($input['handoff_target'] ?? null) ? (array) $input['handoff_target'] : null) ? 'medium' : 'low',
                'context_refs' => $contextRefs,
                'evidence_refs' => $evidenceRefs,
                'must_keep_items' => $mustKeepItems,
                'recent_turns' => array_values((array) ($input['turns'] ?? [])),
            ]);
        } catch (Throwable $exception) {
            return [
                'schema_version' => AtlasContextIntelligenceService::SCHEMA_VERSION,
                'status' => AtlasContextIntelligenceService::STATUS_DEGRADED,
                'error' => 'context_intelligence_threw',
                'exception_class' => $exception::class,
                'writes' => false,
            ];
        }
    }

    /**
     * @param  list<mixed>  $turns
     * @param  list<string>  $contextRefs
     * @return array<string,mixed>
     */
    private function safeConversationHealth(string $domain, string $flowId, array $turns, array $contextRefs): array
    {
        try {
            return $this->conversationOps->healthReport([
                'goal' => 'hyperflow route '.$domain.' via '.$flowId,
                'domain' => $domain,
                'turns' => $turns,
                'context_refs' => $contextRefs,
            ]);
        } catch (Throwable $exception) {
            return [
                'schema_version' => AtlasConversationOperationsService::HEALTH_SCHEMA_VERSION,
                'status' => AtlasConversationOperationsService::STATUS_WATCH,
                'error' => 'conversation_ops_threw',
                'exception_class' => $exception::class,
                'writes' => false,
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  list<string>  $contextRefs
     * @param  list<string>  $evidenceRefs
     * @param  list<array<string,mixed>>  $mustKeepItems
     * @return array<string,mixed>
     */
    private function verifiedCompactionReport(array $input, string $flowId, array $contextRefs, array $evidenceRefs, array $mustKeepItems): array
    {
        $handoffTarget = is_array($input['handoff_target'] ?? null) ? (array) $input['handoff_target'] : null;
        if (! $this->requiresVerifiedCompaction($input, $handoffTarget, $contextRefs)) {
            return $this->skippedCompaction('not_required_for_flow');
        }

        if (! DatabaseTableAvailability::has('atlas_long_horizon_compaction_receipts')) {
            return $this->skippedCompaction('long_horizon_compaction_table_unavailable');
        }

        try {
            return $this->verifiedCompaction->compact([
                'scope_type' => $this->scopeType($flowId, $handoffTarget),
                'scope_id' => $this->scopeId($input, $flowId),
                'source_context_refs' => $contextRefs,
                'must_keep_items' => $mustKeepItems,
                'evidence_refs' => $evidenceRefs,
                'stale_risks' => array_values((array) ($input['stale_risks'] ?? [])),
                'recovery_queries' => array_values((array) ($input['recovery_queries'] ?? [])),
            ]);
        } catch (Throwable $exception) {
            return [
                'schema_version' => AtlasVerifiedCompactionService::SCHEMA_VERSION,
                'status' => AtlasVerifiedCompactionService::STATUS_BLOCKED,
                'error' => 'verified_compaction_threw',
                'exception_class' => $exception::class,
                'blockers' => [[
                    'id' => 'verified_compaction_threw',
                    'reason' => $exception->getMessage(),
                ]],
                'writes' => false,
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function skippedCompaction(string $reason): array
    {
        return [
            'schema_version' => AtlasVerifiedCompactionService::SCHEMA_VERSION,
            'status' => 'skipped',
            'reason' => $reason,
            'compaction_receipt' => [
                'must_keep_coverage' => 1.0,
                'write_allowed' => false,
            ],
            'semantic_diff' => [
                'schema_version' => AtlasVerifiedCompactionService::SEMANTIC_DIFF_SCHEMA_VERSION,
                'missing_must_keep_ids' => [],
            ],
            'blockers' => [],
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>|null  $handoffTarget
     * @param  list<string>  $contextRefs
     */
    private function requiresVerifiedCompaction(array $input, ?array $handoffTarget, array $contextRefs): bool
    {
        if (($input['force_verified_compaction'] ?? false) === true) {
            return true;
        }

        return ($handoffTarget['kind'] ?? null) === 'atlas_forge'
            || in_array((string) ($input['runtime_mode'] ?? ''), ['forge', 'deep'], true)
            || count($contextRefs) >= 4;
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>|null  $handoffTarget
     */
    private function contextRequired(array $input, ?array $handoffTarget): bool
    {
        return (bool) ($input['policy_required'] ?? false)
            || (bool) ($input['evidence_required'] ?? false)
            || (bool) ($input['tool_plan_required'] ?? false)
            || $handoffTarget !== null;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function mustKeepItems(array $input, string $flowId): array
    {
        $items = array_values((array) ($input['must_keep_items'] ?? []));
        $items[] = ['id' => 'flow_id', 'kind' => 'flow_route', 'digest' => $flowId];

        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * @param  array<string,mixed>|null  $handoffTarget
     */
    private function scopeType(string $flowId, ?array $handoffTarget): string
    {
        if (($handoffTarget['kind'] ?? null) === 'atlas_forge' || $flowId === 'atlas_forge') {
            return AtlasLongHorizonCanon::SCOPE_TYPE_FORGE_OBRA;
        }

        if (str_starts_with($flowId, 'atlas_dev') || $flowId === 'atlas_debug' || $flowId === 'atlas_review') {
            return AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION;
        }

        return AtlasLongHorizonCanon::SCOPE_TYPE_THREAD;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function scopeId(array $input, string $flowId): string
    {
        $provided = AiValueNormalizer::trimmedScalarStringOrNull($input['scope_id'] ?? null);
        if ($provided !== null) {
            return $provided;
        }

        return substr(MissionCanonicalHash::sha256([
            'prompt' => (string) ($input['prompt'] ?? ''),
            'flow_id' => $flowId,
        ]), 0, 32);
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $conversation
     * @param  array<string,mixed>  $verified
     * @param  array<string,mixed>  $critic
     */
    private function status(array $context, array $conversation, array $verified, array $critic): string
    {
        if (($context['status'] ?? null) === AtlasContextIntelligenceService::STATUS_BLOCKED
            || ($conversation['status'] ?? null) === AtlasConversationOperationsService::STATUS_BLOCKED
            || ($verified['status'] ?? null) === AtlasVerifiedCompactionService::STATUS_BLOCKED
            || ($critic['status'] ?? null) === AtlasConversationOperationsService::STATUS_BLOCKED) {
            return self::STATUS_BLOCKED;
        }

        if (($context['status'] ?? null) !== AtlasContextIntelligenceService::STATUS_READY
            || ($conversation['status'] ?? null) !== AtlasConversationOperationsService::STATUS_HEALTHY
            || ($verified['status'] ?? null) === 'skipped') {
            return self::STATUS_WATCH;
        }

        return self::STATUS_READY;
    }

}
