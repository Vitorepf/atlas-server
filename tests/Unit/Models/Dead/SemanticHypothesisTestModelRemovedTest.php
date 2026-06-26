<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Dead;

use Tests\TestCase;

final class SemanticHypothesisTestModelRemovedTest extends TestCase
{
    public function test_semantic_hypothesis_test_model_class_no_longer_exists(): void
    {
        $this->assertFalse(class_exists('App\\Models\\SemanticHypothesisTest', false));
        $this->assertFalse(class_exists('App\\Models\\SemanticHypothesisTest'));
    }
}
