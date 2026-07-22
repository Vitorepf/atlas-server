<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\Cognition\AcosProgram\DogfoodingFrictionLeadMiner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Multn1708DogfoodingFrictionLeadTest extends TestCase
{
    #[Test]
    public function repeated_override_signature_becomes_dogfooding_lead(): void
    {
        $out = DogfoodingFrictionLeadMiner::mine([
            ['signature' => 'capture_fail:memory', 'kind' => 'override', 'target' => 'app/Services/Ai/Memory/Foo.php', 'operator_text' => 'raw private note'],
            ['signature' => 'capture_fail:memory', 'kind' => 'override', 'target' => 'app/Services/Ai/Memory/Foo.php', 'operator_text' => 'another raw note'],
            ['signature' => 'capture_fail:memory', 'kind' => 'override', 'target' => 'app/Services/Ai/Memory/Foo.php', 'operator_text' => 'third raw note'],
        ]);

        $this->assertSame('ok', $out['status']);
        $this->assertSame('dogfooding', $out['leads'][0]['class']);
        $this->assertSame('app/Services/Ai/Memory/Foo.php', $out['leads'][0]['target']);
        $this->assertStringNotContainsString('raw private', $out['leads'][0]['objective']);
    }

    #[Test]
    public function singleton_friction_is_no_signal_not_a_seed(): void
    {
        $out = DogfoodingFrictionLeadMiner::mine([
            ['signature' => 'one', 'kind' => 'override', 'target' => 'x'],
        ]);

        $this->assertSame('insufficient_signal', $out['status']);
        $this->assertSame([], $out['leads']);
    }
}
