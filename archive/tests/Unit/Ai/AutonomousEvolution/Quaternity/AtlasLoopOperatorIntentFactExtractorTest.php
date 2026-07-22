<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Quaternity;

use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\AtlasLoopOperatorIntentFactExtractor;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\OperatorIntentFact;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\OperatorIntentMessage;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\OperatorIntentVerb;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\VagueIntentRejection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AtlasLoopOperatorIntentFactExtractorTest extends TestCase
{
    private function message(string $text): OperatorIntentMessage
    {
        return new OperatorIntentMessage(hash('sha256', $text), 1, 'operator', $text, 'chat');
    }

    public function test_remove_fixture(): void
    {
        $fact = (new AtlasLoopOperatorIntentFactExtractor)->extract($this->message('tira keepalive do loop'));

        $this->assertInstanceOf(OperatorIntentFact::class, $fact);
        $this->assertSame(OperatorIntentVerb::REMOVE, $fact->verb);
        $this->assertSame('keepalive', $fact->object);
        $this->assertSame([], $fact->constraints);
    }

    public function test_focus_with_constraint_fixture(): void
    {
        $fact = (new AtlasLoopOperatorIntentFactExtractor)->extract($this->message('foco no loop sem mexer em marketing'));

        $this->assertInstanceOf(OperatorIntentFact::class, $fact);
        $this->assertSame(OperatorIntentVerb::FOCUS, $fact->verb);
        $this->assertSame('loop', $fact->object);
        $this->assertSame(['sem mexer em marketing'], $fact->constraints);
    }

    public function test_vague_message_is_rejected_on_both_axes(): void
    {
        $rejection = (new AtlasLoopOperatorIntentFactExtractor)->extract($this->message('isso e massa'));

        $this->assertInstanceOf(VagueIntentRejection::class, $rejection);
        $this->assertSame(VagueIntentRejection::AXIS_BOTH, $rejection->axis);
    }

    public function test_extraction_is_deterministic_and_makes_no_network_call(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $message = $this->message('foco no loop sem mexer em marketing');
        $extractor = new AtlasLoopOperatorIntentFactExtractor;

        $first = $extractor->extract($message);
        $second = $extractor->extract($message);

        $this->assertInstanceOf(OperatorIntentFact::class, $first);
        $this->assertInstanceOf(OperatorIntentFact::class, $second);
        $this->assertSame($first->toArray(), $second->toArray(), 'byte-identical fact across two calls');

        Http::assertNothingSent(); // no network / no LLM
    }
}
