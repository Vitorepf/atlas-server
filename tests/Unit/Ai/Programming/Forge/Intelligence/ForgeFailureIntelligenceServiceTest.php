<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Forge\Intelligence;

use App\Models\AiForgeWorkPacketExecutionCycle;
use App\Services\Ai\EngineeringKernel\Repair\FailureTaxonomy;
use App\Services\Ai\Programming\Forge\ForgeWorkPacketExecutionCycleCanon;
use App\Services\Ai\Programming\Forge\Intelligence\ForgeFailureIntelligenceService;
use Tests\TestCase;

/**
 * MESMA RAIZ (repair-taxonomy): o Forge agora consulta o cérebro de diagnóstico canônico
 * (RepairDiagnosisStage → FailureTaxonomy) e anexa canonical_class/canonical_strategy SEM
 * trocar o próprio failure_class/repair_hint. Prova: mapeamento canônico, guard pétreo
 * (nunca test_wrong sem sinal explícito) e não-regressão dos campos próprios.
 */
final class ForgeFailureIntelligenceServiceTest extends TestCase
{
    private function cycle(): AiForgeWorkPacketExecutionCycle
    {
        return (new AiForgeWorkPacketExecutionCycle())->forceFill([
            'uuid' => 'cycle-uuid-1',
            'work_packet_canonical_id' => 'wp-1',
        ]);
    }

    public function test_provider_timeout_maps_to_env_flake_rerun(): void
    {
        $capsule = (new ForgeFailureIntelligenceService())
            ->capsule($this->cycle(), 'provider timeout after 60s');

        // Forge mantém o rótulo próprio...
        self::assertSame('provider_runtime_failure', $capsule['failure_class']);
        // ...e ganha a classe/estratégia canônica compartilhada.
        self::assertSame(FailureTaxonomy::ENV_FLAKE, $capsule['canonical_class']);
        self::assertSame(FailureTaxonomy::STRATEGY_RERUN_NO_PROVIDER, $capsule['canonical_strategy']);
    }

    public function test_canonical_brain_classifies_what_forge_string_match_misses(): void
    {
        // "Class Foo not found" não casa nenhuma regra crua do Forge (execution_failure),
        // mas o cérebro canônico determinístico reconhece dependência quebrada.
        $capsule = (new ForgeFailureIntelligenceService())
            ->capsule($this->cycle(), "Class 'App\\Foo' not found");

        self::assertSame('execution_failure', $capsule['failure_class']);
        self::assertSame(FailureTaxonomy::DEPENDENCY_BROKEN, $capsule['canonical_class']);
        self::assertSame(FailureTaxonomy::STRATEGY_ABORT_BLOCKER, $capsule['canonical_strategy']);
    }

    public function test_petreo_never_resolves_test_wrong_without_explicit_signal(): void
    {
        // Reason mencionando "test"/"assertion" JAMAIS pode virar test_wrong pelo Forge:
        // o guard pétreo exige sinal explícito de spec congelada, que o Forge não emite.
        $capsule = (new ForgeFailureIntelligenceService())
            ->capsule($this->cycle(), 'test_assertion_failed_in_provider_router');

        self::assertSame('verification_failure', $capsule['failure_class']);
        self::assertNotSame(FailureTaxonomy::TEST_WRONG, $capsule['canonical_class']);
        self::assertSame(FailureTaxonomy::IMPL_BUG, $capsule['canonical_class']);
        self::assertSame(FailureTaxonomy::STRATEGY_REGENERATE_WITH_HINT, $capsule['canonical_strategy']);
    }

    public function test_existing_fields_and_schema_unchanged(): void
    {
        $capsule = (new ForgeFailureIntelligenceService())
            ->capsule($this->cycle(), 'assertion failed: expected 1 got 2');

        self::assertSame('atlas.forge.failure_intelligence.v1', $capsule['schema_version']);
        self::assertSame(ForgeWorkPacketExecutionCycleCanon::REPAIR_HINT_ADD_TESTS, $capsule['repair_hint']);
        self::assertArrayHasKey('canonical_class', $capsule);
        self::assertArrayHasKey('canonical_strategy', $capsule);
        self::assertSame(64, strlen((string) $capsule['failure_hash']));
    }
}
