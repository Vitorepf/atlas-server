<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskServing;

use App\Services\Ai\SelfConstruction\TaskServing\AtlasRefactorDeltaProver;
use PHPUnit\Framework\TestCase;

/**
 * Proves the refactor delta prover: a genuine simplification is improved with
 * no flags; a pure move, a wrapper addition, a one-implementation abstraction
 * and pure growth are each caught by their anti-fake flag. Deterministic on
 * content — the same inputs must always yield the same verdict.
 */
final class AtlasRefactorDeltaProverTest extends TestCase
{
    private AtlasRefactorDeltaProver $prover;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prover = new AtlasRefactorDeltaProver;
    }

    private const DUPLICATED_BLOCK = <<<'PHP'
        $a = load($id);
        if ($a === null) { throw new RuntimeException('missing'); }
        $a->normalize();
        $a->validate();
        $a->stamp();
        save($a);
    PHP;

    public function test_genuine_simplification_is_improved_with_no_flags(): void
    {
        $before = ['app/A.php' => "<?php\nclass A {\n".
            "function one(\$id) {\n".self::DUPLICATED_BLOCK."\nreturn 1;\n}\n".
            "function two(\$id) {\n".self::DUPLICATED_BLOCK."\nreturn 2;\n}\n}\n"];
        // The duplicated pipeline is extracted; both callers shrink to one line.
        $after = ['app/A.php' => "<?php\nclass A {\n".
            "function pipeline(\$id) {\n".self::DUPLICATED_BLOCK."\n}\n".
            "function one(\$id) { \$this->pipeline(\$id); return 1; }\n".
            "function two(\$id) { \$this->pipeline(\$id); return 2; }\n}\n"];

        $proof = $this->prover->prove($before, $after);

        $this->assertTrue($proof['improved'], json_encode($proof));
        $this->assertLessThan(0, $proof['delta']['loc']);
        $this->assertNotContains('no_measurable_improvement', $proof['flags']);
        $this->assertNotContains('move_only', $proof['flags']);
    }

    public function test_pure_move_between_files_is_flagged_and_not_improved(): void
    {
        $body = "function calc(\$x) {\nif (\$x > 0) { return \$x * 2; }\nreturn 0;\n}";
        $before = ['app/A.php' => "<?php\n".$body."\nfunction other() { return 1; }\n"];
        $after = [
            'app/A.php' => "<?php\nfunction other() { return 1; }\n",
            'app/B.php' => "<?php\n".$body."\n",
        ];

        $proof = $this->prover->prove($before, $after);

        $this->assertContains('move_only', $proof['flags'], json_encode($proof));
        $this->assertFalse($proof['improved']);
    }

    public function test_wrapper_only_addition_is_flagged(): void
    {
        $before = ['app/A.php' => "<?php\nfunction real(\$x) {\nif (\$x) { return \$x + 1; }\nreturn 0;\n}\n"];
        $after = ['app/A.php' => "<?php\nfunction real(\$x) {\nif (\$x) { return \$x + 1; }\nreturn 0;\n}\n".
            "function realWrapper(\$x) { return real(\$x); }\n"];

        $proof = $this->prover->prove($before, $after);

        $this->assertContains('wrapper_only', $proof['flags'], json_encode($proof));
        $this->assertFalse($proof['improved']);
    }

    public function test_new_abstraction_with_one_implementation_is_flagged(): void
    {
        $before = ['app/A.php' => "<?php\nclass Worker {\nfunction run() { return 1; }\n}\n"];
        $after = [
            'app/A.php' => "<?php\nclass Worker implements RunnerContract {\nfunction run() { return 1; }\n}\n",
            'app/RunnerContract.php' => "<?php\ninterface RunnerContract {\nfunction run();\n}\n",
        ];

        $proof = $this->prover->prove($before, $after);

        $this->assertContains('single_impl_abstraction', $proof['flags'], json_encode($proof));
    }

    public function test_pure_growth_has_no_measurable_improvement(): void
    {
        $before = ['app/A.php' => "<?php\nfunction a() { return 1; }\n"];
        $after = ['app/A.php' => "<?php\nfunction a() { return 1; }\n".
            "function b(\$x) {\nif (\$x) { return 2; }\nreturn 3;\n}\n"];

        $proof = $this->prover->prove($before, $after);

        $this->assertContains('no_measurable_improvement', $proof['flags'], json_encode($proof));
        $this->assertFalse($proof['improved']);
    }

    public function test_deterministic_on_identical_input(): void
    {
        $before = ['app/A.php' => "<?php\nfunction a() {\nif (x()) { return 1; }\nreturn 2;\n}\n"];
        $after = ['app/A.php' => "<?php\nfunction a() { return x() ? 1 : 2; }\n"];

        $this->assertSame(
            $this->prover->prove($before, $after),
            $this->prover->prove($before, $after),
        );
    }
}
