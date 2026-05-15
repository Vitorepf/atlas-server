<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Read-only DSL for querying evidence/proofs across the Agent Control
 * Plane certification stack (chain integrity, deterministic replay,
 * snapshot store, replay diff, promotion gate, baseline, scenario
 * simulator, release dossier and mutation guard).
 *
 * Filters: slice, capability, cli_option, readiness_method,
 * invoker_class, doc_anchor, runtime_flag, edge_from, edge_to,
 * corridor, violation_type, proof_kind, hash, status.
 *
 * Operators: equals, contains, starts_with, ends_with, exists,
 * missing, in, not_in.
 *
 * Read-only by design: never starts processes, never calls Codex
 * CLI/app, never spawns subprocesses, never invokes adapters, never
 * dispatches work, never spends tokens, never advances the next
 * required slice, never enables self-programming, never writes the
 * ledger.
 */
final class AgentControlPlaneCertificationEvidenceQueryService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_certification_evidence_query.v1';

    public const MODE = 'read_only_agent_control_plane_certification_evidence_query';

    public const SUPPORTED_FIELDS = [
        'slice',
        'capability',
        'cli_option',
        'readiness_method',
        'invoker_class',
        'doc_anchor',
        'runtime_flag',
        'edge_from',
        'edge_to',
        'corridor',
        'violation_type',
        'proof_kind',
        'hash',
        'status',
    ];

    public const SUPPORTED_OPERATORS = [
        'equals',
        'contains',
        'starts_with',
        'ends_with',
        'exists',
        'missing',
        'in',
        'not_in',
    ];

    public function __construct(
        private readonly AgentControlPlaneChainIntegrityAuditService $audit,
        private readonly AgentControlPlaneDeterministicChainReplayService $replay,
        private readonly AgentControlPlaneCertificationBaselineService $baseline,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function query(array $options = []): array
    {
        $filters = $this->normalizeFilters((array) ($options['filters'] ?? []));
        $invalidFilters = $this->validateFilters($filters);

        $audit = $this->audit->audit();
        $replay = $this->replay->replay();
        $baseline = $this->baseline->build();

        $records = $this->buildRecordIndex($audit, $replay, $baseline);

        $results = $invalidFilters === [] ? $this->applyFilters($records, $filters) : [];
        $facets = $this->buildFacets($records);
        $resultIndex = array_map(static fn (array $r) => [
            'kind' => $r['kind'],
            'field' => $r['field'],
            'value' => $r['value'],
            'meta' => $r['meta'],
        ], $results);

        $status = $invalidFilters === []
            ? ($results === [] ? 'no_match' : 'available')
            : 'blocked';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'query_id' => (string) Str::uuid(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'read_only' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'external_provider_call' => false,
            'token_spend' => false,
            'process_started' => false,
            'self_programming_allowed' => false,
            'completion_claim_allowed' => false,
            'filters' => $filters,
            'invalid_filters' => $invalidFilters,
            'supported_fields' => self::SUPPORTED_FIELDS,
            'supported_operators' => self::SUPPORTED_OPERATORS,
            'result_count' => count($resultIndex),
            'results' => $resultIndex,
            'facets' => $facets,
            'record_count' => count($records),
            'non_execution_guarantees' => [
                'evidence_query_does_not_start_codex',
                'evidence_query_does_not_call_codex_cli_or_app',
                'evidence_query_does_not_spawn_subprocess',
                'evidence_query_does_not_invoke_adapter',
                'evidence_query_does_not_execute_adapter',
                'evidence_query_does_not_call_provider',
                'evidence_query_does_not_dispatch_work',
                'evidence_query_does_not_spend_tokens',
                'evidence_query_does_not_enable_self_programming',
                'evidence_query_does_not_write_ledger',
                'evidence_query_does_not_mutate_pointer',
                'evidence_query_does_not_promote_completion_claim',
            ],
            'human_summary' => match ($status) {
                'available' => sprintf('Evidence query returned %d match(es).', count($resultIndex)),
                'no_match' => 'Evidence query produced no matches for the given filters.',
                'blocked' => 'Evidence query is blocked due to invalid filters; review supported fields/operators.',
                default => 'Evidence query status is unknown.',
            },
        ];

        $payload['query_hash'] = $this->stableHash($this->normalizeForQueryHash($payload));

        return $payload;
    }

    /**
     * @param  array<int|string, mixed>  $rawFilters
     * @return list<array<string, mixed>>
     */
    private function normalizeFilters(array $rawFilters): array
    {
        $normalized = [];
        foreach ($rawFilters as $filter) {
            if (! is_array($filter)) {
                continue;
            }
            $field = (string) ($filter['field'] ?? '');
            $operator = (string) ($filter['operator'] ?? 'equals');
            $value = $filter['value'] ?? null;
            $normalized[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $filters
     * @return list<array<string, mixed>>
     */
    private function validateFilters(array $filters): array
    {
        $invalid = [];
        foreach ($filters as $filter) {
            $reasons = [];
            if (! in_array($filter['field'], self::SUPPORTED_FIELDS, true)) {
                $reasons[] = 'unsupported_field';
            }
            if (! in_array($filter['operator'], self::SUPPORTED_OPERATORS, true)) {
                $reasons[] = 'unsupported_operator';
            }
            if (in_array($filter['operator'], ['in', 'not_in'], true) && ! is_array($filter['value'])) {
                $reasons[] = 'value_must_be_array_for_in_or_not_in';
            }
            if (in_array($filter['operator'], ['exists', 'missing'], true) && $filter['value'] !== null && $filter['value'] !== '') {
                // exists / missing operators ignore value — only field matters; value should be empty.
            }
            if ($reasons !== []) {
                $invalid[] = [
                    'filter' => $filter,
                    'reasons' => $reasons,
                ];
            }
        }

        return $invalid;
    }

    /**
     * @param  array<string, mixed>  $audit
     * @param  array<string, mixed>  $replay
     * @param  array<string, mixed>  $baseline
     * @return list<array<string, mixed>>
     */
    private function buildRecordIndex(array $audit, array $replay, array $baseline): array
    {
        $records = [];

        foreach ((array) data_get($audit, 'slices', []) as $slice) {
            $sliceKey = (string) data_get($slice, 'slice_key', '');
            $records[] = [
                'kind' => 'slice',
                'field' => 'slice',
                'value' => $sliceKey,
                'meta' => [
                    'method_prefix' => (string) data_get($slice, 'method_prefix', ''),
                    'invoker_class' => (string) data_get($slice, 'invoker_class', ''),
                    'doc_anchor' => (string) data_get($slice, 'doc_bullet', ''),
                    'activate_key' => (string) data_get($slice, 'activate_key', ''),
                    'runtime_key' => (string) data_get($slice, 'runtime_key', ''),
                    'all_artifacts_ok' => (bool) data_get($slice, 'ok', false),
                ],
            ];
            if ((string) data_get($slice, 'method_prefix', '') !== '') {
                $records[] = [
                    'kind' => 'readiness_method',
                    'field' => 'readiness_method',
                    'value' => (string) data_get($slice, 'method_prefix', ''),
                    'meta' => ['slice' => $sliceKey],
                ];
            }
            if ((string) data_get($slice, 'invoker_class', '') !== '') {
                $records[] = [
                    'kind' => 'invoker_class',
                    'field' => 'invoker_class',
                    'value' => (string) data_get($slice, 'invoker_class', ''),
                    'meta' => [
                        'slice' => $sliceKey,
                        'prepare_method' => (string) data_get($slice, 'prepare_method', ''),
                    ],
                ];
            }
            if ((string) data_get($slice, 'doc_bullet', '') !== '') {
                $records[] = [
                    'kind' => 'doc_anchor',
                    'field' => 'doc_anchor',
                    'value' => (string) data_get($slice, 'doc_bullet', ''),
                    'meta' => ['slice' => $sliceKey],
                ];
            }
        }

        $capabilities = (array) data_get($baseline, 'sections.capability_surface.capabilities', []);
        foreach ($capabilities as $capability) {
            $records[] = [
                'kind' => 'capability',
                'field' => 'capability',
                'value' => (string) $capability,
                'meta' => [],
            ];
        }

        $cliOptions = (array) data_get($audit, 'cli_surface.present_options', []);
        foreach ($cliOptions as $option) {
            $records[] = [
                'kind' => 'cli_option',
                'field' => 'cli_option',
                'value' => (string) $option,
                'meta' => [],
            ];
        }

        foreach ((array) data_get($audit, 'per_slice_next_edge', []) as $edge) {
            $records[] = [
                'kind' => 'edge',
                'field' => 'edge_from',
                'value' => (string) data_get($edge, 'from_slice', ''),
                'meta' => [
                    'edge_to' => (string) data_get($edge, 'expected_next', '') ?: (string) data_get($edge, 'declared_next', ''),
                    'edge_ok' => (bool) data_get($edge, 'edge_ok', false),
                ],
            ];
            $records[] = [
                'kind' => 'edge',
                'field' => 'edge_to',
                'value' => (string) data_get($edge, 'expected_next', '') ?: (string) data_get($edge, 'declared_next', ''),
                'meta' => [
                    'edge_from' => (string) data_get($edge, 'from_slice', ''),
                    'edge_ok' => (bool) data_get($edge, 'edge_ok', false),
                ],
            ];
        }

        $runtimeSafety = (array) data_get($replay, 'runtime_safety', []);
        foreach ($runtimeSafety as $flagKey => $flagValue) {
            if (! is_bool($flagValue)) {
                continue;
            }
            $records[] = [
                'kind' => 'runtime_flag',
                'field' => 'runtime_flag',
                'value' => (string) $flagKey,
                'meta' => ['flag_value' => $flagValue],
            ];
        }

        foreach ((array) data_get($audit, 'violations', []) as $violation) {
            $records[] = [
                'kind' => 'violation',
                'field' => 'violation_type',
                'value' => (string) data_get($violation, 'code', 'unknown_violation'),
                'meta' => [
                    'detail' => (string) data_get($violation, 'detail', ''),
                    'slice_key' => (string) data_get($violation, 'slice_key', ''),
                ],
            ];
        }

        $proofBundle = (array) data_get($replay, 'proof_bundle', []);
        foreach (array_keys($proofBundle) as $proofKind) {
            $records[] = [
                'kind' => 'proof',
                'field' => 'proof_kind',
                'value' => (string) $proofKind,
                'meta' => [],
            ];
        }

        foreach ([
            'chain_integrity_hash' => (string) data_get($baseline, 'chain_integrity_hash', ''),
            'deterministic_replay_hash' => (string) data_get($baseline, 'deterministic_replay_hash', ''),
            'baseline_hash' => (string) data_get($baseline, 'baseline_hash', ''),
            'docs_hash' => (string) data_get($baseline, 'docs_hash', ''),
            'capability_surface_hash' => (string) data_get($baseline, 'capability_surface_hash', ''),
            'runtime_safety_hash' => (string) data_get($baseline, 'runtime_safety_hash', ''),
        ] as $hashKind => $hashValue) {
            if ($hashValue === '') {
                continue;
            }
            $records[] = [
                'kind' => 'hash',
                'field' => 'hash',
                'value' => $hashValue,
                'meta' => ['hash_kind' => $hashKind],
            ];
        }

        foreach ([
            'chain_integrity' => (string) data_get($audit, 'status', ''),
            'replay' => (string) data_get($replay, 'status', ''),
            'baseline' => (string) data_get($baseline, 'status', ''),
        ] as $statusKind => $statusValue) {
            if ($statusValue === '') {
                continue;
            }
            $records[] = [
                'kind' => 'status',
                'field' => 'status',
                'value' => $statusValue,
                'meta' => ['layer' => $statusKind],
            ];
        }

        $corridors = ['post_start_evidence_corridor', 'implementation_to_operator_handoff_corridor', 'provider_to_runtime_corridor'];
        foreach ($corridors as $corridor) {
            $records[] = [
                'kind' => 'corridor',
                'field' => 'corridor',
                'value' => $corridor,
                'meta' => [],
            ];
        }

        return $records;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  list<array<string, mixed>>  $filters
     * @return list<array<string, mixed>>
     */
    private function applyFilters(array $records, array $filters): array
    {
        if ($filters === []) {
            return $records;
        }

        $matches = [];
        foreach ($records as $record) {
            $allOk = true;
            foreach ($filters as $filter) {
                if ((string) $record['field'] !== (string) $filter['field']) {
                    $allOk = false;
                    break;
                }
                if (! $this->matchOperator((string) $record['value'], $filter['operator'], $filter['value'])) {
                    $allOk = false;
                    break;
                }
            }
            if ($allOk) {
                $matches[] = $record;
            }
        }

        return $matches;
    }

    private function matchOperator(string $value, string $operator, mixed $needle): bool
    {
        return match ($operator) {
            'equals' => $value === (string) $needle,
            'contains' => $needle !== null && $needle !== '' && str_contains($value, (string) $needle),
            'starts_with' => $needle !== null && $needle !== '' && str_starts_with($value, (string) $needle),
            'ends_with' => $needle !== null && $needle !== '' && str_ends_with($value, (string) $needle),
            'exists' => $value !== '',
            'missing' => $value === '',
            'in' => is_array($needle) && in_array($value, array_map('strval', $needle), true),
            'not_in' => is_array($needle) && ! in_array($value, array_map('strval', $needle), true),
            default => false,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, int>
     */
    private function buildFacets(array $records): array
    {
        $facets = [];
        foreach ($records as $record) {
            $field = (string) $record['field'];
            $facets[$field] = ($facets[$field] ?? 0) + 1;
        }
        ksort($facets);

        return $facets;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForQueryHash(array $payload): array
    {
        $clone = $payload;
        unset($clone['query_id'], $clone['generated_at'], $clone['query_hash']);

        return $this->recursivelyKsort($clone);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    private function recursivelyKsort(array $value): array
    {
        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->recursivelyKsort($entry);
            }
        }
        if ($isAssoc) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  array<mixed, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        $payload = $this->recursivelyKsort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
