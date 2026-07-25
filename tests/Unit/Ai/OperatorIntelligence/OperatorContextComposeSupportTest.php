<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\Support\OperatorContextComposeSupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OperatorContextComposeSupportTest extends TestCase
{
    #[Test]
    public function clamp_limit_and_privacy(): void
    {
        $this->assertSame(1, OperatorContextComposeSupport::clampLimit(0));
        $this->assertSame(50, OperatorContextComposeSupport::clampLimit(99));
        $this->assertSame(8, OperatorContextComposeSupport::clampLimit(8));
        $this->assertTrue(OperatorContextComposeSupport::privacyAllowed('normal', ['normal']));
        $this->assertFalse(OperatorContextComposeSupport::privacyAllowed('secret', ['normal', 'private']));
    }

    #[Test]
    public function omitted_and_rank_helpers(): void
    {
        $omitted = OperatorContextComposeSupport::omitted('id-1', 'key', 'private', 'privacy_class_not_allowed');
        $this->assertSame('privacy_class_not_allowed', $omitted['reason']);
        $this->assertSame(2, OperatorContextComposeSupport::flowScoreForMatch(true));
        $this->assertSame(0, OperatorContextComposeSupport::flowScoreForMatch(false));

        $a = OperatorContextComposeSupport::rankTuple(2, 0.9, 100);
        $b = OperatorContextComposeSupport::rankTuple(0, 0.99, 200);
        $this->assertTrue(($a <=> $b) > 0);
    }
}
