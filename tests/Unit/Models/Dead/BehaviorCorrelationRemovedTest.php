<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Dead;

use Tests\TestCase;

final class BehaviorCorrelationRemovedTest extends TestCase
{
    public function test_behavior_correlation_model_class_no_longer_exists(): void
    {
        $this->assertFalse(class_exists('App\\Models\\BehaviorCorrelation', false));
    }
}
