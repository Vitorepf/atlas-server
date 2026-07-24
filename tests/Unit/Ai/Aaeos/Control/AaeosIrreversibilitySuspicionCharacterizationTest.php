<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Control;

use App\Services\Ai\Aaeos\Control\AaeosIntentCompiler;
use PHPUnit\Framework\TestCase;

final class AaeosIrreversibilitySuspicionCharacterizationTest extends TestCase
{
    public function test_text_regex_is_only_a_suspicion_and_never_mints_sovereign_authority(): void
    {
        $objective = (new AaeosIntentCompiler)->compile('production wipe of billing database');

        $this->assertTrue($objective['irreversibility_suspected']);
        $this->assertFalse($objective['irreversible']);
    }
}
