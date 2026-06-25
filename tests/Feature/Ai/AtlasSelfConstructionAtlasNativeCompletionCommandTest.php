<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasSelfConstructionAtlasNativeCompletionCommandTest extends TestCase
{
    private string $factsPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->factsPath = sys_get_temp_dir().'/atlas_native_completion_'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->factsPath);
        parent::tearDown();
    }

    private function writeJson(array $data): void
    {
        file_put_contents($this->factsPath, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    private function readyEvidenceFacts(array $sourceOverrides = []): array
    {
        $sources = array_replace([
            'task_serving_contract_sentinel' => ['status' => 'pass'],
            'code_index_readiness_bridge' => ['status' => 'pass'],
            'multi_project_governance_dossier' => ['status' => 'pass'],
            'native_worker_readiness' => ['status' => 'pass'],
            'verification_court' => ['status' => 'pass'],
            'merge_governor' => ['status' => 'pass'],
            'rollback' => ['status' => 'pass'],
            'receipts' => ['status' => 'pass'],
            'learning_transfer' => ['status' => 'pass'],
            'docs_health' => ['status' => 'pass'],
            'knowledge_sync' => ['status' => 'pass'],
        ], $sourceOverrides);

        return [
            'final_runtime_owner' => 'atlas_native',
            'steady_state_runtime_owner' => 'atlas_server',
            'autonomy_dependencies' => [
                'depends_on_operator' => false,
                'depends_on_claude_code' => false,
                'depends_on_codex' => false,
                'depends_on_external_provider_network' => false,
            ],
            'sources' => $sources,
        ];
    }

    public function test_verify_ready_when_all_sources_pass(): void
    {
        $this->writeJson(['evidence_facts' => $this->readyEvidenceFacts()]);
        Artisan::call('atlas:self-construction:atlas-native-completion', ['action' => 'verify', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $p['status']);
        $this->assertSame('atlas_native_ready', $p['verify']['status']);
        $this->assertTrue($p['verify']['passed']);
    }

    public function test_gate_returns_hold_when_evidence_missing_refreshable(): void
    {
        $evidence = $this->readyEvidenceFacts();
        unset($evidence['sources']['docs_health']); // refreshable → hold

        $this->writeJson([
            'evidence_facts' => $evidence,
            'dependency_facts' => [
                'final_runtime_owner' => 'atlas_native',
                'paths' => [['id' => 'p1', 'kind' => 'ordinary', 'steady_state_required' => ['atlas_native']]],
            ],
            'autonomy_verdict' => ['level' => 'atlas_native_bounded'],
            'ledger' => [],
        ]);
        Artisan::call('atlas:self-construction:atlas-native-completion', ['action' => 'gate', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $p['status']);
        $this->assertContains($p['gate']['final_state'], ['hold', 'blocked']);
    }

    public function test_dossier_blocked_when_dependency_regression(): void
    {
        $this->writeJson([
            'evidence_facts' => $this->readyEvidenceFacts(),
            'dependency_facts' => [
                'final_runtime_owner' => 'atlas_native',
                'paths' => [
                    ['id' => 'p1', 'kind' => 'ordinary', 'steady_state_required' => ['claude_code']],
                ],
            ],
            'autonomy_verdict' => ['level' => 'atlas_native_bounded'],
            'ledger' => [],
        ]);
        Artisan::call('atlas:self-construction:atlas-native-completion', ['action' => 'dossier', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $p['status']);
        $this->assertSame('blocked', $p['dossier']['final_state']);
    }

    public function test_empty_facts_returns_hold_or_blocked_never_ready(): void
    {
        Artisan::call('atlas:self-construction:atlas-native-completion', ['action' => 'verify', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $p['status']);
        $this->assertNotSame('atlas_native_ready', $p['verify']['status']);
    }

    public function test_invalid_action_yields_unknown_action(): void
    {
        $exit = Artisan::call('atlas:self-construction:atlas-native-completion', ['action' => 'bogus', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('unknown_action', $p['status']);
    }

    public function test_invalid_facts_path_yields_usage_error(): void
    {
        $exit = Artisan::call('atlas:self-construction:atlas-native-completion', ['action' => 'verify', '--facts' => '/does/not/exist.json', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $p['status']);
    }

    public function test_json_output_shape_is_deterministic(): void
    {
        Artisan::call('atlas:self-construction:atlas-native-completion', ['action' => 'verify', '--json' => true]);
        $raw = trim(Artisan::output());
        $decoded = json_decode($raw, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('status', $decoded);
        $this->assertArrayHasKey('verify', $decoded);
        $this->assertSame(json_encode($decoded, JSON_UNESCAPED_SLASHES), json_encode($decoded, JSON_UNESCAPED_SLASHES));
    }

    public function test_verify_source_coverage_ready_includes_mandatory_source_ids(): void
    {
        $this->writeJson(['evidence_facts' => $this->readyEvidenceFacts()]);
        Artisan::call('atlas:self-construction:atlas-native-completion', ['action' => 'verify', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertArrayHasKey('source_coverage', $p);
        $this->assertNotEmpty($p['source_coverage']['mandatory_source_ids']);
        $this->assertSame([], $p['source_coverage']['source_blockers']);
    }

    public function test_gate_hold_due_to_missing_code_index_bridge(): void
    {
        $evidence = $this->readyEvidenceFacts();
        unset($evidence['sources']['code_index_readiness_bridge']); // refreshable

        $this->writeJson([
            'evidence_facts' => $evidence,
            'dependency_facts' => [
                'final_runtime_owner' => 'atlas_native',
                'paths' => [['id' => 'p1', 'kind' => 'ordinary', 'steady_state_required' => ['atlas_native']]],
            ],
            'autonomy_verdict' => ['level' => 'atlas_native_bounded'],
            'ledger' => [],
        ]);
        Artisan::call('atlas:self-construction:atlas-native-completion', ['action' => 'gate', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame('hold', $p['final_state']);
        $this->assertContains('code_index_readiness_bridge', $p['source_coverage']['hold_sources']);
    }

    public function test_dossier_blocked_due_to_unsafe_queue_contract_sentinel_failure(): void
    {
        $this->writeJson([
            'evidence_facts' => $this->readyEvidenceFacts(['task_serving_contract_sentinel' => ['status' => 'fail']]),
            'dependency_facts' => [
                'final_runtime_owner' => 'atlas_native',
                'paths' => [['id' => 'p1', 'kind' => 'ordinary', 'steady_state_required' => ['atlas_native']]],
            ],
            'autonomy_verdict' => ['level' => 'atlas_native_bounded'],
            'ledger' => [],
        ]);
        Artisan::call('atlas:self-construction:atlas-native-completion', ['action' => 'dossier', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame('blocked', $p['final_state']);
        $this->assertContains('task_serving_contract_sentinel', $p['evidence_source_coverage']['blocked_source_ids']);
    }

    public function test_command_source_is_read_only(): void
    {
        $src = (string) file_get_contents(base_path('app/Console/Commands/AtlasSelfConstructionAtlasNativeCompletionCommand.php'));
        foreach (['shell_exec', 'proc_open', 'Queue::push', 'dispatch(', 'fopen', 'fwrite'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "completion command must NOT contain {$forbidden}");
        }
    }
}
