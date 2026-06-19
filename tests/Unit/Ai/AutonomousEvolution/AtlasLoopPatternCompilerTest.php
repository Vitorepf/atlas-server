<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopExecutionContract;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternCompiler;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternRegistry;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternSpec;
use InvalidArgumentException;

/**
 * Proves the Compiler binds a governed spec + an objective into a complete, SAFE ExecutionContract, and
 * that it is fail-closed: an empty objective throws, and a spec that would yield no success gate throws
 * (the compiler never synthesizes a placeholder gate to "rescue" a degenerate spec).
 */
final class AtlasLoopPatternCompilerTest extends \Tests\TestCase
{
    private function registry(): AtlasLoopPatternRegistry
    {
        return new AtlasLoopPatternRegistry();
    }

    private function objective(): array
    {
        return [
            'objective' => 'Update the loop pattern registry doc after the Slice 1 code landed.',
            'allowed_scope' => ['docs/engineering-knowledge-base/*.md'],
            'required_inputs' => ['changed_paths'],
            'expected_outputs' => ['updated_docs'],
            'budget' => ['iterations' => 3, 'time_seconds' => 600],
        ];
    }

    public function test_compiled_contract_has_all_14_required_fields(): void
    {
        $spec = $this->registry()->find('docs_sweep');
        $this->assertInstanceOf(AtlasLoopPatternSpec::class, $spec);

        $contract = (new AtlasLoopPatternCompiler())->compile($spec, $this->objective());

        $this->assertInstanceOf(AtlasLoopExecutionContract::class, $contract);
        $this->assertTrue($contract->isComplete(), 'a freshly compiled contract must be structurally complete');

        $array = $contract->toArray();
        $expectedKeys = [
            'pattern_id', 'pattern_version', 'objective', 'allowed_scope', 'required_inputs',
            'expected_outputs', 'success_gates', 'terminal_states', 'durability_mode', 'sandbox_profile',
            'agent_lane_policy', 'budget', 'rollback_policy', 'memory_writeback_policy',
        ];
        $this->assertCount(14, $array, 'the contract array must carry exactly the 14 governed fields');
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $array, "contract is missing required field '{$key}'");
        }
    }

    public function test_safety_frontier_comes_from_the_spec_not_the_objective(): void
    {
        $spec = $this->registry()->find('docs_sweep');
        $this->assertInstanceOf(AtlasLoopPatternSpec::class, $spec);

        $contract = (new AtlasLoopPatternCompiler())->compile($spec, $this->objective());

        // Gates, terminals, durability, sandbox and lane policy are copied verbatim from the governed spec.
        $this->assertSame($spec->successGates, $contract->successGates);
        $this->assertSame($spec->terminalStates, $contract->terminalStates);
        $this->assertSame($spec->durabilityMode, $contract->durabilityMode);
        $this->assertSame($spec->sandboxProfile, $contract->sandboxProfile);
        $this->assertSame($spec->agentLanePolicy, $contract->agentLanePolicy);

        // Territory + budget come from the objective.
        $this->assertContains('docs/engineering-knowledge-base/*.md', $contract->allowedScope);
        $this->assertSame(3, $contract->budget['iterations']);

        // Conservative writeback default is baked in (objective cannot flip raw_promote on).
        $this->assertSame(['evidence' => true, 'raw_promote' => false], $contract->memoryWritebackPolicy);

        // single_cycle durability -> discard_worktree rollback.
        $this->assertSame('discard_worktree', $contract->rollbackPolicy['mode']);
    }

    public function test_docs_sweep_and_ticket_to_pr_ready_yield_different_gates_and_sandbox(): void
    {
        $registry = $this->registry();
        $docsSweep = $registry->find('docs_sweep');
        $ticket = $registry->find('ticket_to_pr_ready');
        $this->assertInstanceOf(AtlasLoopPatternSpec::class, $docsSweep);
        $this->assertInstanceOf(AtlasLoopPatternSpec::class, $ticket);

        $compiler = new AtlasLoopPatternCompiler();
        $docsContract = $compiler->compile($docsSweep, $this->objective());
        $ticketContract = $compiler->compile($ticket, [
            'objective' => 'Turn the failing scope-leak report into a bounded patch.',
            'allowed_scope' => ['app/Services/Ai/**'],
        ]);

        // Different patterns -> genuinely different safety frontiers (not a constant template).
        $this->assertNotEquals($docsContract->successGates, $ticketContract->successGates);
        $this->assertNotEquals($docsContract->sandboxProfile, $ticketContract->sandboxProfile);

        // Concretely: ticket-to-PR-ready opts into 'command'; docs sweep does not.
        $this->assertNotContains('command', $docsContract->sandboxProfile['allowed']);
        $this->assertContains('command', $ticketContract->sandboxProfile['allowed']);
    }

    public function test_empty_objective_throws(): void
    {
        $spec = $this->registry()->find('docs_sweep');
        $this->assertInstanceOf(AtlasLoopPatternSpec::class, $spec);

        $this->expectException(InvalidArgumentException::class);

        // objective text is whitespace-only -> the up-front fail-closed guard rejects it.
        (new AtlasLoopPatternCompiler())->compile($spec, ['objective' => '   ']);
    }

    public function test_missing_objective_key_throws(): void
    {
        $spec = $this->registry()->find('docs_sweep');
        $this->assertInstanceOf(AtlasLoopPatternSpec::class, $spec);

        $this->expectException(InvalidArgumentException::class);

        // no 'objective' key at all -> still fail-closed.
        (new AtlasLoopPatternCompiler())->compile($spec, ['allowed_scope' => ['x/*']]);
    }

    /**
     * Fail-closed degraded path: a spec that yields NO success gate must make the compiler throw — it must
     * NOT silently fill a placeholder gate. We force the degenerate condition by building a spec whose
     * gates are all whitespace (the Spec factory itself rejects truly gateless specs, so we reflect-inject
     * a blank-gate spec to prove the COMPILER's downstream gate, then also prove the direct contract throw).
     */
    public function test_spec_yielding_no_gate_fails_closed(): void
    {
        // Direct proof of the structural gate the compiler relies on: an ExecutionContract array with an
        // empty success_gates set THROWS. The compiler hands the assembled array to exactly this factory,
        // so a degenerate spec cannot produce a gateless-but-valid contract.
        $this->expectException(InvalidArgumentException::class);

        AtlasLoopExecutionContract::fromArray([
            'pattern_id' => 'docs_sweep',
            'pattern_version' => '1.0.0',
            'objective' => 'non-empty objective',
            'success_gates' => [],            // <-- the fail-closed condition
            'terminal_states' => ['success'],
            'durability_mode' => 'single_cycle',
            'sandbox_profile' => ['allowed' => ['read_only']],
        ]);
    }

    public function test_compiler_does_not_synthesize_a_gate_for_a_blank_gate_spec(): void
    {
        // Build a degenerate spec via reflection that has zero gates, bypassing the Spec factory's own
        // gate (which would otherwise refuse to construct it). This proves the COMPILER itself does not
        // rescue a gateless spec by inventing a placeholder — it propagates the fail-closed throw.
        $spec = $this->blankGateSpec();
        $this->assertSame([], $spec->successGates, 'precondition: the crafted spec has no gates');

        $this->expectException(InvalidArgumentException::class);

        (new AtlasLoopPatternCompiler())->compile($spec, $this->objective());
    }

    /**
     * Construct an otherwise-valid spec with an EMPTY success_gates list, bypassing the Spec's fail-closed
     * factory (which forbids gateless specs) via reflection — the only honest way to test that the
     * COMPILER does not itself become the hole.
     */
    private function blankGateSpec(): AtlasLoopPatternSpec
    {
        $ref = new \ReflectionClass(AtlasLoopPatternSpec::class);
        /** @var AtlasLoopPatternSpec $spec */
        $spec = $ref->newInstanceWithoutConstructor();

        $set = static function (string $prop, mixed $value) use ($ref, $spec): void {
            $p = $ref->getProperty($prop);
            $p->setValue($spec, $value);
        };

        $set('id', 'degenerate_no_gate');
        $set('version', '0.0.0');
        $set('name', 'Degenerate');
        $set('description', '');
        $set('intent', 'refactor');
        $set('triggerSchema', []);
        $set('paramsSchema', []);
        $set('outputSchema', []);
        $set('successGates', []);                 // <-- zero gates
        $set('terminalStates', ['success']);
        $set('durabilityMode', 'single_cycle');
        $set('sandboxProfile', ['allowed' => ['read_only'], 'deny_by_default' => true]);
        $set('agentLanePolicy', ['lanes' => ['verifier'], 'self_approval' => false, 'verifier_independent' => true]);
        $set('riskLevel', 'low');
        $set('source', 'operator_seed');
        $set('sourceSnapshot', []);
        $set('status', 'ready');

        return $spec;
    }
}
