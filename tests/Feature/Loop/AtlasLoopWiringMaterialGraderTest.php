<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopWiringMaterialGrader;
use ReflectionClass;
use Tests\TestCase;

/**
 * THE GRADER IS THE EXTERNAL, LOOP-IMMOVABLE BAR for "wire a parked primitive to do something NEW".
 * The operator authors the ruler; the loop fills a constrained slot. These pin the anti-Goodhart heart:
 * a NEW behaviour-changing wiring is admissible; an already-wired no-op, a non-behavioural (structural)
 * acceptance, a comment-only "reference", or a non-existent file are REJECTED — the loop cannot pass for free.
 */
final class AtlasLoopWiringMaterialGraderTest extends TestCase
{
    private function grader(): AtlasLoopWiringMaterialGrader
    {
        return new AtlasLoopWiringMaterialGrader;
    }

    private function behaviourAtom(): array
    {
        return ['type' => 'method_return', 'method' => 'pick', 'expected' => 'doc_gap', 'constructor_args' => [], 'method_args' => []];
    }

    public function test_a_new_behaviour_changing_wiring_is_admissible(): void
    {
        // primitive = the grader itself (just created, referenced nowhere yet); consumer = an unrelated real
        // loop file that does NOT name it. A behavioural atom asserts an observable new behaviour.
        $v = $this->grader()->validateClaim([
            'primitive_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopWiringMaterialGrader.php',
            'consumer_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopAbstainAndAsk.php',
            'behaviour_atom' => $this->behaviourAtom(),
        ], base_path());

        $this->assertTrue($v['material'], 'reason='.json_encode($v['reason']));
        $this->assertSame('AtlasLoopWiringMaterialGrader', $v['primitive_class']);
    }

    public function test_already_wired_primitive_is_rejected_as_not_new(): void
    {
        // AtlasLoopAbstainAndAsk IS already wired into AtlasLoopOriginationPipeline (slice 1a). Nothing new.
        $v = $this->grader()->validateClaim([
            'primitive_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopAbstainAndAsk.php',
            'consumer_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopOriginationPipeline.php',
            'behaviour_atom' => $this->behaviourAtom(),
        ], base_path());

        $this->assertFalse($v['material']);
        $this->assertSame('primitive_already_wired_into_consumer', $v['reason']);
    }

    public function test_non_behavioural_atom_is_rejected(): void
    {
        // A structural "references" atom (not observable behaviour) must NOT certify — a trivial unused
        // reference would satisfy it. Only method_return / command_output / http_response are admissible.
        foreach ([['type' => 'references'], ['type' => 'symbol_present'], [], ['type' => '']] as $atom) {
            $v = $this->grader()->validateClaim([
                'primitive_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopWiringMaterialGrader.php',
                'consumer_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopAbstainAndAsk.php',
                'behaviour_atom' => $atom,
            ], base_path());
            $this->assertFalse($v['material'], 'atom '.json_encode($atom).' must be rejected');
            $this->assertStringContainsString('not_an_observable_behaviour_atom', (string) $v['reason']);
        }
    }

    public function test_nonexistent_files_are_rejected(): void
    {
        $noPrim = $this->grader()->validateClaim([
            'primitive_path' => 'app/Services/Ai/AutonomousEvolution/NoSuchPrimitiveZZZ.php',
            'consumer_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopAbstainAndAsk.php',
            'behaviour_atom' => $this->behaviourAtom(),
        ], base_path());
        $this->assertSame('primitive_not_a_real_file', $noPrim['reason']);

        $noCons = $this->grader()->validateClaim([
            'primitive_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopWiringMaterialGrader.php',
            'consumer_path' => 'app/Services/Ai/AutonomousEvolution/NoSuchConsumerZZZ.php',
            'behaviour_atom' => $this->behaviourAtom(),
        ], base_path());
        $this->assertSame('consumer_not_a_real_file', $noCons['reason']);
    }

    public function test_required_primitive_usage_is_the_class_for_valid_else_null(): void
    {
        $ok = $this->grader()->requiredPrimitiveUsage([
            'primitive_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopWiringMaterialGrader.php',
            'consumer_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopAbstainAndAsk.php',
            'behaviour_atom' => $this->behaviourAtom(),
        ], base_path());
        $this->assertSame('AtlasLoopWiringMaterialGrader', $ok);

        $bad = $this->grader()->requiredPrimitiveUsage([
            'primitive_path' => 'app/.../NoSuchZZZ.php', 'consumer_path' => 'x', 'behaviour_atom' => $this->behaviourAtom(),
        ], base_path());
        $this->assertNull($bad);
    }

    public function test_comment_only_mention_does_not_count_as_wired(): void
    {
        // referencesSymbol must ignore comments — a `// AtlasLoopFoo` note is not wiring.
        $grader = $this->grader();
        $m = (new ReflectionClass($grader))->getMethod('referencesSymbol');
        $m->setAccessible(true);
        $this->assertTrue($m->invoke($grader, '$x = new AtlasLoopFoo();', 'AtlasLoopFoo'), 'real code reference counts');
        $this->assertFalse($m->invoke($grader, '// AtlasLoopFoo is parked', 'AtlasLoopFoo'), 'comment mention does NOT count');
        $this->assertFalse($m->invoke($grader, '/* AtlasLoopFoo */ $y = 1;', 'AtlasLoopFoo'), 'block-comment mention does NOT count');
    }

    public function test_php8_attribute_reference_counts_as_wired(): void
    {
        // A primitive referenced only inside a PHP 8 #[Attribute(...)] is real code, not a comment;
        // the hash-comment stripper must NOT erase it — otherwise GATE 2 fails open.
        $grader = $this->grader();
        $m = (new ReflectionClass($grader))->getMethod('referencesSymbol');
        $m->setAccessible(true);

        $src = <<<'PHP'
<?php

#[UsesPrimitive(AtlasLoopFoo::class)]
final class SomeConsumer
{
}
PHP;

        $this->assertTrue($m->invoke($grader, $src, 'AtlasLoopFoo'), 'a PHP 8 attribute reference counts as wired');
    }
}
