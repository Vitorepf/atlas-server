<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiAgentLoopCertification;

/**
 * Pure JSON-and-hash canonicalization helpers for the multi-agent loop
 * certification service.
 *
 * Extracted from AgentControlPlaneMultiAgentLoopCertificationService to reduce
 * the god-class. All methods are pure — no instance state.
 */
final class AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer
{
    /**
     * @param  mixed  $disk
     * @return array<string, mixed>|null
     */
    public static function loadJson($disk, string $path): ?array
    {
        if (! $disk->exists($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) $disk->get($path), true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function encodeJson(array $payload): string
    {
        return (string) json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function normalizeForHash(array $payload): array
    {
        $clone = $payload;
        unset(
            $clone['certification_id'],
            $clone['generated_at'],
            $clone['certification_hash'],
            $clone['human_summary'],
        );
        // Cycle evidence embeds run-id-scoped task/lease ids; exclude the
        // volatile per-run identifiers so consecutive certifications of the
        // same invariants produce a comparable digest.
        if (isset($clone['cycle_evidence']) && is_array($clone['cycle_evidence'])) {
            $clone['cycle_evidence'] = array_map(function (array $cycle): array {
                unset($cycle['seeded_packet_ids']);
                if (isset($cycle['agents']) && is_array($cycle['agents'])) {
                    $cycle['agents'] = array_map(function (array $agent): array {
                        unset($agent['agent_id'], $agent['task_packet_id'], $agent['lease_id']);

                        return $agent;
                    }, $cycle['agents']);
                }
                if (isset($cycle['recovery']) && is_array($cycle['recovery'])) {
                    unset(
                        $cycle['recovery']['orphan_lease_id'],
                        $cycle['recovery']['expired_lease_id'],
                        $cycle['recovery']['expiration_result'],
                    );
                }
                unset($cycle['continuation_hashes']);

                return $cycle;
            }, $clone['cycle_evidence']);
        }
        if (isset($clone['continuation_summary_hashes'])) {
            unset($clone['continuation_summary_hashes']);
        }
        if (isset($clone['terminal_loop_fleet_launch_plan_probe']) && is_array($clone['terminal_loop_fleet_launch_plan_probe'])) {
            unset(
                $clone['terminal_loop_fleet_launch_plan_probe']['queue_tag'],
                $clone['terminal_loop_fleet_launch_plan_probe']['seeded_packet_ids'],
                $clone['terminal_loop_fleet_launch_plan_probe']['fleet_launch_plan_hash'],
            );
        }
        if (isset($clone['terminal_loop_fleet_partial_supply_gate_probe']) && is_array($clone['terminal_loop_fleet_partial_supply_gate_probe'])) {
            unset(
                $clone['terminal_loop_fleet_partial_supply_gate_probe']['queue_tag'],
                $clone['terminal_loop_fleet_partial_supply_gate_probe']['seeded_task_packet_id'],
            );
        }
        if (isset($clone['terminal_loop_fleet_resume_rollup_probe']) && is_array($clone['terminal_loop_fleet_resume_rollup_probe'])) {
            unset(
                $clone['terminal_loop_fleet_resume_rollup_probe']['queue_tag'],
                $clone['terminal_loop_fleet_resume_rollup_probe']['task_packet_id'],
                $clone['terminal_loop_fleet_resume_rollup_probe']['lease_id'],
                $clone['terminal_loop_fleet_resume_rollup_probe']['fleet_resume_rollup_hash'],
            );
        }
        if (isset($clone['terminal_loop_fleet_released_resume_probe']) && is_array($clone['terminal_loop_fleet_released_resume_probe'])) {
            unset(
                $clone['terminal_loop_fleet_released_resume_probe']['queue_tag'],
                $clone['terminal_loop_fleet_released_resume_probe']['task_packet_id'],
                $clone['terminal_loop_fleet_released_resume_probe']['lease_id'],
            );
        }
        if (isset($clone['terminal_loop_fleet_evidence_rollup_probe']) && is_array($clone['terminal_loop_fleet_evidence_rollup_probe'])) {
            unset(
                $clone['terminal_loop_fleet_evidence_rollup_probe']['queue_tag'],
                $clone['terminal_loop_fleet_evidence_rollup_probe']['task_packet_id'],
                $clone['terminal_loop_fleet_evidence_rollup_probe']['fleet_evidence_rollup_hash'],
            );
        }
        if (isset($clone['queue_summary'])) {
            unset(
                $clone['queue_summary']['entry_count'],
                $clone['queue_summary']['total_count'],
                $clone['queue_summary']['status_counts'],
                $clone['queue_summary']['corrupt'],
            );
        }
        if (isset($clone['lease_summary'])) {
            unset($clone['lease_summary']['active_lease_count']);
        }

        return self::recursivelyKsort($clone);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    public static function recursivelyKsort(array $value): array
    {
        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = self::recursivelyKsort($entry);
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
    public static function stableHash(array $payload): string
    {
        $payload = self::recursivelyKsort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
