<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskServing;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;

/**
 * Read-only, facts-only auditor that compares legacy queue records' task_packet.simplicity_contract
 * against the canonical default. Returns deterministic envelopes, no scalar scoring, no side effects.
 */
final class AtlasTaskSimplicityContractAuditor
{
    public const SCHEMA = 'atlas.task_serving.simplicity_contract_audit.v1';

    public const STATUS_CONFORMING = 'conforming';

    public const STATUS_MISSING = 'missing';

    public const STATUS_DRIFTED = 'drifted';

    public const STATUS_SKIPPED = 'skipped';

    /**
     * @param  list<array<string,mixed>>  $records
     * @return array<string,mixed>
     */
    public function audit(array $records): array
    {
        $default = AgentControlPlaneTaskPacketBuilder::defaultSimplicityContract();
        $findings = [];
        $inspected = 0;
        $conforming = 0;
        $missing = 0;
        $drift = 0;
        $skipped = 0;

        foreach ($records as $record) {
            if (! is_array($record) || ! isset($record['task_packet']) || ! is_array($record['task_packet'])) {
                $skipped++;
                $findings[] = $this->finding($record, self::STATUS_SKIPPED, [], 'malformed_record', 'skip_no_action');

                continue;
            }
            $inspected++;
            $packet = $record['task_packet'];
            $contract = $packet['simplicity_contract'] ?? null;

            if (! is_array($contract)) {
                $missing++;
                $findings[] = $this->finding(
                    $record,
                    self::STATUS_MISSING,
                    array_keys($default),
                    'simplicity_contract_absent',
                    'rebuild_packet_with_default_contract',
                );

                continue;
            }

            $driftFields = [];
            foreach ($default as $key => $expected) {
                if (! array_key_exists($key, $contract) || $contract[$key] !== $expected) {
                    $driftFields[] = $key;
                }
            }

            if ($driftFields === []) {
                $conforming++;
                $findings[] = $this->finding($record, self::STATUS_CONFORMING, [], 'matches_default_contract', 'no_action');
            } else {
                $drift++;
                $findings[] = $this->finding(
                    $record,
                    self::STATUS_DRIFTED,
                    $driftFields,
                    'drifted_from_default_contract',
                    'reset_drifted_fields_to_default',
                );
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'status' => 'ok',
            'inspected_count' => $inspected,
            'conforming_count' => $conforming,
            'missing_count' => $missing,
            'drift_count' => $drift,
            'skipped_count' => $skipped,
            'findings' => $findings,
            'proof_summary' => [
                'default_contract_field_count' => count($default),
                'records_total' => count($records),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>|mixed  $record
     * @param  list<string>  $driftFields
     * @return array<string,mixed>
     */
    private function finding(mixed $record, string $status, array $driftFields, string $findingType, string $recommendedAction): array
    {
        $packet = is_array($record) && isset($record['task_packet']) && is_array($record['task_packet']) ? $record['task_packet'] : [];

        return [
            'task_packet_id' => (string) ($packet['task_packet_id'] ?? (is_array($record) ? (string) ($record['task_packet_id'] ?? '') : '')),
            'status' => $status,
            'wave' => is_array($record) ? ($record['wave'] ?? null) : null,
            'tags' => is_array($record) ? array_values((array) ($record['tags'] ?? [])) : [],
            'finding_type' => $findingType,
            'drift_fields' => array_values($driftFields),
            'recommended_action' => $recommendedAction,
        ];
    }
}
