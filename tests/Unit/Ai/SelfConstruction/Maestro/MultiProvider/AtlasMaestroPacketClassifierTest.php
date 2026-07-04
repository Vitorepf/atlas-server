<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\MultiProvider\AtlasMaestroPacketClassifier;
use Tests\TestCase;

final class AtlasMaestroPacketClassifierTest extends TestCase
{
    public function test_classify_is_closed_set_and_byte_deterministic(): void
    {
        $packet = $this->packet(['app/Services/Foo.php'], 'small implementation task');
        $classifier = $this->classifier();

        $first = $classifier->classify($packet);
        $second = $classifier->classify($packet);

        $this->assertSame($first, $second);
        $this->assertContains($first, [
            AtlasMaestroPacketClassifier::ARCHITECTURE,
            AtlasMaestroPacketClassifier::MULTI_FILE,
            AtlasMaestroPacketClassifier::GRIND,
            AtlasMaestroPacketClassifier::DOC,
            AtlasMaestroPacketClassifier::QUEUE_REPAIR,
            AtlasMaestroPacketClassifier::LEARNING_LOOP,
            AtlasMaestroPacketClassifier::TASK_FABRIC,
            AtlasMaestroPacketClassifier::MODEL_AMPLIFIER,
        ]);
    }

    public function test_doc_paths_only_rule(): void
    {
        $packet = $this->packet(['docs/loop-routing.md', 'docs/maestro-provider-policy.md'], 'document routing');

        $this->assertSame(AtlasMaestroPacketClassifier::DOC, $this->classifier()->classify($packet));
        $this->assertSame(AtlasMaestroPacketClassifier::REASON_DOC, $this->classifier()->reasonFor($packet));
    }

    public function test_multi_file_rule(): void
    {
        $packet = $this->packet([
            'app/Services/Ai/Foo.php',
            'app/Console/Commands/FooCommand.php',
            'config/atlas.php',
        ], 'wire a broad change');

        $this->assertSame(AtlasMaestroPacketClassifier::MULTI_FILE, $this->classifier()->classify($packet));
        $this->assertSame(AtlasMaestroPacketClassifier::REASON_MULTI_FILE, $this->classifier()->reasonFor($packet));
    }

    public function test_architecture_keyword_with_small_surface_rule(): void
    {
        $packet = $this->packet([
            'app/Services/Ai/Foo.php',
            'tests/Unit/FooTest.php',
        ], 'Design the routing contract for worker selection');

        $this->assertSame(AtlasMaestroPacketClassifier::ARCHITECTURE, $this->classifier()->classify($packet));
        $this->assertSame(AtlasMaestroPacketClassifier::REASON_ARCHITECTURE, $this->classifier()->reasonFor($packet));
    }

    public function test_grind_default_rule(): void
    {
        $packet = $this->packet(['app/Services/Ai/Foo.php'], 'implement focused green test');

        $this->assertSame(AtlasMaestroPacketClassifier::GRIND, $this->classifier()->classify($packet));
        $this->assertSame(AtlasMaestroPacketClassifier::REASON_GRIND, $this->classifier()->reasonFor($packet));
    }

    public function test_queue_repair_classification_on_objective_keyword(): void
    {
        $signals = ['respec the packet', 'poison task in queue', 'queue-repair needed', 'queue_health check', 'repair-blocked item'];
        $c = $this->classifier();
        foreach ($signals as $objective) {
            $packet = $this->packet(['app/Services/Ai/Foo.php'], $objective);
            $this->assertSame(AtlasMaestroPacketClassifier::QUEUE_REPAIR, $c->classify($packet), "objective: {$objective}");
            $this->assertSame(AtlasMaestroPacketClassifier::REASON_QUEUE_REPAIR, $c->reasonFor($packet));
        }
    }

    public function test_learning_loop_classification_on_objective_and_path_signal(): void
    {
        $c = $this->classifier();

        $byObjective = $this->packet(['app/Services/Ai/Foo.php'], 'update outcome ledger for give_back events');
        $this->assertSame(AtlasMaestroPacketClassifier::LEARNING_LOOP, $c->classify($byObjective));
        $this->assertSame(AtlasMaestroPacketClassifier::REASON_LEARNING_LOOP, $c->reasonFor($byObjective));

        $byPath = $this->packet(['app/Services/Ai/SelfConstruction/Maestro/ClosedLoop/OutcomeLedger.php'], 'store results');
        $this->assertSame(AtlasMaestroPacketClassifier::LEARNING_LOOP, $c->classify($byPath),
            'ClosedLoop path should trigger learning-loop via closed-loop signal');
    }

    public function test_task_fabric_classification_on_objective_keyword(): void
    {
        $signals = ['harden the task fabric', 'extend task_packet builder', 'fix the task-queue orchestrator', 'enforce claim_lease ttl', 'tighten scope_lock validator'];
        $c = $this->classifier();
        foreach ($signals as $objective) {
            $packet = $this->packet(['app/Services/Ai/Foo.php'], $objective);
            $this->assertSame(AtlasMaestroPacketClassifier::TASK_FABRIC, $c->classify($packet), "objective: {$objective}");
            $this->assertSame(AtlasMaestroPacketClassifier::REASON_TASK_FABRIC, $c->reasonFor($packet));
        }
    }

