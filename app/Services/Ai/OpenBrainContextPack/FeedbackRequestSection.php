<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrainContextPack;

use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\Context\AtlasCanonicalContextRef;

/**
 * GOD-DEBULK split of {@see \App\Services\Ai\AtlasOpenBrainContextPackService}.
 * Verbatim feedbackrequest family extracted from the AOBG context-pack
 * façade; behavior-preserving (private helpers -> Support; public API stays on the façade).
 */
final class FeedbackRequestSection
{
    public function __construct(
        private readonly Support $support,
    ) {}

    /**
     * @param  array<string,mixed>  $pack
     */
    public function contextPackHash(array $pack): string
    {
        return hash('sha256', (string) json_encode(
            $this->canonicalize($pack),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param  array<string,mixed>  $pack
     * @param  array<string,mixed>  $opts
     * @return array<string,mixed>
     */
    public function contextFeedbackRequest(array $pack, array $opts): array
    {
        $flow = $this->contextFeedbackRequestFlow($opts);
        $deliveredRefs = $this->deliveredContextRefs($pack);

        return [
            'schema_version' => AtlasOpenBrainContextPackService::CONTEXT_FEEDBACK_REQUEST_SCHEMA,
            'status' => 'requested',
            'mode' => 'post_execution_provider_safe_roi',
            'tool' => 'atlas_context_feedback',
            'timing' => 'after_execution',
            'context_pack_hash' => (string) ($pack['context_pack_hash'] ?? ''),
            'retrieval_receipt_id' => (string) ($pack['context_pack_hash'] ?? ''),
            'flow_id' => $flow['flow_id'],
            'domain' => $flow['domain'],
            'task_type' => $flow['task_type'],
            'delivered_context_refs' => $deliveredRefs,
            'delivered_ref_count' => count($deliveredRefs),
            'source_types' => $this->feedbackSourceTypes($deliveredRefs),
            'arguments_template' => [
                'objective' => (string) ($pack['task'] ?? ''),
                'workspace' => (string) ($pack['workspace'] ?? ''),
                'flow_id' => $flow['flow_id'],
                'domain' => $flow['domain'],
                'task_type' => $flow['task_type'],
                'outcome_status' => 'passed|partial|failed|blocked',
                'context_pack_hash' => (string) ($pack['context_pack_hash'] ?? ''),
                'retrieval_receipt_id' => (string) ($pack['context_pack_hash'] ?? ''),
                'delivered_context_refs' => $deliveredRefs,
                'used_context_refs' => [],
                'noise_context_refs' => [],
                'missed_required_sources' => [],
                'post_execution_utility' => '0-100',
                'record' => true,
            ],
            'required_after_execution' => [
                'outcome_status',
                'used_context_refs',
                'post_execution_utility',
            ],
            'optional_after_execution' => [
                'noise_context_refs',
                'missed_required_sources',
                'run_outcome_id',
            ],
            'policy' => [
                'provider_safe_only' => true,
                'raw_text_exposed' => false,
                'raw_logs_allowed' => false,
                'providers_invoked' => false,
                'writes_only_when_record_true' => true,
                'auto_promote_learning' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $opts
     * @return array{flow_id:string,domain:string,task_type:string}
     */
    public function contextFeedbackRequestFlow(array $opts): array
    {
        $explicitFlow = $this->support->stringOpt($opts, 'flow_id');
        $domain = $this->support->stringOpt($opts, 'domain');
        $taskType = $this->support->stringOpt($opts, 'task_type');

        if ($explicitFlow !== null && str_contains($explicitFlow, '.')) {
            [$flowDomain, $flowTaskType] = array_pad(explode('.', $explicitFlow, 2), 2, null);
            $domain ??= is_string($flowDomain) && trim($flowDomain) !== '' ? trim($flowDomain) : null;
            $taskType ??= is_string($flowTaskType) && trim($flowTaskType) !== '' ? trim($flowTaskType) : null;
        }

        $domain ??= 'atlas';
        $taskType ??= 'dev';

        return [
            'flow_id' => $explicitFlow ?? $domain.'.'.$taskType,
            'domain' => $domain,
            'task_type' => $taskType,
        ];
    }

    /**
     * @param  array<string,mixed>  $pack
     * @return array<int,string>
     */
    public function deliveredContextRefs(array $pack): array
    {
        return AtlasCanonicalContextRef::deliveredFromPack($pack);
    }

    /**
     * @param  array<int,string>  $refs
     * @return array<int,string>
     */
    public function feedbackSourceTypes(array $refs): array
    {
        return $this->support->uniqueStrings(array_map(
            static fn (string $ref): string => str_contains($ref, ':') ? strstr($ref, ':', true) ?: 'unknown' : 'unknown',
            $refs,
        ));
    }

    public function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
