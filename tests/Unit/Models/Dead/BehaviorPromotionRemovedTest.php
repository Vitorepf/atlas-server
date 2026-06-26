<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Dead;

use Tests\TestCase;

final class BehaviorPromotionRemovedTest extends TestCase
{
    public function test_behavior_promotion_model_class_no_longer_exists(): void
    {
        $this->assertFalse(class_exists('App\\Models\\BehaviorPromotion', false));
    }
}
