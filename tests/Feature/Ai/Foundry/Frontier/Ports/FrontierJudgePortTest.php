<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Foundry\Frontier\Ports;

use App\Services\Ai\AtlasDecideService;
use App\Services\Ai\Foundry\Frontier\Ports\AtlasDecideFrontierJudgeService;
use App\Services\Ai\Foundry\Frontier\Ports\DeterministicFixtureFrontierJudgeService;
use App\Services\Ai\Foundry\Frontier\Ports\FrontierJudgePort;
use Tests\TestCase;

/**
 * Foundry AP-C · Frontier Judge port (Invariant I3) tests.
 *
 * Proves: default-refute, explicit-accept majority required, and that the judge
 * model identity MUST differ from the generator on RESOLVED provider/model. The
 * real judge BLOCKS honestly (no real execution bridge) and never fabricates an
 * accept. No canonical/code write is performed by any path.
 */
final class FrontierJudgePortTest extends TestCase
{
    private const GEN_PROVIDER = 'generator_provider';

    private const GEN_MODEL = 'generator-model';

    /** @return array<string,mixed> */
    private function context(): array
    {
        return [
            'generator_provider_resolved' => self::GEN_PROVIDER,
            'generator_model_resolved' => self::GEN_MODEL,
        ];
    }

    /** @return array<string,mixed> */
    private function proposal(string $id = 'prop_1'): array
    {
        return ['proposal_id' => $id, 'title' => 'Frontier proposal', 'thesis' => 'thesis'];
    }

    public function test_implements_port_interface(): void
    {
        $fixture = new DeterministicFixtureFrontierJudgeService([]);
        $real = new AtlasDecideFrontierJudgeService(app(AtlasDecideService::class));

        $this->assertInstanceOf(FrontierJudgePort::class, $fixture);
        $this->assertInstanceOf(FrontierJudgePort::class, $real);
    }

    public function test_fixture_default_refutes_when_no_seats_configured(): void
    {
        $judge = new DeterministicFixtureFrontierJudgeService([]);

        $verdict = $judge->adjudicate($this->proposal(), $this->context());

        $this->assertSame('refute', $verdict['decision']);
        $this->assertSame('default_refute', $verdict['reason']);
        $this->assertSame(0, $verdict['judge_count']);
    }

    public function test_fixture_default_refutes_on_split_without_majority(): void
    {
        // 3 seats, threshold = ceil(3/2)+1 = 3; only 2 accept => refute.
        $judge = new DeterministicFixtureFrontierJudgeService([
            'prop_1' => ['accept', 'accept', 'refute'],
        ]);

        $verdict = $judge->adjudicate($this->proposal(), $this->context());

        $this->assertSame('refute', $verdict['decision']);
        $this->assertSame('default_refute', $verdict['reason']);
        $this->assertSame(2, $verdict['accept_count']);
        $this->assertSame(3, $verdict['majority_threshold']);
        // Every non-accept seat records a machine-readable refute reason.
        $refuted = array_values(array_filter($verdict['lenses'], static fn ($l) => $l['decision'] === 'refute'));
        $this->assertSame('default_refute', $refuted[0]['reason']);
    }

    public function test_fixture_survives_only_on_explicit_accept_majority(): void
    {
        // 3 seats, threshold = 3; all accept => survive.
        $judge = new DeterministicFixtureFrontierJudgeService([
            'prop_1' => ['accept', 'accept', 'accept'],
        ]);

        $verdict = $judge->adjudicate($this->proposal(), $this->context());

        $this->assertSame('accept', $verdict['decision']);
        $this->assertSame('majority_accept', $verdict['reason']);
        $this->assertSame(3, $verdict['accept_count']);
        $this->assertSame(3, $verdict['majority_threshold']);
    }

    public function test_fixture_treats_unknown_vote_as_default_refute(): void
    {
        // 3 'accept' would survive; one bogus vote (not exactly 'accept') drops it.
        $judge = new DeterministicFixtureFrontierJudgeService([
            'prop_1' => ['accept', 'accept', 'maybe'],
        ]);

        $verdict = $judge->adjudicate($this->proposal(), $this->context());

        $this->assertSame('refute', $verdict['decision']);
        $this->assertSame(2, $verdict['accept_count']);
        $this->assertSame(1, $verdict['refute_count']);
    }

