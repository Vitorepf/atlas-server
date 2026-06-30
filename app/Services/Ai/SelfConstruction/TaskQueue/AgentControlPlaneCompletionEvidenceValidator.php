<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQueue;

use App\Services\Ai\AutonomousEvolution\Discovery\Supply\AtlasLoopRefillerPayloadNormalizer;

/**
 * Completion-evidence validation and canonical hashing for the Agent Control
 * Plane task queue orchestrator.
 *
 * Extracted from AgentControlPlaneTaskQueueOrchestrator to reduce the
 * god-class. Pure static methods — no instance state.
 */
final class AgentControlPlaneCompletionEvidenceValidator
{
    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    public static function completionEvidence(array $evidence): array
    {
        $nested = $evidence['completion_evidence'] ?? null;
        if (is_array($nested)) {
            return array_merge($nested, [
                'evidence_hash' => $evidence['evidence_hash'] ?? $evidence['operator_supplied_evidence_hash'] ?? $nested['evidence_hash'] ?? null,
            ]);
        }

        return $evidence;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $expectedBinding
     * @return array<string, mixed>
     */
    public static function validateCompletionEvidence(array $evidence, array $expectedBinding): array
    {
        $evidenceHash = strtolower(trim((string) ($evidence['evidence_hash'] ?? $evidence['operator_supplied_evidence_hash'] ?? '')));
        $computedEvidenceHash = self::canonicalCompletionEvidenceHash($evidence);
        $expectedTaskPacketId = (string) ($expectedBinding['task_packet_id'] ?? '');
        $expectedLeaseId = (string) ($expectedBinding['lease_id'] ?? '');
        $expectedAgentId = (string) ($expectedBinding['agent_id'] ?? '');
        $allowedFiles = AtlasLoopRefillerPayloadNormalizer::stringList((array) ($expectedBinding['allowed_files'] ?? []));
        $evidenceTaskPacketId = trim((string) ($evidence['packet_id'] ?? $evidence['task_packet_id'] ?? ''));
        $evidenceLeaseId = trim((string) ($evidence['lease_id'] ?? ''));
        $evidenceActor = trim((string) ($evidence['actor'] ?? $evidence['agent_id'] ?? ''));
        $blockers = [];
        $requiredFields = [
            'packet_id',
            'lease_id',
            'evidence_hash',
            'files_changed',
            'commands_run',
            'tests_or_gates_result',
            'git_status_short',
            'git_diff_check_result',
        ];
        $providedFields = array_values(array_intersect($requiredFields, array_keys($evidence)));
        $missingFields = [];
        if ($evidenceHash === '') {
            $blockers[] = 'evidence_hash_missing';
            $missingFields[] = 'evidence_hash';
        } elseif (preg_match('/^[a-f0-9]{64}$/', $evidenceHash) !== 1) {
            $blockers[] = 'evidence_hash_invalid';
        } elseif (! hash_equals($computedEvidenceHash, $evidenceHash)) {
            $blockers[] = 'evidence_hash_mismatch';
        }
        if ($evidenceTaskPacketId === '') {
            $blockers[] = 'packet_id_missing';
            $missingFields[] = 'packet_id';
        } elseif ($expectedTaskPacketId !== '' && $evidenceTaskPacketId !== $expectedTaskPacketId) {
            $blockers[] = 'packet_id_mismatch';
        }
        if ($evidenceLeaseId === '') {
            $blockers[] = 'lease_id_missing';
            $missingFields[] = 'lease_id';
        } elseif ($expectedLeaseId !== '' && $evidenceLeaseId !== $expectedLeaseId) {
            $blockers[] = 'lease_id_mismatch';
        }
        if ($evidenceActor !== '' && $expectedAgentId !== '' && $evidenceActor !== $expectedAgentId) {
            $blockers[] = 'actor_mismatch';
        }

        $testsOrGates = strtolower(trim((string) ($evidence['tests_or_gates_result'] ?? '')));
        if ($testsOrGates !== '' && ! in_array($testsOrGates, ['pass', 'passed', 'green'], true)) {
            $blockers[] = 'tests_or_gates_result_not_passing';
        }
        if ($testsOrGates === '') {
            $blockers[] = 'tests_or_gates_result_missing';
            $missingFields[] = 'tests_or_gates_result';
        }

        $filesChanged = AtlasLoopRefillerPayloadNormalizer::stringList((array) ($evidence['files_changed'] ?? []));
        if ($filesChanged === []) {
            $blockers[] = 'files_changed_missing';
            $missingFields[] = 'files_changed';
        }
        $filesChangedOutsideAllowedScope = $allowedFiles === []
            ? []
            : array_values(array_diff($filesChanged, $allowedFiles));
        if ($filesChanged !== [] && $allowedFiles === []) {
            $blockers[] = 'allowed_files_missing_for_completion_scope_check';
        } elseif ($filesChangedOutsideAllowedScope !== []) {
            $blockers[] = 'files_changed_outside_allowed_scope';
        }
        $commandsRun = AtlasLoopRefillerPayloadNormalizer::stringList((array) ($evidence['commands_run'] ?? []));
        if ($commandsRun === []) {
            $blockers[] = 'commands_run_missing';
            $missingFields[] = 'commands_run';
        }
        $requiredCommands = AtlasLoopRefillerPayloadNormalizer::stringList((array) ($expectedBinding['required_commands'] ?? []));
        $missingRequiredCommands = $requiredCommands !== []
            ? array_values(array_diff($requiredCommands, $commandsRun))
            : [];
        if ($missingRequiredCommands !== []) {
            $blockers[] = 'required_command_not_run';
        }
        $requiredEvidenceLabels = AtlasLoopRefillerPayloadNormalizer::stringList((array) ($expectedBinding['required_evidence'] ?? []));
        $missingRequiredEvidence = [];
        foreach ($requiredEvidenceLabels as $label) {
            if (trim((string) ($evidence[$label] ?? '')) === '') {
                $missingRequiredEvidence[] = $label;
            }
        }
        if ($missingRequiredEvidence !== []) {
            $blockers[] = 'required_evidence_missing';
        }
        $gitStatusShort = trim((string) ($evidence['git_status_short'] ?? ''));
        if ($gitStatusShort === '') {
            $blockers[] = 'git_status_short_missing';
            $missingFields[] = 'git_status_short';
        }
        $diffCheckResult = strtolower(trim((string) ($evidence['git_diff_check_result'] ?? '')));
        if ($diffCheckResult === '') {
            $blockers[] = 'git_diff_check_result_missing';
            $missingFields[] = 'git_diff_check_result';
        } elseif (! in_array($diffCheckResult, ['clean', 'passed', 'pass', 'ok'], true)) {
            $blockers[] = 'git_diff_check_result_not_clean';
        }

        $missingFields = array_values(array_unique($missingFields));
        $blockers = array_values(array_unique($blockers));
        $structuredCompletionEvidenceValid = $missingFields === []
            && $evidenceHash !== ''
            && preg_match('/^[a-f0-9]{64}$/', $evidenceHash) === 1
            && hash_equals($computedEvidenceHash, $evidenceHash)
            && $evidenceTaskPacketId !== ''
            && ($expectedTaskPacketId === '' || $evidenceTaskPacketId === $expectedTaskPacketId)
            && $evidenceLeaseId !== ''
            && ($expectedLeaseId === '' || $evidenceLeaseId === $expectedLeaseId)
            && ($evidenceActor === '' || $expectedAgentId === '' || $evidenceActor === $expectedAgentId)
            && in_array($testsOrGates, ['pass', 'passed', 'green'], true)
            && $filesChanged !== []
            && $allowedFiles !== []
            && $filesChangedOutsideAllowedScope === []
            && $commandsRun !== []
            && $missingRequiredCommands === []
            && $missingRequiredEvidence === []
            && $gitStatusShort !== ''
            && in_array($diffCheckResult, ['clean', 'passed', 'pass', 'ok'], true);

        $validation = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_task_queue_completion_evidence_validation.v1',
            'status' => $blockers === [] ? 'valid' : 'blocked',
            'structured_completion_evidence_required' => true,
            'structured_completion_evidence_valid' => $structuredCompletionEvidenceValid,
            'required_fields' => $requiredFields,
            'provided_fields' => $providedFields,
            'missing_fields' => $missingFields,
            'missing_field_count' => count($missingFields),
            'expected_binding' => [
                'task_packet_id' => $expectedTaskPacketId,
                'lease_id' => $expectedLeaseId,
                'agent_id' => $expectedAgentId,
                'allowed_files' => $allowedFiles,
            ],
            'evidence_binding' => [
                'task_packet_id' => $evidenceTaskPacketId,
                'lease_id' => $evidenceLeaseId,
                'actor' => $evidenceActor,
            ],
            'packet_id_matches' => $evidenceTaskPacketId !== '' && ($expectedTaskPacketId === '' || $evidenceTaskPacketId === $expectedTaskPacketId),
            'lease_id_matches' => $evidenceLeaseId !== '' && ($expectedLeaseId === '' || $evidenceLeaseId === $expectedLeaseId),
            'actor_matches' => $evidenceActor === '' || $expectedAgentId === '' || $evidenceActor === $expectedAgentId,
            'evidence_hash' => $evidenceHash,
            'computed_evidence_hash' => $computedEvidenceHash,
            'evidence_hash_present' => $evidenceHash !== '',
            'evidence_hash_valid' => $evidenceHash !== '' && preg_match('/^[a-f0-9]{64}$/', $evidenceHash) === 1,
            'evidence_hash_matches_payload' => $evidenceHash !== ''
                && preg_match('/^[a-f0-9]{64}$/', $evidenceHash) === 1
                && hash_equals($computedEvidenceHash, $evidenceHash),
            'evidence_hash_algorithm' => 'sha256(canonical_json(completion_evidence_without_evidence_hash_fields))',
            'files_changed_count' => count($filesChanged),
            'files_changed' => $filesChanged,
            'allowed_files_count' => count($allowedFiles),
            'files_changed_within_allowed_scope' => $filesChanged !== [] && $allowedFiles !== [] && $filesChangedOutsideAllowedScope === [],
            'files_changed_outside_allowed_scope' => $filesChangedOutsideAllowedScope,
            'commands_run_count' => count($commandsRun),
            'required_commands' => $requiredCommands,
            'missing_required_commands' => $missingRequiredCommands,
            'required_evidence_labels' => $requiredEvidenceLabels,
            'missing_required_evidence_labels' => $missingRequiredEvidence,
            'tests_or_gates_result' => $testsOrGates,
            'tests_or_gates_passing' => in_array($testsOrGates, ['pass', 'passed', 'green'], true),
            'git_status_short_present' => $gitStatusShort !== '',
            'git_diff_check_result' => $diffCheckResult,
            'git_diff_check_clean' => in_array($diffCheckResult, ['clean', 'passed', 'pass', 'ok'], true),
            'blocker_count' => count($blockers),
            'blockers' => $blockers,
            'completion_real_allowed' => false,
        ];
        $validation['evidence_validation_hash'] = hash('sha256', (string) json_encode($validation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $validation;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public static function canonicalCompletionEvidenceHash(array $evidence): string
    {
        $normalized = self::normalizeEvidenceForHash($evidence);

        return hash('sha256', (string) json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function normalizeEvidenceForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        unset($value['evidence_hash'], $value['operator_supplied_evidence_hash']);

        foreach ($value as $key => $nested) {
            $value[$key] = self::normalizeEvidenceForHash($nested);
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
