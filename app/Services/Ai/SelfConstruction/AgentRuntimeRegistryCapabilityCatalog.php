<?php

namespace App\Services\Ai\SelfConstruction;

/**
 * Canonical catalog of Agent Control Plane agent capabilities.
 *
 * Provides normalization, validation and matching of capability sets.
 * Pure projection: never starts processes, never calls providers, never
 * dispatches work, never spends tokens and never writes the evidence
 * ledger.
 */
final class AgentRuntimeRegistryCapabilityCatalog
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_runtime_registry_capability_catalog.v1';

    public const MODE = 'read_only_agent_runtime_registry_capability_catalog';

    public const CAPABILITIES = [
        'code_review',
        'code_edit',
        'test_runner',
        'docs_writer',
        'self_construction_readiness',
        'task_packet_handling',
        'claim_lease_handling',
        'workspace_planning',
        'workspace_isolation',
        'evidence_collection',
        'continuation_summary',
        'cost_reporting',
        'human_approval',
        'dry_run_only',
    ];

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function catalog(array $options = []): array
    {
        $includeDescription = (bool) ($options['include_description'] ?? false);

        $entries = [];
        foreach (self::CAPABILITIES as $capability) {
            $entry = [
                'capability' => $capability,
                'canonical' => true,
            ];
            if ($includeDescription) {
                $entry['description'] = $this->describe($capability);
            }
            $entries[] = $entry;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'capability_count' => count($entries),
            'capabilities' => $entries,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
        ];
    }

    /**
     * Normalize a capability list: lowercase, trim, dedupe, sort.
     *
     * @param  array<int, mixed>  $capabilities
     * @return list<string>
     */
    public function normalizeCapabilities(array $capabilities): array
    {
        $normalized = [];
        foreach ($capabilities as $capability) {
            $value = strtolower(trim((string) $capability));
            if ($value === '') {
                continue;
            }
            $normalized[$value] = true;
        }
        $keys = array_keys($normalized);
        sort($keys);

        return array_values($keys);
    }

    /**
     * @param  array<int, mixed>  $capabilities
     * @return array<string, mixed>
     */
    public function validateCapabilities(array $capabilities): array
    {
        $normalized = $this->normalizeCapabilities($capabilities);
        $unknown = [];
        $known = [];
        foreach ($normalized as $cap) {
            if (in_array($cap, self::CAPABILITIES, true)) {
                $known[] = $cap;
            } else {
                $unknown[] = $cap;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'normalized' => $normalized,
            'known' => $known,
            'unknown' => $unknown,
            'is_valid' => $unknown === [],
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
        ];
    }

    /**
     * Normalize raw capability definitions into deterministic structured records.
     * Each record includes: id, class, required_gates, evidence_abilities, and safety_flags.
     *
     * @param  array<int, array<string, mixed>>  $capabilities  raw capability definitions
     * @return array{schema_version:string, mode:string, records:list<array{id:string, class:string, required_gates:list<string>, evidence_abilities:list<string>, safety_flags:array<string,bool>}>}
     */
    public function normalizeCapabilityRecords(array $capabilities): array
    {
        $records = [];
        $seen = [];

        foreach ($capabilities as $cap) {
            if (! is_array($cap)) {
                continue;
            }
            $id = strtolower(trim((string) ($cap['id'] ?? '')));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $class = strtolower(trim((string) ($cap['class'] ?? 'unknown')));
            $requiredGates = $this->normalizeCapabilities($cap['required_gates'] ?? []);
            $evidenceAbilities = $this->normalizeCapabilities($cap['evidence_abilities'] ?? []);
            $safetyFlags = [
                'runtime_execution_allowed' => (bool) ($cap['safety_flags']['runtime_execution_allowed'] ?? false),
                'dispatch_allowed' => (bool) ($cap['safety_flags']['dispatch_allowed'] ?? false),
                'provider_call_allowed' => (bool) ($cap['safety_flags']['provider_call_allowed'] ?? false),
                'token_spend_allowed' => (bool) ($cap['safety_flags']['token_spend_allowed'] ?? false),
                'self_programming_allowed' => (bool) ($cap['safety_flags']['self_programming_allowed'] ?? false),
                'ledger_write_allowed' => (bool) ($cap['safety_flags']['ledger_write_allowed'] ?? false),
            ];

            $records[] = [
                'id' => $id,
                'class' => $class,
                'required_gates' => $requiredGates,
                'evidence_abilities' => $evidenceAbilities,
                'safety_flags' => $safetyFlags,
            ];
        }

        usort($records, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'records' => $records,
        ];
    }

    /**
     * Validate structured capability records: rejects duplicate ids, missing
     * required gates, missing evidence abilities, or unsafe runtime flags.
     *
     * @param  array<int, array<string, mixed>>  $capabilities
     * @return array{schema_version:string, is_valid:bool, violations:list<string>, records:list<array<string,mixed>>}
     */
    public function validateCapabilityRecords(array $capabilities): array
    {
        $normalized = $this->normalizeCapabilityRecords($capabilities);
        $records = $normalized['records'];
        $violations = [];
        $seenIds = [];

        foreach ($records as $record) {
            $id = $record['id'];
            if (isset($seenIds[$id])) {
                $violations[] = 'duplicate_id:'.$id;
            }
            $seenIds[$id] = true;

            if ($record['required_gates'] === []) {
                $violations[] = 'missing_required_gates:'.$id;
            }
            if ($record['evidence_abilities'] === []) {
                $violations[] = 'missing_evidence_ability:'.$id;
            }
            foreach ($record['safety_flags'] as $flag => $value) {
                if ($value === true) {
                    $violations[] = 'unsafe_runtime_flag:'.$id.':'.$flag;
                }
            }
        }

        sort($violations, SORT_STRING);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'is_valid' => $violations === [],
            'violations' => $violations,
            'records' => $records,
        ];
    }

    /**
     * Compute the match between a required capability set and an available set,
     * optionally distinguishing missing proof ability and unsafe runtime state
     * when capability facts are supplied.
     *
     * @param  array<int, mixed>  $required
     * @param  array<int, mixed>  $available
     * @param  array<string, mixed>  $capabilityFacts  optional per-capability proof/safety facts
     * @return array<string, mixed>
     */
    public function match(array $required, array $available, array $capabilityFacts = []): array
    {
        $req = $this->normalizeCapabilities($required);
        $avail = $this->normalizeCapabilities($available);

        $matched = array_values(array_intersect($req, $avail));
        $missing = array_values(array_diff($req, $avail));
        $extra = array_values(array_diff($avail, $req));

        $requiredCount = count($req);
        $matchedCount = count($matched);
        $matchScore = $requiredCount === 0 ? 1.0 : round($matchedCount / $requiredCount, 4);

        $missingProofAbilities = [];
        $unsafeRuntimeStates = [];

        if ($capabilityFacts !== []) {
            foreach ($matched as $cap) {
                $facts = $capabilityFacts[$cap] ?? null;
                if (! is_array($facts)) {
                    continue;
                }
                $hasProof = (bool) ($facts['has_proof'] ?? false);
                $runtimeSafe = (bool) ($facts['runtime_safe'] ?? true);
                if (! $hasProof) {
                    $missingProofAbilities[] = $cap;
                }
                if (! $runtimeSafe) {
                    $unsafeRuntimeStates[] = $cap;
                }
            }
        }

        if ($missing !== [] && $matchedCount > 0) {
            $matchStatus = 'partial';
        } elseif ($missing !== []) {
            $matchStatus = 'missing';
        } elseif ($missingProofAbilities !== []) {
            $matchStatus = 'missing_proof_ability';
        } elseif ($unsafeRuntimeStates !== []) {
            $matchStatus = 'unsafe_runtime_state';
        } else {
            $matchStatus = 'matched';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'required' => $req,
            'available' => $avail,
            'matched' => $matched,
            'missing' => $missing,
            'extra' => $extra,
            'match_score' => $matchScore,
            'match_status' => $matchStatus,
            'missing_proof_abilities' => $missingProofAbilities,
            'unsafe_runtime_states' => $unsafeRuntimeStates,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
        ];
    }

    public const CAPABILITY_STATUS_VERIFIED = 'verified';

    public const CAPABILITY_STATUS_UNVERIFIED = 'unverified';

    public const CAPABILITY_STATUS_FAILURE_PRONE = 'failure_prone';

    private const HIGH_GIVE_BACK_RATE_CEILING = 0.50;

    private const VERIFIED_SUCCESS_RATE_FLOOR = 0.70;

    private const MIN_OUTCOME_SAMPLE_FOR_VERDICT = 3;

    /**
     * Derives a capability's real, observed status for a (capability,
     * task_family) pair — never trusts a self-declared label alone.
     *
     * capability_status (first match wins):
     *   failure_prone — sample >= MIN_OUTCOME_SAMPLE_FOR_VERDICT AND
     *                    give_back_rate > HIGH_GIVE_BACK_RATE_CEILING, OR the
     *                    capability is listed in known_failure_modes.
     *   verified      — sample >= MIN_OUTCOME_SAMPLE_FOR_VERDICT AND
     *                    success_rate >= VERIFIED_SUCCESS_RATE_FLOOR.
     *   unverified    — everything else, INCLUDING a self_declared=true claim
     *                    with no outcome evidence at all — a label is not proof.
     *
     * routing_hint: verified → route_freely; unverified → route_with_caution_collect_evidence;
     *               failure_prone → avoid_routing.
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function evaluateObservedCapability(array $facts): array
    {
        $capability = (string) ($facts['capability'] ?? '');
        $taskFamily = (string) ($facts['task_family'] ?? '');
        $supportedTools = array_map('strval', (array) ($facts['supported_tools'] ?? []));
        $selfDeclared = (bool) ($facts['self_declared'] ?? false);
        $recentOutcomes = (array) ($facts['recent_outcomes'] ?? []);
        $knownFailureModes = array_map('strval', (array) ($facts['known_failure_modes'] ?? []));

        $sampleSize = count($recentOutcomes);
        $successCount = count(array_filter(
            $recentOutcomes,
            static fn ($o): bool => is_array($o) && (string) ($o['outcome'] ?? '') === 'success',
        ));
        $giveBackCount = count(array_filter(
            $recentOutcomes,
            static fn ($o): bool => is_array($o) && (string) ($o['outcome'] ?? '') === 'give_back',
        ));
        $successRate = $sampleSize > 0 ? round($successCount / $sampleSize, 4) : 0.0;
        $giveBackRate = $sampleSize > 0 ? round($giveBackCount / $sampleSize, 4) : 0.0;

        $hasEnoughSample = $sampleSize >= self::MIN_OUTCOME_SAMPLE_FOR_VERDICT;
        $isFailureProne = $knownFailureModes !== []
            || ($hasEnoughSample && $giveBackRate > self::HIGH_GIVE_BACK_RATE_CEILING);
        $isVerified = ! $isFailureProne && $hasEnoughSample && $successRate >= self::VERIFIED_SUCCESS_RATE_FLOOR;

        $capabilityStatus = match (true) {
            $isFailureProne => self::CAPABILITY_STATUS_FAILURE_PRONE,
            $isVerified => self::CAPABILITY_STATUS_VERIFIED,
            default => self::CAPABILITY_STATUS_UNVERIFIED,
        };

        $routingHint = match ($capabilityStatus) {
            self::CAPABILITY_STATUS_VERIFIED => 'route_freely',
            self::CAPABILITY_STATUS_FAILURE_PRONE => 'avoid_routing',
            default => 'route_with_caution_collect_evidence',
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'capability' => $capability,
            'task_family' => $taskFamily,
            'supported_tools' => $supportedTools,
            'self_declared' => $selfDeclared,
            'evidence_summary' => [
                'sample_size' => $sampleSize,
                'success_rate' => $successRate,
                'give_back_rate' => $giveBackRate,
                'known_failure_modes' => $knownFailureModes,
            ],
            'capability_status' => $capabilityStatus,
            'routing_hint' => $routingHint,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function runtimeFlags(): array
    {
        return [
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
        ];
    }

    private function describe(string $capability): string
    {
        return match ($capability) {
            'code_review' => 'review code changes without executing them',
            'code_edit' => 'apply scoped code edits under packet boundaries',
            'test_runner' => 'execute test suites in workspace isolation',
            'docs_writer' => 'author and update canonical documentation',
            'self_construction_readiness' => 'evaluate self-construction posture',
            'task_packet_handling' => 'consume task packets without dispatch',
            'claim_lease_handling' => 'simulate claim/lease lifecycle entries',
            'workspace_planning' => 'plan workspace use without provisioning',
            'workspace_isolation' => 'operate inside isolated worktrees',
            'evidence_collection' => 'attach evidence references to a packet',
            'continuation_summary' => 'produce continuation summaries for runs',
            'cost_reporting' => 'report cost events without billing impact',
            'human_approval' => 'require operator sign-off before promotion',
            'dry_run_only' => 'restricted to dry-run packets',
            default => 'undocumented capability',
        };
    }
}