    public function test_fixture_refutes_when_judge_provider_equals_generator(): void
    {
        $judge = new DeterministicFixtureFrontierJudgeService(
            ['prop_1' => ['accept', 'accept', 'accept']],
            judgeProviderResolved: self::GEN_PROVIDER, // same provider as generator
            judgeModelResolved: 'different-model',
        );

        $verdict = $judge->adjudicate($this->proposal(), $this->context());

        $this->assertSame('refute', $verdict['decision']);
        $this->assertSame('judge_equals_generator_blocked', $verdict['reason']);
    }

    public function test_fixture_refutes_when_judge_model_equals_generator(): void
    {
        $judge = new DeterministicFixtureFrontierJudgeService(
            ['prop_1' => ['accept', 'accept', 'accept']],
            judgeProviderResolved: 'different_provider',
            judgeModelResolved: self::GEN_MODEL, // same model as generator
        );

        $verdict = $judge->adjudicate($this->proposal(), $this->context());

        $this->assertSame('refute', $verdict['decision']);
        $this->assertSame('judge_equals_generator_blocked', $verdict['reason']);
    }

    public function test_fixture_model_difference_uses_resolved_identity_not_label(): void
    {
        // Distinct resolved provider AND model => the invariant passes and the
        // majority vote is allowed to run.
        $judge = new DeterministicFixtureFrontierJudgeService(
            ['prop_1' => ['accept', 'accept', 'accept']],
            judgeProviderResolved: 'distinct_provider',
            judgeModelResolved: 'distinct-model',
        );

        $verdict = $judge->adjudicate($this->proposal(), $this->context());

        $this->assertSame('accept', $verdict['decision']);
        $this->assertSame('distinct_provider', $verdict['judge_provider_resolved']);
        $this->assertSame('distinct-model', $verdict['judge_model_resolved']);
    }

    public function test_real_judge_blocks_honestly_with_recorded_reason(): void
    {
        // No real provider execution bridge today => default refute (honest),
        // never a fabricated accept. I3 cannot pass with the real port.
        $judge = new AtlasDecideFrontierJudgeService(app(AtlasDecideService::class));

        $verdict = $judge->adjudicate($this->proposal(), $this->context());

        $this->assertSame('refute', $verdict['decision']);
        $this->assertContains($verdict['reason'], [
            'judge_provider_real_execution_bridge_missing',
            'judge_equals_generator_blocked',
        ]);
        $this->assertArrayHasKey('judge_provider_resolved', $verdict);
        $this->assertArrayHasKey('judge_model_resolved', $verdict);
    }

    public function test_real_judge_refutes_when_resolved_identity_matches_generator(): void
    {
        $judge = new AtlasDecideFrontierJudgeService(app(AtlasDecideService::class));

        // Resolve the judge's own identity first, then feed it back as the
        // generator's resolved identity to force the equals-block path.
        $baseline = $judge->adjudicate($this->proposal(), $this->context());

        $verdict = $judge->adjudicate($this->proposal(), [
            'generator_provider_resolved' => $baseline['judge_provider_resolved'],
            'generator_model_resolved' => $baseline['judge_model_resolved'],
        ]);

        $this->assertSame('refute', $verdict['decision']);
        $this->assertSame('judge_equals_generator_blocked', $verdict['reason']);
    }

    public function test_no_canonical_or_code_write_occurs(): void
    {
        // Adjudication is pure: it returns a verdict map and writes nothing.
        $fixture = new DeterministicFixtureFrontierJudgeService([
            'prop_1' => ['accept', 'accept', 'accept'],
        ]);

        $verdict = $fixture->adjudicate($this->proposal(), $this->context());

        $this->assertIsArray($verdict);
        $this->assertArrayNotHasKey('canonical_write', $verdict);
        $this->assertArrayNotHasKey('merge', $verdict);
        $this->assertArrayNotHasKey('executed', $verdict);
    }
}
