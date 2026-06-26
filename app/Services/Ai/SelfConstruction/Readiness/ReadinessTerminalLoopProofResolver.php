<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\ReadinessJsonInput;
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
            return $options;
        }

        if (isset($options['agent_control_plane_terminal_loop_operational_proof_json'])) {
            $proof = ReadinessJsonInput::decodeOption($options['agent_control_plane_terminal_loop_operational_proof_json']);
            $options['agent_control_plane_terminal_loop_operational_proof'] = self::payloadFromJson($proof);

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
}
