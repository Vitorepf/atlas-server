<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Support\ReadinessJsonInput;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves the terminal-loop operational-proof payload from various input
 * sources (explicit array, JSON option string, or canonical operator
 * submission file).
 *
 * Extracted from AtlasSelfConstructionReadinessService to reduce the god-class.
 * The static methods replace the private helpers and have no $this state.
 */
final class ReadinessTerminalLoopProofResolver
{
    /**
     * Extract the proof payload from a decoded JSON structure, trying several
     * known dot-notation paths before falling back to the root array.
     *
     * @return array<string, mixed>
     */
    public static function payloadFromJson(mixed $proof): array
    {
        if (! is_array($proof)) {
            return [];
        }

        foreach ([
            'proof_payload',
            'completion_audit_binding_packet.proof_payload',
            'agent_control_plane_terminal_loop_operational_proof.completion_audit_binding_packet.proof_payload',
        ] as $path) {
            $payload = data_get($proof, $path);
            if (is_array($payload)) {
                return (array) $payload;
            }
        }

        return (array) $proof;
    }

    /**
     * Return a deterministic resolution audit without mutating options. When more than one
     * proof source is simultaneously supplied, the SAME precedence (explicit array > json
     * option > canonical file) resolves which source wins, but source_ambiguity=true and a
     * named blocker surface the ambiguity instead of silently picking one — an autonomous
     * promotion decision must never rely on undocumented tie-breaking.
     *
     * $policy['max_age_seconds'], when supplied, blocks a proof payload whose own
     * generated_at is older than that ceiling: freshness_status becomes 'stale' and a named
     * blocker is added. Without a policy (or without a generated_at in the payload),
     * freshness_status is 'unknown' — this never blocks (freshness is opt-in via policy).
     *
     * INVARIANT: an empty, stale, or ambiguous proof payload never reports payload_present=true
     * without a blocker naming the specific weakness.
     *
     * @param  array<string, mixed>  $options
     * @param  array{max_age_seconds?: int}  $policy
     * @return array{source: string, canonical_path: string, payload_present: bool, blockers: list<string>, freshness_status: string, source_ambiguity: bool}
     */
    public static function resolveAudit(array $options, string $canonicalPath, array $policy = []): array
    {
        $suppliedSources = [];
        if (array_key_exists('agent_control_plane_terminal_loop_operational_proof', $options)) {
            $suppliedSources[] = 'explicit_array';
        }
        if (isset($options['agent_control_plane_terminal_loop_operational_proof_json'])) {
            $suppliedSources[] = 'json_option';
        }
        if (Storage::disk('local')->exists($canonicalPath)) {
            $suppliedSources[] = 'canonical_file';
        }
        $sourceAmbiguity = count($suppliedSources) > 1;

        if (in_array('explicit_array', $suppliedSources, true)) {
            $payload = (array) $options['agent_control_plane_terminal_loop_operational_proof'];
            $source = 'explicit_array';
            $canonical = '';
            $emptyBlocker = 'explicit_array_proof_is_empty';
        } elseif (in_array('json_option', $suppliedSources, true)) {
            $proof = ReadinessJsonInput::decodeOption($options['agent_control_plane_terminal_loop_operational_proof_json']);
            $payload = self::payloadFromJson($proof);
            $source = 'json_option';
            $canonical = '';
            $emptyBlocker = 'json_option_payload_empty_or_invalid';
        } elseif (in_array('canonical_file', $suppliedSources, true)) {
            $proof = ReadinessJsonInput::decodeOption('@storage/app/private/'.$canonicalPath);
            $payload = self::payloadFromJson($proof);
            $source = 'canonical_file';
            $canonical = 'storage/app/private/'.$canonicalPath;
            $emptyBlocker = 'canonical_file_payload_empty';
        } else {
            return [
                'source' => 'missing',
                'canonical_path' => '',
                'payload_present' => false,
                'blockers' => ['no_proof_source_available'],
                'freshness_status' => 'unknown',
                'source_ambiguity' => false,
            ];
        }

        $payloadPresent = $payload !== [];
        $blockers = $payloadPresent ? [] : [$emptyBlocker];

        $freshnessStatus = 'unknown';
        if ($payloadPresent) {
            $maxAgeSeconds = array_key_exists('max_age_seconds', $policy) ? (int) $policy['max_age_seconds'] : null;
            $generatedAt = $payload['generated_at'] ?? null;
            if ($generatedAt !== null && $maxAgeSeconds !== null) {
                $timestamp = is_numeric($generatedAt) ? (int) $generatedAt : strtotime((string) $generatedAt);
                if ($timestamp !== false) {
                    $freshnessStatus = (time() - $timestamp) > $maxAgeSeconds ? 'stale' : 'fresh';
                    if ($freshnessStatus === 'stale') {
                        $blockers[] = 'stale_proof_payload';
                    }
                }
            }
        }

        if ($sourceAmbiguity) {
            $blockers[] = 'multiple_proof_sources_supplied:'.implode(',', $suppliedSources);
        }

        return [
            'source' => $source,
            'canonical_path' => $canonical,
            'payload_present' => $payloadPresent,
            'blockers' => $blockers,
            'freshness_status' => $freshnessStatus,
            'source_ambiguity' => $sourceAmbiguity,
        ];
    }

