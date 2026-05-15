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
     * Compute the match between a required capability set and an available set.
     *
     * @param  array<int, mixed>  $required
     * @param  array<int, mixed>  $available
     * @return array<string, mixed>
     */
    public function match(array $required, array $available): array
    {
        $req = $this->normalizeCapabilities($required);
        $avail = $this->normalizeCapabilities($available);

        $matched = array_values(array_intersect($req, $avail));
        $missing = array_values(array_diff($req, $avail));
        $extra = array_values(array_diff($avail, $req));

        $requiredCount = count($req);
        $matchedCount = count($matched);
        $matchScore = $requiredCount === 0 ? 1.0 : round($matchedCount / $requiredCount, 4);

        if ($missing === [] && $requiredCount > 0) {
            $matchStatus = 'matched';
        } elseif ($missing === [] && $requiredCount === 0) {
            $matchStatus = 'matched';
        } elseif ($matchedCount > 0) {
            $matchStatus = 'partial';
        } else {
            $matchStatus = 'missing';
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
