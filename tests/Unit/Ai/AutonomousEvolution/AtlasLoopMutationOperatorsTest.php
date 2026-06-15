<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMutationOperators;
use PHPUnit\Framework\TestCase;

/**
 * Pins the shared mutation-operator catalog now used by BOTH the mutation-adequacy gate (sampling)
 * and the characterization-test verifier (re-applying a specific operator). A divergence here would
 * let the verifier "prove" a test kills a mutant the gate never produced.
 */
final class AtlasLoopMutationOperatorsTest extends TestCase
{
    public function test_apply_operator_reproduces_the_expected_transforms(): void
    {
        $this->assertSame(
            "<?php\nif (\$a !== \$b) { return 1; }\n",
            AtlasLoopMutationOperators::applyOperator('strict_equals', "<?php\nif (\$a === \$b) { return 1; }\n"),
        );
        $this->assertSame(
            "<?php\nreturn \$n <= 0;\n",
            AtlasLoopMutationOperators::applyOperator('gt_comparison', "<?php\nreturn \$n > 0;\n"),
        );
        $this->assertSame(
            "<?php\nreturn 0;\n",
            AtlasLoopMutationOperators::applyOperator('return_integer', "<?php\nreturn 5;\n"),
        );
    }

    public function test_unknown_or_inapplicable_operator_returns_null(): void
    {
        $this->assertNull(AtlasLoopMutationOperators::applyOperator('no_such_operator', '<?php return 1;'));
        // strict_equals on a file with no === does not apply
        $this->assertNull(AtlasLoopMutationOperators::applyOperator('strict_equals', "<?php\nreturn 1;\n"));
    }

    public function test_relational_operators_ignore_comments_and_strings(): void
    {
        // The only `>` lives in a docblock generic — masking must stop it being mutated (would be a
        // no-op decision mutant that FALSELY rejects a real refactor).
        $src = "<?php\n/** @return array<int,string> */\nfunction f(): array { return []; }\n";
        $this->assertNull(AtlasLoopMutationOperators::applyOperator('gt_comparison', $src));
    }

    public function test_cosmetic_classification_matches_the_catalog(): void
    {
        $this->assertTrue(AtlasLoopMutationOperators::isCosmetic('string_literal'));
        $this->assertTrue(AtlasLoopMutationOperators::isCosmetic('return_string_literal'));
        $this->assertFalse(AtlasLoopMutationOperators::isCosmetic('strict_equals'));
        $this->assertFalse(AtlasLoopMutationOperators::isCosmetic('return_integer'));
    }
}
