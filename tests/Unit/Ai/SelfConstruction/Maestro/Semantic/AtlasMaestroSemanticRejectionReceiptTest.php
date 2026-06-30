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
            'respec_suggestion',
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

    public function test_unresolved_symbol_is_captured_in_offending_symbol_field(): void
    {
        $panel = $this->panelResult();
        $panel['offending_symbol'] = 'UnresolvedServiceClass';
        $decoded = json_decode($this->composer()->compose('pkt-u', $panel), true);

        $this->assertSame('UnresolvedServiceClass', $decoded['offending_symbol']);
    }

    public function test_wrong_sibling_is_captured_in_sibling_observed_field(): void
    {
        $panel = $this->panelResult();
        $panel['sibling_observed'] = 'WrongSiblingClass';
        $decoded = json_decode($this->composer()->compose('pkt-s', $panel), true);

        $this->assertSame('WrongSiblingClass', $decoded['sibling_observed']);
    }

    public function test_orphan_caller_is_captured_in_rejected_voters(): void
    {
        $panel = $this->panelResult();
        $panel['voter_reasons']['orphan_caller'] = 'caller_has_no_consumer';
        $decoded = json_decode($this->composer()->compose('pkt-o', $panel), true);

        $this->assertArrayHasKey('orphan_caller', $decoded['rejected_voters']);
        $this->assertSame('caller_has_no_consumer', $decoded['rejected_voters']['orphan_caller']);
    }

    public function test_allowed_files_mismatch_is_captured_in_rejected_voters(): void
    {
        $panel = $this->panelResult();
        $panel['voter_reasons']['allowed_files_intent'] = 'file_outside_scope';
        $decoded = json_decode($this->composer()->compose('pkt-af', $panel), true);

        $this->assertSame('file_outside_scope', $decoded['rejected_voters']['allowed_files_intent']);
    }

    public function test_respec_suggestion_derived_from_rejected_voters(): void
    {
        // allowed_files_intent rejected only
        $panel = array_merge($this->panelResult(), ['voter_reasons' => ['allowed_files_intent' => 'x'], 'votes' => [false, true, true]]);
        $d = json_decode($this->composer()->compose('pkt-r', $panel), true);
        $this->assertStringContainsString('widen_allowed_files', $d['respec_suggestion']);

        // both rejected
        $panel2 = $this->panelResult(); // both allowed_files_intent and orphan_caller rejected
        $d2 = json_decode($this->composer()->compose('pkt-r2', $panel2), true);
        $this->assertStringContainsString('widen_allowed_files', $d2['respec_suggestion']);
        $this->assertStringContainsString('fix_wiring', $d2['respec_suggestion']);
    }

    public function test_stable_receipt_hash_same_inputs_yield_identical_json(): void
    {
        $a = $this->composer()->compose('pkt-hash', $this->panelResult());
        $b = $this->composer()->compose('pkt-hash', $this->panelResult());
        $this->assertSame($a, $b);
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
