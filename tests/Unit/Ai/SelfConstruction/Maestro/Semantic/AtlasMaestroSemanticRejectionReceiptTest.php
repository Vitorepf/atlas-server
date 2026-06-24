<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Semantic;

use App\Services\Ai\SelfConstruction\Maestro\Semantic\AtlasMaestroSemanticRejectionReceipt;
use Tests\TestCase;

final class AtlasMaestroSemanticRejectionReceiptTest extends TestCase
{
    public function test_compose_returns_fact_only_rejection_json_shape(): void
    {
        $json = $this->composer()->compose('packet-1', $this->panelResult());
        $decoded = json_decode($json, true);

        $this->assertSame([
            'defining_file',
            'expected_role_tokens',
            'offending_symbol',
            'packet_id',
            'panel_votes',
            'rejected_voters',
            'sibling_observed',
            'ts_utc',
        ], array_keys($decoded));
        $this->assertSame('packet-1', $decoded['packet_id']);
        $this->assertSame([false, false, true], $decoded['panel_votes']);
        $this->assertSame('symbol_outside_allowed_files', $decoded['rejected_voters']['allowed_files_intent']);
        $this->assertSame('sibling_role_mismatch', $decoded['rejected_voters']['orphan_caller']);
        $this->assertSame('AtlasTaskPacketQualityInspector', $decoded['offending_symbol']);
        $this->assertSame('app/Services/Ai/SelfConstruction/AtlasTaskPacketQualityInspector.php', $decoded['defining_file']);
    }

    public function test_compose_is_byte_deterministic_for_same_inputs(): void
    {
        $composer = $this->composer();

        $this->assertSame(
            $composer->compose('packet-1', $this->panelResult()),
            $composer->compose('packet-1', $this->panelResult()),
        );
    }

    public function test_passed_panel_returns_error_json_instead_of_rejection_receipt(): void
    {
        $decoded = json_decode($this->composer()->compose('packet-2', [
            'pass' => true,
            'votes' => [true, true, true],
        ]), true);

        $this->assertSame('semantic_rejection_receipt_requires_failed_panel', $decoded['error']);
        $this->assertSame('packet-2', $decoded['packet_id']);
    }

    private function composer(): AtlasMaestroSemanticRejectionReceipt
    {
        return new AtlasMaestroSemanticRejectionReceipt;
    }

    /**
     * @return array<string,mixed>
     */
    private function panelResult(): array
    {
        return [
            'pass' => false,
            'votes' => [false, false, true],
            'ts_utc' => '2026-06-24T18:00:00Z',
            'voter_reasons' => [
                'allowed_files_intent' => 'symbol_outside_allowed_files',
                'orphan_caller' => 'sibling_role_mismatch',
            ],
            'offending_symbol' => 'AtlasTaskPacketQualityInspector',
            'defining_file' => 'app/Services/Ai/SelfConstruction/AtlasTaskPacketQualityInspector.php',
            'sibling_observed' => 'AtlasTaskScopedCommitter',
            'expected_role_tokens' => ['audit', 'panel'],
        ];
    }
}
