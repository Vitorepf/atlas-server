<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesAgentControlPlaneTaskQueueOrchestrator;
use Tests\TestCase;

/**
 * govA-author-not-judge-servetime-w2 — author≠judge separation at serve time.
 *
 * Workers must NEVER receive a packet that was authored and then immediately served without an
 * independent excellence-grade re-check intervening. The serving service calls the inspector
 * twice on every claimed packet: once to reproduce the minter's self-sufficiency claim, once
 * as an independent serve-time gate. Both share the same BLOCKING_DEFICIENCIES list.
 */
final class AtlasTaskServingAuthorNotJudgeTest extends TestCase
{
    use MakesAgentControlPlaneTaskQueueOrchestrator;
    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-anj-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        if ($this->envFile !== '') {
            @unlink($this->envFile);
        }
        parent::tearDown();
    }

    public function test_serve_time_inspector_is_called_exactly_twice_per_packet(): void
    {
        $source = (string) file_get_contents(
            base_path('app/Services/Ai/SelfConstruction/AtlasTaskServingService.php')
        );
        // The author≠judge contract: exactly two `$this->inspector->inspect(` calls in next().
        $count = substr_count($source, '$this->inspector->inspect(');
        self::assertSame(2, $count, 'expected exactly two inspector calls (self-check + independent re-check)');
    }

    public function test_quarantine_path_is_identical_for_self_check_and_serve_time_failures(): void
    {
        // Both failure paths go through the SAME `quarantineClaimed` call inside the loop.
        $source = (string) file_get_contents(
            base_path('app/Services/Ai/SelfConstruction/AtlasTaskServingService.php')
        );
        $count = substr_count($source, '$this->orchestrator->quarantineClaimed(');
        self::assertSame(1, $count, 'both inspection failures must share one quarantineClaimed call');
    }

    public function test_serve_time_failure_response_mirrors_self_check_failure(): void
    {
        $orch = $this->orchestrator();
        // Deficient: zero acceptance criteria → blocking deficiency 'missing_acceptance_criteria'.
        $this->rawEnqueue($this->input('only-deficient', acceptance: []));

        $serving = new AtlasTaskServingService($orch);
        $res = $serving->next('client-cold');

        self::assertSame('no_self_sufficient_task', $res['status']);
        self::assertSame('needs_brain_origination', $res['escalation']);
        self::assertContains('missing_acceptance_criteria', $res['blocking_deficiencies']);

        $blocked = (new AgentControlPlaneTaskPacketQueueRepository)->get('only-deficient');
        self::assertSame('blocked', (string) ($blocked['status'] ?? ''));
    }

    public function test_excellence_grade_packet_is_served_with_quality_facts(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('excellent')]);

        $serving = new AtlasTaskServingService($orch);
        $res = $serving->next('client-cold');

        self::assertSame('served', $res['status']);
        self::assertSame('excellent', $res['task']['task_packet_id']);
        self::assertTrue($res['task']['packet_quality']['self_sufficient']);
        self::assertSame([], $res['task']['packet_quality']['blocking_deficiencies']);
    }

    public function test_served_packet_carries_known_lessons_matching_its_files(): void
    {
        $ledgerPath = sys_get_temp_dir().'/atlas-anj-lessons-'.bin2hex(random_bytes(5)).'.jsonl';
        config()->set('atlas.self_construction.learning_transfer_admission_ledger_path', $ledgerPath);
        file_put_contents($ledgerPath, json_encode([
            'schema_version' => 'atlas.learning_transfer.admission_ledger.v1',
            'recorded_at' => '2026-07-01T00:00:00Z',
            'classification' => [
                'class' => 'duplicate_capability',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/lesson-match.php'],
                'blocking_facts' => ['observed_already_exists'],
                'evidence_refs' => ['evidence://lesson'],
            ],
        ])."\n".json_encode([
            'schema_version' => 'atlas.learning_transfer.admission_ledger.v1',
            'recorded_at' => '2026-07-01T00:00:01Z',
            'classification' => [
                'class' => 'scope_gap',
                'allowed_files' => ['app/Other/Unrelated.php'],
                'blocking_facts' => [],
                'evidence_refs' => [],
            ],
        ])."\n");

        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('lesson-match')]);

        $res = (new AtlasTaskServingService($orch))->next('client-cold');

        self::assertSame('served', $res['status']);
        // The lesson whose files overlap travels with the packet; the unrelated one does not.
        self::assertCount(1, $res['task']['known_lessons']);
        self::assertSame('duplicate_capability', $res['task']['known_lessons'][0]['class']);
        self::assertSame(['observed_already_exists'], $res['task']['known_lessons'][0]['blocking_facts']);

        @unlink($ledgerPath);
    }

    public function test_known_lessons_match_by_directory_not_literal_file(): void
    {
        // w28: an admitted lesson carries the exact files of the packet that
        // CLOSED it; a future task in the same AREA (different file) must
        // still receive the lesson — the lesson identity is class+scope_dirs.
        $ledgerPath = sys_get_temp_dir().'/atlas-anj-dirmatch-'.bin2hex(random_bytes(5)).'.jsonl';
        config()->set('atlas.self_construction.learning_transfer_admission_ledger_path', $ledgerPath);
        file_put_contents($ledgerPath, json_encode([
            'schema_version' => 'atlas.learning_transfer.admission_ledger.v1',
            'recorded_at' => '2026-07-01T00:00:00Z',
            'classification' => [
                'class' => 'scope_gap',
                'allowed_files' => ['app/Services/Ai/SelfConstruction/some-other-packet-file.php'],
                'blocking_facts' => ['allowed_files_insufficient'],
                'evidence_refs' => ['task_packet:closer'],
            ],
        ])."\n");

        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('dir-match')]);

        $res = (new AtlasTaskServingService($orch))->next('client-cold');

        self::assertSame('served', $res['status']);
        self::assertCount(1, $res['task']['known_lessons']);
        self::assertSame('scope_gap', $res['task']['known_lessons'][0]['class']);

        @unlink($ledgerPath);
    }

    public function test_resolved_packet_becomes_a_green_run_exemplar_for_same_area_tasks(): void
    {
        $receiptsPath = AgentControlPlaneTaskQueueOrchestrator::resolvedReceiptsPath();
        @unlink($receiptsPath);

        // Real resolution through the funnel: claim + lease-validated commit.
        $sourceInput = $this->input('exemplar-source');
        $proof = $this->committedTaskProof('exemplar-source', $sourceInput['allowed_files']);
        $orch = $this->orchestrator($proof['repository']);
        $orch->prepareAndEnqueue(['task_packet' => $sourceInput]);
        $claim = $orch->claimNext('agent-exemplar');
        $orch->markResolved('exemplar-source', (string) $claim['lease_id'], 'agent-exemplar', $proof['commit_sha']);

        // A NEW task in the same directory (different file) is served the exemplar.
        $orch->prepareAndEnqueue(['task_packet' => $this->input('exemplar-consumer')]);
        $res = (new AtlasTaskServingService($orch))->next('client-cold');

        self::assertSame('served', $res['status']);
        self::assertCount(1, $res['task']['green_run_exemplars']);
        self::assertSame('exemplar-source', $res['task']['green_run_exemplars'][0]['task_packet_id']);
        self::assertSame($proof['commit_sha'], $res['task']['green_run_exemplars'][0]['commit_sha']);
        self::assertNotSame('', $res['task']['green_run_exemplars'][0]['objective_excerpt']);

        @unlink($receiptsPath);
    }

    public function test_served_packet_carries_sibling_tests_for_real_repo_files(): void
    {
        // Uses a REAL production file with a REAL mirrored test in this repo.
        $orch = $this->orchestrator();
        $input = $this->input('sibling-probe');
        $input['allowed_files'] = ['app/Services/Ai/Gateway/ChatWeakResponseProbe.php'];
        $input['scope_in'] = $input['allowed_files'];
        $orch->prepareAndEnqueue(['task_packet' => $input]);

        $res = (new AtlasTaskServingService($orch))->next('client-cold');

        self::assertSame('served', $res['status']);
        self::assertCount(1, $res['task']['sibling_tests']);
        self::assertSame(
            'tests/Unit/Ai/Gateway/ChatWeakResponseProbeTest.php',
            $res['task']['sibling_tests'][0]['test_path'],
        );
        self::assertNotEmpty($res['task']['sibling_tests'][0]['test_methods']);
    }

    public function test_served_packet_known_lessons_is_empty_when_ledger_absent(): void
    {
        config()->set('atlas.self_construction.learning_transfer_admission_ledger_path', sys_get_temp_dir().'/atlas-anj-missing-'.bin2hex(random_bytes(5)).'.jsonl');

        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('no-lessons')]);

        $res = (new AtlasTaskServingService($orch))->next('client-cold');

        self::assertSame('served', $res['status']);
        self::assertSame([], $res['task']['known_lessons']);
    }

    // ── prepareAndEnqueue own quality-rejection + receipt + author-not-judge guarantees ──

    public function test_prepare_and_enqueue_rejects_not_self_sufficient_packet_and_returns_quality_not_queue_entry(): void
    {
        // Passes the builder's own checks (non-empty objective/scope/acceptance) but the write
        // scope is a bare DIRECTORY, not a concrete file — a deficiency only the quality
        // inspector catches, not the builder itself.
        $input = $this->input('bare-dir-packet');
        $input['allowed_files'] = ['app/Services/Ai/SelfConstruction/'];
        $input['scope_in'] = ['app/Services/Ai/SelfConstruction/'];

        $result = $this->orchestrator()->prepareAndEnqueue(['task_packet' => $input]);

        self::assertSame('prepare_blocked', $result['event']);
        self::assertSame('task_packet_not_self_sufficient', $result['reason']);
        self::assertNull($result['queue_entry']);
        self::assertFalse($result['packet_quality']['self_sufficient']);
        self::assertContains('bare_directory_in_allowed_files', $result['packet_quality']['blocking_deficiencies']);
    }

    public function test_successful_prepare_and_enqueue_appends_exactly_one_of_each_planning_receipt(): void
    {
        $taskPacketId = 'receipt-once-'.bin2hex(random_bytes(4));
        $orch = $this->orchestrator();
        $result = $orch->prepareAndEnqueue(['task_packet' => $this->input($taskPacketId)]);

        self::assertSame('prepared_and_enqueued', $result['event']);
        self::assertNotNull($result['queue_entry']);

        $record = (new AgentControlPlaneTaskPacketQueueRepository)->get($taskPacketId);
        $receiptKinds = array_map(
            static fn (array $r): string => (string) ($r['receipt_kind'] ?? ''),
            (array) data_get($record, 'receipts', []),
        );

        foreach (['scope_lock_runtime_validated', 'evidence_plan_prepared', 'continuation_summary_prepared'] as $expectedKind) {
            self::assertSame(
                1,
                count(array_filter($receiptKinds, static fn (string $k): bool => $k === $expectedKind)),
                "expected exactly one '{$expectedKind}' receipt, got: ".implode(',', $receiptKinds),
            );
        }
    }

    public function test_prepare_and_enqueue_stays_author_not_judge_never_claims_completes_or_executes(): void
    {
        $taskPacketId = 'author-not-judge';
        $result = $this->orchestrator()->prepareAndEnqueue(['task_packet' => $this->input($taskPacketId)]);

        self::assertSame('prepared_and_enqueued', $result['event']);
        self::assertFalse($result['dispatch_allowed']);
        self::assertFalse($result['provider_call_allowed']);
        self::assertFalse($result['token_spend_allowed']);
        self::assertFalse($result['self_programming_allowed']);
        self::assertFalse($result['ledger_write_allowed']);
        self::assertFalse($result['completion_real_allowed']);
        self::assertFalse($result['runtime_execution_allowed']);

        // Enqueued as claimable, never pre-claimed by the authoring path itself.
        $record = (new AgentControlPlaneTaskPacketQueueRepository)->get($taskPacketId);
        self::assertSame('claimable', (string) ($record['status'] ?? ''));
    }

    /** @param array<string, mixed> $input */
    private function rawEnqueue(array $input): void
    {
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($input);
        (new AgentControlPlaneTaskPacketQueueRepository)->enqueue($packet);
    }

    /** @return array<string, mixed> */
    private function input(string $id, ?array $acceptance = null, ?array $evidence = null): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'wire AtlasFooService into the php artisan kernel for packet '.$id,
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'acceptance_criteria' => $acceptance ?? ['php artisan test passes'],
            'required_evidence' => $evidence ?? ['task_packet_created'],
        ];
    }
}
