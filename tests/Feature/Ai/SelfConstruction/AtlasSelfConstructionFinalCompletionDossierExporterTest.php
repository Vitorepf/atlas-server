<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionFinalCompletionDossierExporterService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionFinalCompletionHumanGateService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReservationRepository;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionFinalCompletionDossierExporterTest extends TestCase
{
    public function test_exporter_generates_machine_json_and_markdown_without_persisting_by_default(): void
    {
        Storage::fake('local');

        $payload = $this->exporter()->build($this->humanGateOptions(blockedRuntime: true));

        $this->assertSame('atlas.self_construction.final_completion_dossier_exporter.v1', $payload['schema_version']);
        $this->assertSame('export_ready', $payload['status']);
        $this->assertFalse((bool) $payload['export_persisted']);
        $this->assertSame('', (string) $payload['export_path']);
        $this->assertIsArray((array) $payload['machine_json']);
        $this->assertGreaterThan(0, (int) $payload['markdown_byte_size']);
        $this->assertStringContainsString('# Atlas Self-Construction · Final Completion Dossier', (string) $payload['markdown']);
        $this->assertFalse((bool) $payload['completion_claim_allowed']);
        Storage::disk('local')->assertMissing(AtlasSelfConstructionFinalCompletionDossierExporterService::STORAGE_PREFIX.'/registry.json');
    }

    public function test_exporter_lists_failed_blockers_and_next_commands(): void
    {
        $payload = $this->exporter()->build($this->humanGateOptions(blockedRuntime: true));

        $blockers = (array) $payload['failed_blockers'];
        $nextCommands = (array) $payload['next_commands'];

        $this->assertContains('runtime_gap_matrix_all_runtime_y', $blockers);
        $this->assertContains('human_signed_os_complete_receipt_present', $blockers);
        $this->assertNotEmpty($nextCommands);
        $this->assertStringContainsString('atlas:ai:self-construction', $nextCommands[0]);
        $this->assertTrue((bool) collect($nextCommands)->contains(
            static fn (string $command): bool => str_contains($command, 'terminal-loop-operational-proof-status'),
        ));
        $this->assertTrue((bool) collect($nextCommands)->contains(
            static fn (string $command): bool => str_contains($command, '--agent-control-plane-terminal-loop-operational-proof-json='),
        ));
    }

    public function test_exporter_persists_dossier_when_persist_export_true_but_never_persists_receipts(): void
    {
        Storage::fake('local');

        $options = $this->humanGateOptions(blockedRuntime: true) + ['persist_export' => true];
        $payload = $this->exporter()->build($options);

        $this->assertSame('export_persisted', $payload['status']);
        $this->assertTrue((bool) $payload['export_persisted']);
        $this->assertNotSame('', (string) $payload['export_path']);
        Storage::disk('local')->assertExists((string) $payload['export_path'].'.json');
        Storage::disk('local')->assertExists((string) $payload['export_path'].'.md');
        Storage::disk('local')->assertExists(AtlasSelfConstructionFinalCompletionDossierExporterService::STORAGE_PREFIX.'/registry.json');
        Storage::disk('local')->assertMissing('atlas/self-construction/os-completion/human-signed-receipts/registry.json');
    }

    public function test_exporter_evidence_map_includes_canonical_hashes(): void
    {
        $payload = $this->exporter()->build($this->humanGateOptions(blockedRuntime: true));
        $map = (array) $payload['evidence_map'];

        $this->assertArrayHasKey('completion_audit_hash', $map);
        $this->assertArrayHasKey('final_completion_human_gate_hash', $map);
        $this->assertArrayHasKey('submission_preflight_hash', $map);
        $this->assertArrayHasKey('human_completion_receipt_dossier_hash', $map);
        $this->assertArrayHasKey('human_completion_receipt_endgame_verifier_hash', $map);
    }

    public function test_exporter_payload_is_json_serializable_and_hash_is_deterministic(): void
    {
        $options = $this->humanGateOptions(blockedRuntime: true);
        $first = $this->exporter()->build($options);
        $second = $this->exporter()->build($options);

        $encoded = json_encode($first, JSON_THROW_ON_ERROR);
        $this->assertJson($encoded);
        $this->assertSame($first['exporter_hash'], $second['exporter_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $first['exporter_hash']);
    }

    public function test_exporter_does_not_promote_completion_or_call_provider(): void
    {
        $payload = $this->exporter()->build($this->humanGateOptions(blockedRuntime: true));

        $this->assertFalse((bool) $payload['completion_claim_allowed']);
        $this->assertFalse((bool) $payload['execution_allowed']);
        $this->assertFalse((bool) $payload['provider_call_allowed']);
        $this->assertFalse((bool) $payload['token_spend_allowed']);
        $this->assertContains('final_completion_dossier_exporter_does_not_promote_completion', (array) data_get($payload, 'machine_json.non_execution_guarantees', []));
    }

    public function test_status_projection_exposes_blockers_next_commands_and_export_metadata(): void
    {
        $status = (new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository))
            ->atlasSelfConstructionFinalCompletionDossierExporterStatus([
                'final_completion_human_gate' => $this->humanGateOptions(blockedRuntime: true)['final_completion_human_gate'],
            ]);

        $summary = (array) data_get($status, 'agent_control_plane_atlas_self_construction_final_completion_dossier_exporter_status', []);

        $this->assertSame('export_ready', $summary['status']);
        $this->assertSame('incomplete', $summary['final_audit_status']);
        $this->assertFalse((bool) $summary['final_audit_complete']);
        $this->assertContains('runtime_gap_matrix_all_runtime_y', (array) $summary['failed_blockers']);
        $this->assertSame(count((array) $summary['failed_blockers']), $summary['failed_blocker_count']);
        $this->assertGreaterThan(0, $summary['next_command_count']);
        $this->assertSame(count((array) $summary['next_commands']), $summary['next_command_count']);
        $this->assertTrue((bool) collect((array) $summary['next_commands'])->contains(
            static fn (string $command): bool => str_contains($command, 'terminal-loop-operational-proof-status'),
        ));
        $this->assertGreaterThan(0, $summary['markdown_byte_size']);
        $this->assertFalse((bool) $summary['export_persisted']);
        $this->assertSame('', $summary['export_path']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['exporter_hash']);
        $this->assertFalse((bool) $summary['completion_claim_allowed']);
    }

    public function test_status_projection_builds_current_audit_when_no_override_is_supplied(): void
    {
        $status = (new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository))
            ->atlasSelfConstructionFinalCompletionDossierExporterStatus();

        $summary = (array) data_get($status, 'agent_control_plane_atlas_self_construction_final_completion_dossier_exporter_status', []);

        $this->assertSame('export_ready', $summary['status']);
        $this->assertContains($summary['final_audit_status'], ['complete', 'incomplete']);
        $this->assertGreaterThanOrEqual(0, $summary['failed_blocker_count']);
        $this->assertGreaterThan(0, $summary['next_command_count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['exporter_hash']);
        $this->assertFalse((bool) $summary['completion_claim_allowed']);
    }

    private function exporter(): AtlasSelfConstructionFinalCompletionDossierExporterService
    {
        return new AtlasSelfConstructionFinalCompletionDossierExporterService(
            new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository),
        );
    }

    /** @return array<string, mixed> */
    private function humanGateOptions(bool $blockedRuntime): array
    {
        $hash = str_repeat('a', 64);
        $criteria = [
            ['id' => 'runtime_gap_matrix_all_runtime_y', 'passed' => ! $blockedRuntime, 'evidence' => ['runtime_gap_matrix_hash' => $hash, 'runtime_promotion_receipt_hash' => $hash]],
            ['id' => 'release_dossier_green', 'passed' => true, 'evidence' => ['hash' => $hash, 'baseline_snapshot_capture_required' => false]],
            ['id' => 'replay_diff_against_completion_snapshot_green', 'passed' => true, 'evidence' => ['diff_hash' => $hash]],
            ['id' => 'promotion_gate_green', 'passed' => true, 'evidence' => []],
            ['id' => 'mutation_guard_green', 'passed' => true, 'evidence' => []],
            ['id' => 'human_signed_os_complete_receipt_present', 'passed' => false, 'evidence' => []],
            ['id' => 'end_to_end_real_provider_smoke_green', 'passed' => true, 'evidence' => ['smoke_hash' => $hash]],
            ['id' => 'forge_self_improvement_integration_smoke_green', 'passed' => true, 'evidence' => []],
            ['id' => 'certification_status_batch_green', 'passed' => true, 'evidence' => ['hash' => $hash]],
        ];
        $failed = array_values(array_filter(array_map(static fn (array $r): ?string => ($r['passed'] ?? false) === false ? (string) $r['id'] : null, $criteria)));
        $audit = [
            'status' => 'incomplete',
            'completion_audit_hash' => $hash,
            'failed_criteria' => $failed,
            'failed_count' => count($failed),
            'passed_count' => count($criteria) - count($failed),
            'completion_allowed' => false,
            'criteria' => $criteria,
            'operator_action_packet' => [
                'human_completion_receipt_template' => [
                    'runtime_promotion_receipt_hash' => $hash,
                    'real_provider_smoke_hash' => $hash,
                    'certification_status_batch_hash' => $hash,
                ],
            ],
        ];
        $evidence = [
            'runtime_gap_matrix' => [
                'status' => $blockedRuntime ? 'blocked' : 'passed',
                'all_runtime_y' => ! $blockedRuntime,
                'runtime_gap_matrix_hash' => $hash,
                'runtime_promotion_receipt' => ['status' => $blockedRuntime ? 'blocked' : 'passed', 'receipt_hash' => $hash],
            ],
            'real_provider_smoke' => ['status' => 'passed', 'smoke_hash' => $hash],
            'human_signed_completion_receipt' => ['status' => 'blocked_missing_operator_receipt', 'receipt_hash' => ''],
            'operator_action_packet' => ['human_completion_receipt_template' => ['certification_status_batch_hash' => $hash]],
        ];
        $humanGate = (new AtlasSelfConstructionFinalCompletionHumanGateService(
            new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository),
        ))->build([
            'completion_audit' => $audit,
            'completion_evidence' => $evidence,
            'completion_receipt' => [],
        ]);

        return [
            'final_completion_human_gate' => $humanGate,
        ];
    }
}
