<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves AgentControlPlaneTaskQueueOrchestrator::prepareAndEnqueue composes the existing
 * AtlasTaskFabricTemplateFarmSimilarityGate and AtlasTaskFabricSemanticDuplicateIndex organs:
 * a near-copy of an already-queued packet is prepare_blocked naming the matched packet id,
 * a genuinely distinct packet still enqueues, and a gate exception fails open.
 */
final class AgentControlPlaneEnqueueAntiFarmGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function orchestrator(): AgentControlPlaneTaskQueueOrchestrator
    {
        return new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );
    }

    private function taskInput(array $overrides = []): array
    {
        return array_merge([
            'objective' => 'Implement AtlasFooBarWidget to close the reporting gap for the widget subsystem',
            'allowed_files' => ['app/Services/Ai/FooBarWidget.php'],
            'acceptance_criteria' => ['php artisan test --filter=AtlasFooBarWidgetTest exits 0'],
            'required_evidence' => ['tests_or_gates_result'],
        ], $overrides);
    }

    public function test_near_copy_of_queued_packet_is_prepare_blocked_naming_the_matched_packet(): void
    {
        $orchestrator = $this->orchestrator();

        $first = $orchestrator->prepareAndEnqueue(['task_packet' => $this->taskInput()]);
        $this->assertSame('prepared_and_enqueued', $first['event']);
        $firstId = (string) $first['task_packet']['task_packet_id'];

        // Near-copy: same stem/proof-path shape, different target class name only.
        $second = $orchestrator->prepareAndEnqueue(['task_packet' => $this->taskInput([
            'objective' => 'Implement AtlasBazQuxWidget to close the reporting gap for the widget subsystem',
            'allowed_files' => ['app/Services/Ai/BazQuxWidget.php'],
            'acceptance_criteria' => ['php artisan test --filter=AtlasBazQuxWidgetTest exits 0'],
        ])]);

        $this->assertSame('prepare_blocked', $second['event']);
        $this->assertContains($second['reason'], ['template_farm_similarity', 'semantic_duplicate']);
        $this->assertContains($firstId, $second['matched_task_packet_ids']);
    }

    public function test_semantic_duplicate_by_capability_key_and_target_family_is_prepare_blocked(): void
    {
        $orchestrator = $this->orchestrator();

        $first = $orchestrator->prepareAndEnqueue([
            'task_packet' => $this->taskInput([
                'objective' => 'First distinct objective wording for capability alpha closure',
                'allowed_files' => ['app/Services/Ai/CapabilityAlphaOwner.php'],
                'acceptance_criteria' => ['php artisan test --filter=CapabilityAlphaOwnerTest exits 0'],
                'capability_key' => 'reduce-give-back-rate',
                'target_family' => 'external-brain',
            ]),
        ]);
        $this->assertSame('prepared_and_enqueued', $first['event']);
        $firstId = (string) $first['task_packet']['task_packet_id'];

        $second = $orchestrator->prepareAndEnqueue([
            'task_packet' => $this->taskInput([
                'objective' => 'Wholly unrelated wording that shares nothing lexically with the first task',
                // Different directory than the first packet's allowed_files, so the
                // template-farm allowed_files-shape signal does not also fire here — this
                // scenario proves the semantic-duplicate index specifically.
                'allowed_files' => [
                    'app/Services/Ai/SomethingElse/Owner.php',
                    'tests/Unit/Ai/SomethingElse/OwnerTest.php',
                ],
                // Deliberately avoids the "--filter=" / "exits 0" / "php artisan test" proof-path
                // boilerplate the first packet uses, and any must/shall/will verb — otherwise the
                // template-farm gate's proof-path or verb signal would fire on its own, masking
                // the semantic-duplicate-index behavior this scenario is meant to prove.
                'acceptance_criteria' => ['owner class resolves correctly from the container'],
                'capability_key' => 'reduce-give-back-rate',
                'target_family' => 'external-brain',
            ]),
        ]);

        $this->assertSame('prepare_blocked', $second['event']);
        $this->assertSame('semantic_duplicate', $second['reason']);
        $this->assertContains($firstId, $second['matched_task_packet_ids']);
    }

    public function test_genuinely_distinct_packet_still_enqueues(): void
    {
        $orchestrator = $this->orchestrator();

        $first = $orchestrator->prepareAndEnqueue(['task_packet' => $this->taskInput()]);
        $this->assertSame('prepared_and_enqueued', $first['event']);

        $second = $orchestrator->prepareAndEnqueue(['task_packet' => $this->taskInput([
            'objective' => 'Harden AtlasCompletelyDifferentGate so orphaned receipts never validate twice',
            // Different directory than the first packet's allowed_files, so this scenario
            // proves genuine distinctness rather than tripping the template-farm
            // allowed_files-shape signal (which fires whenever two packets each add a single
            // .php file under the same directory, regardless of wording).
            'allowed_files' => [
                'app/Services/Ai/CompletelyDifferentGate/Gate.php',
                'tests/Unit/Ai/CompletelyDifferentGate/GateTest.php',
            ],
            // Avoids the "--filter=" / "exits 0" / "php artisan test" proof-path boilerplate the
            // first packet uses, which would otherwise trip the template-farm proof-path signal
            // regardless of how distinct the objective wording is.
            'acceptance_criteria' => ['gate rejects an unauthorized caller'],
        ])]);

        $this->assertSame('prepared_and_enqueued', $second['event']);
        $this->assertNull($second['anti_farm_gate_error']);
    }

    public function test_gate_exception_fails_open_and_distinct_packet_still_enqueues(): void
    {
        $orchestrator = $this->orchestrator();

        $first = $orchestrator->prepareAndEnqueue(['task_packet' => $this->taskInput()]);
        $this->assertSame('prepared_and_enqueued', $first['event']);
        $firstId = (string) $first['task_packet']['task_packet_id'];

        // Corrupt the persisted queue record's objective into a non-string (array) — a real
        // runtime fault the composed gates can hit when reading a queue entry, not a mock. The
        // (string) cast on this value inside checkAntiFarmGates() throws Array-to-string
        // ErrorException, which prepareAndEnqueue must swallow (fail-open).
        $path = 'atlas/self-construction/agent-control-plane/task-queue/task_'
            .preg_replace('/[^A-Za-z0-9_\-]/', '_', $firstId).'.json';
        $record = json_decode(Storage::disk('local')->get($path), true);
        $record['task_packet']['objective'] = ['not', 'a', 'string'];
        Storage::disk('local')->put($path, json_encode($record));

        $second = $orchestrator->prepareAndEnqueue([
            'task_packet' => $this->taskInput([
                'objective' => 'Harden AtlasIndependentGate so a distinct capability ships safely',
                'allowed_files' => ['app/Services/Ai/IndependentGate.php'],
                'acceptance_criteria' => ['php artisan test --filter=AtlasIndependentGateTest exits 0'],
            ]),
        ]);

        $this->assertSame('prepared_and_enqueued', $second['event']);
        $this->assertNotNull($second['anti_farm_gate_error']);
    }
}
