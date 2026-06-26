<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Readiness\ReadinessTerminalLoopProofResolver;
use Tests\TestCase;

class ReadinessTerminalLoopProofResolverTest extends TestCase
{
    public function test_payload_from_json_returns_empty_for_non_array(): void
    {
        self::assertSame([], ReadinessTerminalLoopProofResolver::payloadFromJson(null));
        self::assertSame([], ReadinessTerminalLoopProofResolver::payloadFromJson('string'));
        self::assertSame([], ReadinessTerminalLoopProofResolver::payloadFromJson(42));
    }

    public function test_payload_from_json_extracts_root_proof_payload(): void
    {
        $proof = ['proof_payload' => ['key' => 'value']];

        self::assertSame(['key' => 'value'], ReadinessTerminalLoopProofResolver::payloadFromJson($proof));
    }

    public function test_payload_from_json_extracts_nested_completion_audit_path(): void
    {
        $proof = [
            'completion_audit_binding_packet' => [
                'proof_payload' => ['nested' => true],
            ],
        ];

        self::assertSame(['nested' => true], ReadinessTerminalLoopProofResolver::payloadFromJson($proof));
    }

    public function test_payload_from_json_extracts_deep_agent_control_plane_path(): void
    {
        $proof = [
            'agent_control_plane_terminal_loop_operational_proof' => [
                'completion_audit_binding_packet' => [
                    'proof_payload' => ['deep' => 'yes'],
                ],
            ],
        ];

        self::assertSame(['deep' => 'yes'], ReadinessTerminalLoopProofResolver::payloadFromJson($proof));
    }

    public function test_payload_from_json_falls_back_to_root_array(): void
    {
        $proof = ['fallback' => 'root'];

        self::assertSame(['fallback' => 'root'], ReadinessTerminalLoopProofResolver::payloadFromJson($proof));
    }

    public function test_payload_from_json_returns_empty_array_for_empty_input(): void
    {
        self::assertSame([], ReadinessTerminalLoopProofResolver::payloadFromJson([]));
    }

    public function test_with_payload_passes_through_when_explicit_payload_exists(): void
    {
        $options = [
            'agent_control_plane_terminal_loop_operational_proof' => ['already' => 'present'],
            'other' => 'data',
        ];

        $result = ReadinessTerminalLoopProofResolver::withPayload($options, 'some/path.json');

        self::assertSame($options, $result);
    }

    public function test_with_payload_resolves_from_json_option_string(): void
    {
        $json = '{"proof_payload": {"from": "json"}}';
        $options = ['agent_control_plane_terminal_loop_operational_proof_json' => $json];

        $result = ReadinessTerminalLoopProofResolver::withPayload($options, 'some/path.json');

        self::assertArrayHasKey('agent_control_plane_terminal_loop_operational_proof', $result);
        self::assertSame(['from' => 'json'], $result['agent_control_plane_terminal_loop_operational_proof']);
    }

    public function test_with_payload_returns_options_unchanged_when_no_source_available(): void
    {
        $options = ['unrelated' => 'value'];

        $result = ReadinessTerminalLoopProofResolver::withPayload($options, 'nonexistent/path-that-does-not-exist.json');

        self::assertArrayNotHasKey('agent_control_plane_terminal_loop_operational_proof', $result);
        self::assertSame($options, $result);
    }

    public function test_with_payload_loads_from_canonical_file_when_present(): void
    {
        $canonicalPath = 'atlas/self-construction/operator-submissions/test-terminal-loop-proof.json';
        $absolutePath = storage_path('app/private/'.$canonicalPath);
        @mkdir(dirname($absolutePath), 0777, true);
        file_put_contents($absolutePath, json_encode([
            'proof_payload' => ['canonical' => true],
        ]));

        try {
            $options = [];
            $result = ReadinessTerminalLoopProofResolver::withPayload($options, $canonicalPath);

            self::assertArrayHasKey('agent_control_plane_terminal_loop_operational_proof', $result);
            self::assertSame(['canonical' => true], $result['agent_control_plane_terminal_loop_operational_proof']);
            self::assertSame('canonical_operator_submission', $result['agent_control_plane_terminal_loop_operational_proof_source']);
            self::assertStringContainsString($canonicalPath, $result['agent_control_plane_terminal_loop_operational_proof_canonical_path']);
        } finally {
            @unlink($absolutePath);
        }
    }

    public function test_with_payload_does_not_overwrite_when_canonical_payload_empty(): void
    {
        $canonicalPath = 'atlas/self-construction/operator-submissions/test-empty-proof.json';
        $absolutePath = storage_path('app/private/'.$canonicalPath);
        @mkdir(dirname($absolutePath), 0777, true);
        file_put_contents($absolutePath, json_encode([]));

        try {
            $options = [];
            $result = ReadinessTerminalLoopProofResolver::withPayload($options, $canonicalPath);

            self::assertArrayNotHasKey('agent_control_plane_terminal_loop_operational_proof', $result);
        } finally {
            @unlink($absolutePath);
        }
    }
}
