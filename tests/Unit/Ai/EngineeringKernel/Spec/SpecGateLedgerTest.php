<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel\Spec;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\EngineeringKernel\Spec\AtlasSpecGateAdapter;
use App\Services\Ai\EngineeringKernel\Spec\DivergenceStatus;
use App\Services\Ai\EngineeringKernel\Spec\IntentEnvelope;
use App\Services\Ai\EngineeringKernel\Spec\OracleReport;
use App\Services\Ai\EngineeringKernel\Spec\SpecDraft;
use App\Services\Ai\EngineeringKernel\Spec\SpecOracle;
use App\Services\Ai\EngineeringKernel\Spec\SpecSourceIndependence;
use App\Services\Ai\EngineeringKernel\Spec\WitnessContext;
use App\Services\Ai\EngineeringKernel\Spec\WitnessResolver;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use PHPUnit\Framework\TestCase;

final class SpecGateLedgerTest extends TestCase
{
    public function test_frozen_spec_emits_one_idempotent_unit_frozen_event(): void
    {
        $oracle = new class implements SpecOracle {
            public function probe(SpecDraft $draft): OracleReport
            {
                return OracleReport::executional(['ac-1']);
            }
        };
        $witness = new class implements WitnessResolver {
            public function resolve(SpecDraft $draft, IntentEnvelope $intent, TrustLevel $lane): WitnessContext
            {
                return new WitnessContext(
                    SpecSourceIndependence::HumanWitnessed,
                    DivergenceStatus::NotRequired,
                );
            }
        };
        $ledger = $this->createMock(AtlasEvidenceLedger::class);
        $lookups = 0;
        $ledger->expects(self::exactly(2))->method('eventById')->willReturnCallback(function () use (&$lookups) {
            $lookups++;

            return $lookups === 1 ? null : new AtlasLedgerEvent;
        });
        $ledger->expects(self::once())->method('record');

        $adapter = new AtlasSpecGateAdapter($oracle, $witness, 0, null, $ledger);
        $draft = SpecDraft::fromArray([
            'intent_text' => 'adicionar validação em EmailValidator.php',
            'acceptance_criteria' => [['id' => 'ac-1', 'description' => 'rejects invalid email addresses', 'verification' => 'test', 'verification_ref' => 'test-email-invalid']],
        ]);
        $intent = IntentEnvelope::fromArray([
            'raw_goal' => 'adicionar validação em EmailValidator.php',
            'recognized_verbs' => ['adicionar'],
        ]);

        self::assertSame('freeze', $adapter->contest($draft, $intent, TrustLevel::Dev)->status);
        self::assertSame('freeze', $adapter->contest($draft, $intent, TrustLevel::Dev)->status);
        self::assertSame(2, $lookups);
    }
}
