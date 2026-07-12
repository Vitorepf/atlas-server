<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Execution;

use App\Services\Ai\Programming\AtlasDev\Execution\ConfirmedDevRun;
use App\Services\Ai\Programming\AtlasDev\Execution\DevIntent;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AtlasDevExecutionServiceTest extends TestCase
{
    public function test_dev_intent_is_immutable_and_binds_product_spec_world_and_authority(): void
    {
        $intent = DevIntent::fromArray([
            'raw_goal' => 'Add a bounded backend behavior',
            'workspace' => '/tmp/example-repo',
            'operator_id' => 'operator-1',
            'product_intent_hash' => str_repeat('a', 64),
            'spec_hash' => str_repeat('b', 64),
            'world_model_snapshot_hash' => str_repeat('c', 64),
            'authority_hash' => str_repeat('d', 64),
            'risk_class' => 'R5',
            'duration_regime' => 'interactive',
            'topology' => 'single',
        ]);

        $this->assertSame('R5', $intent->riskClass);
        $this->assertSame('interactive', $intent->durationRegime);
        $this->assertSame('single', $intent->topology);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $intent->intentHash);
        $this->assertSame($intent->intentHash, DevIntent::fromArray($intent->toArray())->intentHash);
    }

    public function test_unconfirmed_run_cannot_be_created_from_a_different_authority(): void
    {
        $intent = DevIntent::fromArray($this->validIntent());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dev_run_authority_mismatch');

        ConfirmedDevRun::fromIntent($intent, 'operator-1', str_repeat('e', 64));
    }

    public function test_r5_requires_explicit_operator_authority_and_confirmation_token(): void
    {
        $intent = DevIntent::fromArray($this->validIntent());

        $run = ConfirmedDevRun::fromIntent($intent, 'operator-1', str_repeat('d', 64));

        $this->assertSame($intent->intentHash, $run->intentHash);
        $this->assertSame($intent->authorityHash, $run->authorityHash);
        $this->assertSame('operator-1', $run->operatorId);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $run->runHash);
    }

    /** @return array<string,mixed> */
    private function validIntent(): array
    {
        return [
            'raw_goal' => 'Change a backend behavior', 'workspace' => '/tmp/example-repo', 'operator_id' => 'operator-1',
            'product_intent_hash' => str_repeat('a', 64), 'spec_hash' => str_repeat('b', 64),
            'world_model_snapshot_hash' => str_repeat('c', 64), 'authority_hash' => str_repeat('d', 64),
            'risk_class' => 'R5', 'duration_regime' => 'interactive', 'topology' => 'single',
        ];
    }
}
