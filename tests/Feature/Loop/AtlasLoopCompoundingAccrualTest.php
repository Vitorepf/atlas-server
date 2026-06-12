<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopAutoMergeService;
use App\Services\Ai\Compounding\AtlasCompoundingRuntimeService;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * L3-7: cada merge real vira learning recallável (accrual de compounding).
 *
 * Fecha o laço learn→recall: um merge concreto alimenta AtlasCompoundingRuntimeService
 * com um claim SUBSTANTIVO (arquivo + commit + veredito do canário), criando um learning
 * candidate. O contrato no-noise é honrado por construção — o candidato entra `held_for_
 * evidence` (governado pelo imune cognitivo), nunca auto-promovido. A flag OFF pula tudo.
 */
final class AtlasLoopCompoundingAccrualTest extends TestCase
{
    private function proposal(): AtlasLoopProposal
    {
        return new AtlasLoopProposal([
            'campaign_id' => 'camp-acc',
            'schema_version' => 'atlas.loop.proposal.v1',
            'objective' => 'x',
            'target_path' => 'app/Support/Foo.php',
            'diff_text' => 'diff',
            'proposal_hash' => 'h-acc-'.bin2hex(random_bytes(4)),
        ]);
    }

    private function accrue(AtlasLoopProposal $p, ?string $commit, array $canary): void
    {
        $m = new ReflectionMethod(AtlasLoopAutoMergeService::class, 'accrueCompounding');
        $m->setAccessible(true);
        $m->invoke(app(AtlasLoopAutoMergeService::class), $p, $commit, $canary);
    }

    public function test_merge_feeds_compounding_with_a_substantive_claim_when_flag_on(): void
    {
        config(['atlas.ai.loop.compounding_accrual' => true]);

        $spy = Mockery::mock(AtlasCompoundingRuntimeService::class);
        $spy->shouldReceive('recordExecution')
            ->once()
            ->withArgs(function (array $input): bool {
                // O claim deve ser SUBSTANTIVO (cita o arquivo + commit), não boilerplate.
                $claim = (string) ($input['learning_signal']['claim'] ?? '');

                return ($input['outcome_status'] ?? '') === 'passed'
                    && ($input['flow_id'] ?? '') === 'loop_auto_merge'
                    && str_contains($claim, 'app/Support/Foo.php')
                    && str_contains($claim, 'abc123def4')
                    && in_array('app/Support/Foo.php', (array) ($input['evidence_refs'] ?? []), true);
            })
            ->andReturn(['status' => 'recorded', 'learning_candidate' => ['id' => 'x', 'status' => 'held_for_evidence', 'promotion_allowed' => false]]);
        $this->app->instance(AtlasCompoundingRuntimeService::class, $spy);

        $this->accrue($this->proposal(), 'abc123def456', ['ran' => true, 'passed' => true, 'target' => 'tests/Unit/Support/FooTest.php']);

        // Mockery verifica a expectativa `once()` no tearDown.
        $this->assertTrue(true);
    }

    public function test_flag_off_never_calls_compounding(): void
    {
        config(['atlas.ai.loop.compounding_accrual' => false]);

        $spy = Mockery::mock(AtlasCompoundingRuntimeService::class);
        $spy->shouldNotReceive('recordExecution');
        $this->app->instance(AtlasCompoundingRuntimeService::class, $spy);

        $this->accrue($this->proposal(), 'abc123def456', ['ran' => true, 'passed' => true, 'target' => 'tests/Unit/Support/FooTest.php']);

        $this->assertTrue(true);
    }

    public function test_red_canary_is_reflected_in_the_claim(): void
    {
        config(['atlas.ai.loop.compounding_accrual' => true]);

        $spy = Mockery::mock(AtlasCompoundingRuntimeService::class);
        $spy->shouldReceive('recordExecution')
            ->once()
            ->withArgs(fn (array $input): bool => str_contains((string) ($input['learning_signal']['claim'] ?? ''), 'RED'))
            ->andReturn(['status' => 'recorded']);
        $this->app->instance(AtlasCompoundingRuntimeService::class, $spy);

        $this->accrue($this->proposal(), 'deadbeef1234', ['ran' => true, 'passed' => false, 'target' => 'tests/Unit/Support/FooTest.php']);

        $this->assertTrue(true);
    }
}
