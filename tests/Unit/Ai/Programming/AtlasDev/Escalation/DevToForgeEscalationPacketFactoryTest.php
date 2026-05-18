<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Escalation;

use App\Services\Ai\Programming\AtlasDev\Escalation\DevToForgeEscalationPacketFactory;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EscalationSignals;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationDecision;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationPacket;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DevToForgeEscalationPacketFactoryTest extends TestCase
{
    public function test_factory_builds_canonical_packet_from_decision(): void
    {
        $factory = new DevToForgeEscalationPacketFactory;
        $decision = $this->decision(['sdd_required', 'risk_level_r4_forces_forge']);

        $packet = $factory->fromEscalationDecision(
            decision: $decision,
            originalUserIntent: 'Refactor the auth subsystem to support SSO',
            promotionReason: 'scope crosses the Obra threshold; SDD required',
        );

        $this->assertInstanceOf(EscalationPacket::class, $packet);
        $payload = $packet->toCanonicalArray();
        $this->assertSame(EscalationPacket::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('atlas_dev', $payload['source_core']);
        $this->assertSame('atlas_forge', $payload['target_core']);
        $this->assertSame('Refactor the auth subsystem to support SSO', $payload['original_user_intent']);
        $this->assertSame('scope crosses the Obra threshold; SDD required', $payload['promotion_reason']);
        $this->assertContains('sdd_required', $payload['promotion_triggers']);
    }

    public function test_factory_defaults_promotion_triggers_from_decision_reasons(): void
    {
        $factory = new DevToForgeEscalationPacketFactory;
        $decision = $this->decision(['scope_too_large', 'time_budget_exceeded']);

        $packet = $factory->fromEscalationDecision(
            decision: $decision,
            originalUserIntent: 'Refactor X',
            promotionReason: 'scope_too_large',
        );

        $this->assertSame(['scope_too_large', 'time_budget_exceeded'], $packet->promotionTriggers);
    }

    public function test_factory_default_forge_mode_for_sdd_reason(): void
    {
        $factory = new DevToForgeEscalationPacketFactory;
        $decision = $this->decision(['sdd_required']);

        $packet = $factory->fromEscalationDecision(
            decision: $decision,
            originalUserIntent: 'Implement X',
            promotionReason: 'SDD required for X',
        );

        $this->assertSame(EscalationPacket::RECOMMENDED_FORGE_MODE_SDD_INTAKE, $packet->recommendedForgeMode);
    }

    public function test_factory_default_forge_mode_for_architecture_reason(): void
    {
        $factory = new DevToForgeEscalationPacketFactory;
        $decision = $this->decision(['architecture_change_needed']);

        $packet = $factory->fromEscalationDecision(
            decision: $decision,
            originalUserIntent: 'Refactor architecture',
            promotionReason: 'architecture review needed',
        );

        $this->assertSame(EscalationPacket::RECOMMENDED_FORGE_MODE_ARCHITECTURE_REVIEW, $packet->recommendedForgeMode);
    }

    public function test_factory_default_forge_mode_for_long_run_reason(): void
    {
        $factory = new DevToForgeEscalationPacketFactory;
        $decision = $this->decision(['time_budget_exceeded']);

        $packet = $factory->fromEscalationDecision(
            decision: $decision,
            originalUserIntent: 'long task',
            promotionReason: 'time budget exceeded',
        );

        $this->assertSame(EscalationPacket::RECOMMENDED_FORGE_MODE_LONG_RUN, $packet->recommendedForgeMode);
    }

    public function test_factory_default_forge_mode_falls_back_to_obra_intake(): void
    {
        $factory = new DevToForgeEscalationPacketFactory;
        $decision = $this->decision(['scope_too_large']);

        $packet = $factory->fromEscalationDecision(
            decision: $decision,
            originalUserIntent: 'big change',
            promotionReason: 'scope_too_large',
        );

        $this->assertSame(EscalationPacket::RECOMMENDED_FORGE_MODE_OBRA_INTAKE, $packet->recommendedForgeMode);
    }

    public function test_factory_emits_default_suggested_work_packet(): void
    {
        $factory = new DevToForgeEscalationPacketFactory;
        $decision = $this->decision(['sdd_required']);

        $packet = $factory->fromEscalationDecision(
            decision: $decision,
            originalUserIntent: 'X',
            promotionReason: 'why',
        );

        $this->assertNotEmpty($packet->suggestedWorkPackets);
        $this->assertSame('wp_sdd_intake', $packet->suggestedWorkPackets[0]['id']);
        $this->assertSame('programming.forge', $packet->suggestedWorkPackets[0]['capability']);
    }

    public function test_factory_propagates_caller_supplied_work_packets(): void
    {
        $factory = new DevToForgeEscalationPacketFactory;
        $decision = $this->decision(['scope_too_large']);

        $packets = [
            ['id' => 'wp_auth', 'title' => 'SSO provider abstraction'],
            ['id' => 'wp_session', 'title' => 'Session storage migration'],
        ];
        $packet = $factory->fromEscalationDecision(
            decision: $decision,
            originalUserIntent: 'Refactor auth',
            promotionReason: 'scope',
            suggestedWorkPackets: $packets,
        );

        $this->assertCount(2, $packet->suggestedWorkPackets);
        $this->assertSame('wp_auth', $packet->suggestedWorkPackets[0]['id']);
    }

    public function test_factory_uses_decision_triggered_at_as_created_at(): void
    {
        $factory = new DevToForgeEscalationPacketFactory;
        $decision = $this->decision(['scope_too_large']);

        $packet = $factory->fromEscalationDecision(
            decision: $decision,
            originalUserIntent: 'X',
            promotionReason: 'why',
        );

        $this->assertSame($decision->triggeredAt, $packet->createdAt);
    }

    public function test_factory_throws_when_original_user_intent_is_empty(): void
    {
        $factory = new DevToForgeEscalationPacketFactory;
        $decision = $this->decision(['scope_too_large']);

        $this->expectException(InvalidArgumentException::class);
        $factory->fromEscalationDecision(
            decision: $decision,
            originalUserIntent: '',
            promotionReason: 'why',
        );
    }

    public function test_factory_throws_when_promotion_reason_is_empty(): void
    {
        $factory = new DevToForgeEscalationPacketFactory;
        $decision = $this->decision(['scope_too_large']);

        $this->expectException(InvalidArgumentException::class);
        $factory->fromEscalationDecision(
            decision: $decision,
            originalUserIntent: 'X',
            promotionReason: '   ',
        );
    }

    /**
     * @param  list<string>  $reasons
     */
    private function decision(array $reasons): EscalationDecision
    {
        return EscalationDecision::issue(
            runId: 'run-1',
            taskContractHash: 'tch',
            triggeredAt: '2026-05-18T10:00:00Z',
            target: EscalationDecision::TARGET_FORGE,
            reasons: $reasons,
            signals: new EscalationSignals(
                fileCount: 7,
                layersTouched: 3,
                riskKeywords: ['auth'],
                contextRequiredChars: 12000,
                threadMessages: 6,
                priorFailureCount: 1,
            ),
            score: 8,
            riskLevel: 'R4',
            humanActionRequired: true,
            previewArtifactPath: '/storage/run-1/preview.json',
        );
    }
}