    public function test_task_fabric_classification_on_path_signal_with_terse_objective(): void
    {
        $packet = $this->packet(['app/Services/Ai/SelfConstruction/AgentControlPlaneTaskPacketQueueRepository.php'], 'fix it');

        $this->assertSame(AtlasMaestroPacketClassifier::TASK_FABRIC, $this->classifier()->classify($packet),
            'a task_packet path must trigger task-fabric even with a terse objective');
    }

    public function test_model_amplifier_classification_on_objective_keyword(): void
    {
        $signals = ['add a model_amplifier contract', 'amplify the smaller model with extra sources', 'amplify a weaker model for dev tasks'];
        $c = $this->classifier();
        foreach ($signals as $objective) {
            $packet = $this->packet(['app/Services/Ai/Foo.php'], $objective);
            $this->assertSame(AtlasMaestroPacketClassifier::MODEL_AMPLIFIER, $c->classify($packet), "objective: {$objective}");
            $this->assertSame(AtlasMaestroPacketClassifier::REASON_MODEL_AMPLIFIER, $c->reasonFor($packet));
        }
    }

    public function test_objective_evidence_wins_over_generic_paths(): void
    {
        // Path is a plain, generic file — no family-specific signal lives in it. The objective
        // keyword alone must decide the family.
        $packet = $this->packet(['app/Services/Ai/Foo.php'], 'add a model_amplifier contract for dev tasks');

        $this->assertSame(AtlasMaestroPacketClassifier::MODEL_AMPLIFIER, $this->classifier()->classify($packet));
    }

    public function test_refactor_os_signal_in_objective_classifies_as_refactor_os(): void
    {
        $packet = $this->packet(['app/Services/Ai/Foo.php'], 'Refactor OS pass: deletion-first simplification of dead scaffold retirement');

        $this->assertSame(AtlasMaestroPacketClassifier::REFACTOR_OS, $this->classifier()->classify($packet));
    }

    public function test_refactor_os_signal_in_allowed_files_classifies_as_refactor_os(): void
    {
        $packet = $this->packet(['app/Services/RefactorOs/Simplifier.php'], 'small implementation task');

        $this->assertSame(AtlasMaestroPacketClassifier::REFACTOR_OS, $this->classifier()->classify($packet));
    }

    public function test_task_fabric_signal_still_takes_precedence_over_refactor_os(): void
    {
        $packet = $this->packet(['app/Services/Ai/Foo.php'], 'Refactor OS pass touching TaskFabric internals');

        $this->assertSame(AtlasMaestroPacketClassifier::TASK_FABRIC, $this->classifier()->classify($packet));
    }

    /**
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    private function packet(array $allowedFiles, string $objective): array
    {
        return [
            'objective' => $objective,
            'allowed_files' => $allowedFiles,
            'scope_in' => $allowedFiles,
            'acceptance_criteria' => ['focused proof'],
        ];
    }

    private function classifier(): AtlasMaestroPacketClassifier
    {
        return new AtlasMaestroPacketClassifier;
    }

    // ── classifyWithContract: complexity_tier, risk_tier, required_provider_capabilities, classification_evidence ──

    public function test_classify_with_contract_has_required_keys(): void
    {
        $result = $this->classifier()->classifyWithContract($this->packet(['app/Foo.php'], 'fix bug'));
        $this->assertArrayHasKey('task_family', $result);
        $this->assertArrayHasKey('complexity_tier', $result);
        $this->assertArrayHasKey('risk_tier', $result);
        $this->assertArrayHasKey('required_provider_capabilities', $result);
        $this->assertArrayHasKey('classification_evidence', $result);
    }

    public function test_architecture_task_is_high_complexity(): void
    {
        $result = $this->classifier()->classifyWithContract($this->packet(['app/Foo.php'], 'redesign architecture for Foo'));
        $this->assertSame('high', $result['complexity_tier']);
    }

    public function test_forbidden_hint_marks_high_risk(): void
    {
        $result = $this->classifier()->classifyWithContract($this->packet(['app/Foo.php'], 'fix forbidden scope issue'));
        $this->assertSame('high', $result['risk_tier']);
    }

    public function test_broad_write_set_marks_high_risk(): void
    {
        $packet = $this->packet([
            'app/Services/A/Foo.php', 'app/Services/B/Bar.php', 'app/Services/C/Baz.php',
            'app/Http/A/One.php', 'app/Console/B/Two.php',
        ], 'broad refactor');
        $result = $this->classifier()->classifyWithContract($packet);
        $this->assertSame('high', $result['risk_tier']);
    }

    public function test_classification_evidence_includes_signals(): void
    {
        $result = $this->classifier()->classifyWithContract($this->packet(['app/Foo.php'], 'fix bug'));
        $this->assertNotEmpty($result['classification_evidence']);
        $this->assertContains('task_family:grind', $result['classification_evidence']);
    }

    public function test_required_provider_capabilities_includes_code_edit(): void
    {
        $result = $this->classifier()->classifyWithContract($this->packet(['app/Foo.php'], 'fix bug'));
        $this->assertContains('code_edit', $result['required_provider_capabilities']);
    }
}
