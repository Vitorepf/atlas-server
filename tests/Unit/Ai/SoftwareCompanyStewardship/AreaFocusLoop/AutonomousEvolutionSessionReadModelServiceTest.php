<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionReadModelService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * AP-786 · focused same-name coverage for the autonomous session read model.
 *
 * Proves recorded cycle receipts project read-only without provider, branch or
 * merge side effects.
 */
final class AutonomousEvolutionSessionReadModelServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap786_read_model_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): AutonomousEvolutionSessionReadModelService
    {
        $service = app(AutonomousEvolutionSessionReadModelService::class);
        $service->setStorageRootForTesting($this->tmp);

        return $service;
    }

    /**
     * @param  list<array<string,mixed>>  $cycles
     */
    private function writeSession(string $sessionId, array $cycles, string $recordedAt): void
    {
        $path = $this->tmp.'/agentic_engineering_os.jsonl';
        File::ensureDirectoryExists(dirname($path));
        File::append($path, json_encode([
            'session_id' => $sessionId,
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'status' => 'completed',
            'cycles' => $cycles,
            'generated_at' => $recordedAt,
        ], JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    public function test_list_sessions_returns_most_recent_window_oldest_first(): void
    {
        $this->writeSession('s1', [['cycle_id' => 'c1', 'final_status' => 'cycle_completed']], '2026-05-27T10:00:00+00:00');
        $this->writeSession('s2', [['cycle_id' => 'c2', 'final_status' => 'cycle_completed']], '2026-05-27T11:00:00+00:00');
        $this->writeSession('s3', [['cycle_id' => 'c3', 'final_status' => 'cycle_completed']], '2026-05-27T12:00:00+00:00');

        $sessions = $this->service()->listSessions('agentic_engineering_os', 2);

        $this->assertCount(2, $sessions);
        $this->assertSame('s2', $sessions[0]['session_id']);
        $this->assertSame('s3', $sessions[1]['session_id']);
    }

    public function test_project_flattens_cycle_receipts_without_runtime_side_effects(): void
    {
        $this->writeSession('aes_read_model_1', [[
            'cycle_id' => 'c_read_only',
            'final_status' => 'cycle_completed_waiting_review_or_merge',
            'merge_performed' => false,
            'owner' => 'atlas_dev',
            'blockers' => [],
            'selected_finding' => ['finding_id' => 'find_ro', 'title' => 'Read-only projection'],
        ]], '2026-05-27T12:00:00+00:00');

        $path = $this->tmp.'/agentic_engineering_os.jsonl';
        $before = file_get_contents($path);

        $payload = $this->service()->project('agentic_engineering_os', 5);

        $this->assertSame($before, file_get_contents($path));
        $this->assertSame(AutonomousEvolutionSessionReadModelService::SCHEMA, $payload['schema_version']);
        $this->assertTrue($payload['read_only']);
        $this->assertSame(1, $payload['session_count']);
        $this->assertSame(1, $payload['cycles_total']);
        $this->assertCount(1, $payload['cycle_receipts']);
        $this->assertSame('c_read_only', $payload['cycle_receipts'][0]['cycle_id']);
        $this->assertSame('aes_read_model_1', $payload['cycle_receipts'][0]['_session_id']);
        $this->assertSame('agentic_engineering_os', $payload['cycle_receipts'][0]['_area_id']);
        $this->assertSame('dev_forge', $payload['cycle_receipts'][0]['_focus']);

        $policy = $payload['claim_policy'];
        $this->assertTrue($policy['read_only']);
        $this->assertFalse($policy['invokes_provider']);
        $this->assertFalse($policy['mutates_repo']);
        $this->assertFalse($policy['materializes_branch']);
        $this->assertFalse($policy['performs_merge']);
        $this->assertTrue($policy['no_test_doubles_at_runtime']);
    }

    public function test_cycle_inbox_summaries_skip_dry_run_planned_cycles(): void
    {
        $cycles = [
            [
                'cycle_id' => 'c_dry',
                'final_status' => 'dry_run_planned',
                'selected_finding' => ['finding_id' => 'find_dry', 'title' => 'Dry run'],
            ],
            [
                'cycle_id' => 'c_real',
                'final_status' => 'blocked',
                'blockers' => ['validation_failed'],
                'selected_finding' => ['finding_id' => 'find_real', 'title' => 'Real cycle'],
                'inbox_item_id' => 'inbox_real',
            ],
        ];

        $summaries = $this->service()->cycleInboxSummaries($cycles);

        $this->assertCount(1, $summaries);
        $this->assertSame('Real cycle', $summaries[0]['achado']);
    }

    public function test_project24h_observability_preserves_default_focus_when_slug_collapses(): void
    {
        $payload = $this->service()->project24hObservability([
            'area_id' => 'agentic_engineering_os',
            'focus' => '___',
            'backlog_snapshot' => ['status' => 'ready', 'available_count' => 0, 'finding_count' => 0],
        ]);

        $this->assertSame(AutonomousEvolutionSessionReadModelService::OBSERVABILITY_SCHEMA, $payload['schema_version']);
        $this->assertSame('dev_forge', $payload['focus']);
        $this->assertStringStartsWith('sha256:', (string) ($payload['observability_hash'] ?? ''));
    }
}
