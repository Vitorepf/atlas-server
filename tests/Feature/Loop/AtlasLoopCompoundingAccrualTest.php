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
                $sig = (array) ($input['learning_signal'] ?? []);
                $claim = (string) ($sig['claim'] ?? '');
                $conf = $sig['confidence'] ?? null;

                return ($input['outcome_status'] ?? '') === 'passed'
                    && ($input['flow_id'] ?? '') === 'loop_auto_merge'
                    // Claim is SUBSTANTIVE (cites the file) but STABLE — the volatile commit SHA must
                    // NOT be in the claim (it floods the content-dedup); it belongs in evidence_refs.
                    && str_contains($claim, 'app/Support/Foo.php')
                    && ! str_contains($claim, 'abc123def4')
                    && in_array('commit:abc123def456', (array) ($sig['evidence_refs'] ?? []), true)
                    // Confidence is a PROMOTING 0–100 integer (a green canary). The bug this pins: a
                    // 0–1 fraction floored to 1 (<70) held EVERY merge forever — 0 recallable memory.
                    && is_int($conf) && $conf >= 70 && $conf <= 100;
            })
            ->andReturn(['status' => 'recorded', 'learning_candidate' => ['id' => 'x', 'status' => 'promoted', 'promotion_allowed' => true]]);
        $this->app->instance(AtlasCompoundingRuntimeService::class, $spy);

        $this->accrue($this->proposal(), 'abc123def456', ['ran' => true, 'passed' => true, 'target' => 'tests/Unit/Support/FooTest.php']);

        // Mockery verifica a expectativa `once()` no tearDown.
        $this->assertTrue(true);
    }

    public function test_claim_is_stable_across_commits_so_repeated_merges_dedup(): void
    {
        config(['atlas.ai.loop.compounding_accrual' => true]);
        $claims = [];
        $spy = Mockery::mock(AtlasCompoundingRuntimeService::class);
        $spy->shouldReceive('recordExecution')->twice()->andReturnUsing(function (array $input) use (&$claims): array {
            $claims[] = (string) ($input['learning_signal']['claim'] ?? '');

            return ['status' => 'recorded'];
        });
        $this->app->instance(AtlasCompoundingRuntimeService::class, $spy);

        $green = ['ran' => true, 'passed' => true, 'target' => 'tests/Unit/Support/FooTest.php'];
        $this->accrue($this->proposal(), 'commit-aaaaaaaa', $green);
        $this->accrue($this->proposal(), 'commit-bbbbbbbb', $green);

        // Two green merges to the SAME target with DIFFERENT commits => IDENTICAL claim, so the
        // content-dedup revalidates ONE memory instead of flooding distinct active rows.
        $this->assertCount(2, $claims);
        $this->assertSame($claims[0], $claims[1], 'the claim must not vary by commit (the SHA lives in evidence_refs)');
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
            ->withArgs(function (array $input): bool {
                $sig = (array) ($input['learning_signal'] ?? []);

                // A RED-canary merge (fix-forward enqueued) is reflected in the claim AND stays HELD
                // (<70) — it must never promote a "this was good" memory (the no-noise contract).
                return str_contains((string) ($sig['claim'] ?? ''), 'RED')
                    && is_int($sig['confidence'] ?? null) && (int) $sig['confidence'] < 70;
            })
            ->andReturn(['status' => 'recorded']);
        $this->app->instance(AtlasCompoundingRuntimeService::class, $spy);

        $this->accrue($this->proposal(), 'deadbeef1234', ['ran' => true, 'passed' => false, 'target' => 'tests/Unit/Support/FooTest.php']);

        $this->assertTrue(true);
    }
}