    /**
     * Enrich the options array with the terminal-loop operational proof
     * payload, trying explicit array → JSON string → canonical file.
     *
     * @param  array<string, mixed>  $options
     * @param  string  $canonicalPath  Storage-relative path for the canonical operator submission
     * @return array<string, mixed>
     */
    public static function withPayload(array $options, string $canonicalPath): array
    {
        if (isset($options['agent_control_plane_terminal_loop_operational_proof'])) {
            $options['agent_control_plane_terminal_loop_operational_proof_source'] ??= 'explicit_array';

            return $options;
        }

        if (isset($options['agent_control_plane_terminal_loop_operational_proof_json'])) {
            $proof = ReadinessJsonInput::decodeOption($options['agent_control_plane_terminal_loop_operational_proof_json']);
            $options['agent_control_plane_terminal_loop_operational_proof'] = self::payloadFromJson($proof);
            $options['agent_control_plane_terminal_loop_operational_proof_source'] = 'json_option';

            return $options;
        }

        if (! Storage::disk('local')->exists($canonicalPath)) {
            return $options;
        }

        $proof = ReadinessJsonInput::decodeOption('@storage/app/private/'.$canonicalPath);
        $payload = self::payloadFromJson($proof);
        if ($payload !== []) {
            $options['agent_control_plane_terminal_loop_operational_proof'] = $payload;
            $options['agent_control_plane_terminal_loop_operational_proof_source'] = 'canonical_operator_submission';
            $options['agent_control_plane_terminal_loop_operational_proof_canonical_path'] = 'storage/app/private/'.$canonicalPath;
        }

        return $options;
    }

    /**
     * Resolve all five required terminal-loop proofs into a single readiness
     * verdict. Each proof fact is classified as ready, missing, stale, or
     * contradictory.
     *
     * ready:         the fact exists, status=passed, evidence hash is a valid
     *                sha256, and (when max_age_seconds is in policy) the
     *                generated_at timestamp is within the freshness window.
     * missing:       the fact is absent (null) or an empty array.
     * stale:         the fact would be ready except generated_at is older than
     *                max_age_seconds.
     * contradictory: the fact exists but status is not 'passed', or status is
     *                'passed' without a valid sha256 evidence hash — the proof
     *                claims readiness but cannot be trusted.
     *
     * @param  array<string, array<string, mixed>|null>  $proofFacts  proof_type_key => {status?, hash?, generated_at?} | null
     * @param  array{max_age_seconds?: int}  $policy
     * @return array{proof_refs: array<string,string>, blockers: list<string>, ready: bool, next_proof_action: string}
     */
    public static function resolve(array $proofFacts, array $policy = []): array
    {
        $requiredProofTypes = ['launch', 'replenishment', 'evidence', 'lane_isolation', 'cycle_supervisor'];
        $proofRefs = [];
        $blockers = [];
        $nextAction = '';
        $actionSet = false;

        foreach ($requiredProofTypes as $type) {
            $key = $type.'_proof';
            $fact = $proofFacts[$key] ?? null;
            $state = self::resolveSingleProof($type, is_array($fact) ? $fact : null, $policy);
            $proofRefs[$key] = $state['state'];

            foreach ($state['blockers'] as $blocker) {
                $blockers[] = $blocker;
            }
            if (! $actionSet && $state['next_proof_action'] !== '') {
                $nextAction = $state['next_proof_action'];
                $actionSet = true;
            }
        }

        return [
            'proof_refs' => $proofRefs,
            'blockers' => $blockers,
            'ready' => $blockers === [],
            'next_proof_action' => $nextAction,
        ];
    }

    /**
     * Classify a single proof fact into ready, missing, stale, or contradictory.
     *
     * @param  string  $type  bare type name (e.g. 'launch')
     * @param  array{status?: string, hash?: string, generated_at?: mixed}|null  $fact
     * @param  array{max_age_seconds?: int}  $policy
     * @return array{state: string, blockers: list<string>, next_proof_action: string}
     */
    private static function resolveSingleProof(string $type, ?array $fact, array $policy): array
    {
        if ($fact === null || $fact === []) {
            return [
                'state' => 'missing',
                'blockers' => ["missing_{$type}_proof"],
                'next_proof_action' => "provide_{$type}_proof",
            ];
        }

        $status = (string) ($fact['status'] ?? '');
        $hash = (string) ($fact['hash'] ?? '');
        $generatedAt = $fact['generated_at'] ?? null;

        // Contradictory: status is a non-'passed' value (blocked, failed, etc.)
        if ($status !== '' && $status !== 'passed') {
            return [
                'state' => 'contradictory',
                'blockers' => ["{$type}_proof_status_{$status}"],
                'next_proof_action' => "investigate_{$type}_proof_{$status}",
            ];
        }

        // Contradictory: status is 'passed' but evidence hash missing or invalid
        if ($status === 'passed' && ! preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return [
                'state' => 'contradictory',
                'blockers' => ["{$type}_proof_hash_invalid_or_empty"],
                'next_proof_action' => "investigate_{$type}_proof_hash_inconsistency",
            ];
        }

        // Contradictory: no status at all (fact exists but malformed)
        if ($status === '') {
            return [
                'state' => 'contradictory',
                'blockers' => ["{$type}_proof_missing_status"],
                'next_proof_action' => "investigate_{$type}_proof_malformed",
            ];
        }

        // Stale: generated_at is older than max_age_seconds
        $maxAgeSeconds = array_key_exists('max_age_seconds', $policy) ? (int) $policy['max_age_seconds'] : null;
        if ($maxAgeSeconds !== null && $generatedAt !== null) {
            $timestamp = is_numeric($generatedAt) ? (int) $generatedAt : strtotime((string) $generatedAt);
            if ($timestamp !== false && (time() - $timestamp) > $maxAgeSeconds) {
                return [
                    'state' => 'stale',
                    'blockers' => ["{$type}_proof_stale"],
                    'next_proof_action' => "refresh_{$type}_proof",
                ];
            }
        }

        // Ready: status=passed, valid hash, (optional) fresh
        return [
            'state' => 'ready',
            'blockers' => [],
            'next_proof_action' => '',
        ];
    }
}
