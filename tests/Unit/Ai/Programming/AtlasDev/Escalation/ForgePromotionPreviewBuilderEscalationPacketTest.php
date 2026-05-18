<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Escalation;

use App\Services\Ai\Programming\AtlasDev\Escalation\DevToForgeEscalationPacketFactory;
use App\Services\Ai\Programming\AtlasDev\Escalation\ForgePromotionPreviewBuilder;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EscalationSignals;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationDecision;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationPacket;
use PHPUnit\Framework\TestCase;

/**
 * Minimal integration test: the existing ForgePromotionPreviewBuilder is the
 * Dev-side handoff point where the canonical packet is emitted ALONGSIDE the
 * legacy `forge_promotion_preview.v1` payload. This test guards the
 * additive contract.
 */
final class ForgePromotionPreviewBuilderEscalationPacketTest extends TestCase
{
    public function test_legacy_preview_keys_unchanged_when_no_intent_supplied(): void
    {
        $builder = new ForgePromotionPreviewBuilder;
        $payload = $builder->build(
            $this->decision(),
            intentSummary: 'Refactor auth',
            changedFiles: ['app/Auth/Login.php'],
            contextRefs: ['docs/auth.md'],
            workspaceHash: 'sha256:ws',
            threadId: null,
        );

        $this->assertArrayNotHasKey('escalation_packet_v1', $payload);
        $this->assertSame(ForgePromotionPreviewBuilder::SCHEMA_VERSION, $payload['schema_version']);
    }

    public function test_canonical_escalation_packet_is_emitted_when_intent_and_reason_supplied(): void
    {
        $builder = new ForgePromotionPreviewBuilder;
        $payload = $builder->build(
            $this->decision(),
            intentSummary: 'refactor auth subsystem for SSO',
            changedFiles: ['app/Auth/Login.php'],
            contextRefs: ['docs/auth.md'],
            workspaceHash: 'sha256:ws',
            threadId: 'thread-42',
            originalUserIntent: 'Refactor the auth subsystem to support SSO',
            promotionReason: 'scope_too_large + sdd_required',
        );

        $this->assertArrayHasKey('escalation_packet_v1', $payload);
        $packet = $payload['escalation_packet_v1'];
        $this->assertSame(EscalationPacket::SCHEMA_VERSION, $packet['schema_version']);
        $this->assertSame('atlas_dev', $packet['source_core']);
        $this->assertSame('atlas_forge', $packet['target_core']);
        $this->assertSame('Refactor the auth subsystem to support SSO', $packet['original_user_intent']);
        $this->assertSame('scope_too_large + sdd_required', $packet['promotion_reason']);
        $this->assertNotEmpty($packet['promotion_triggers']);
        $this->assertNotEmpty($packet['suggested_work_packets']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $packet['packet_hash']);

        // Context flowed through the factory: contextRefs + workspaceHash.
        $this->assertSame(['docs/auth.md'], $packet['context_refs']);
        $this->assertSame('sha256:ws', $packet['context_pack_hash']);

        // Canonical evidence_refs 6-slot map must always be present.
        foreach (EscalationPacket::EVIDENCE_REF_SLOTS as $slot) {
            $this->assertArrayHasKey($slot, $packet['evidence_refs']);
        }
    }

    public function test_packet_emission_uses_injected_factory_when_supplied(): void
    {
        $builder = new ForgePromotionPreviewBuilder;
        $factory = new DevToForgeEscalationPacketFactory;
        $payload = $builder->build(
            $this->decision(),
            intentSummary: 'noop',
            changedFiles: [],
            contextRefs: [],
            workspaceHash: null,
            threadId: null,
            originalUserIntent: 'Test the seam',
            promotionReason: 'integration_test',
            packetFactory: $factory,
        );

        $this->assertArrayHasKey('escalation_packet_v1', $payload);
        $this->assertSame('integration_test', $payload['escalation_packet_v1']['promotion_reason']);
    }

    public function test_blank_original_intent_does_not_emit_packet(): void
    {
        $builder = new ForgePromotionPreviewBuilder;
        $payload = $builder->build(
            $this->decision(),
            intentSummary: 'noop',
            changedFiles: [],
            contextRefs: [],
            workspaceHash: null,
            threadId: null,
            originalUserIntent: '   ',
            promotionReason: 'non_empty_reason',
        );

        $this->assertArrayNotHasKey('escalation_packet_v1', $payload);
    }

    private function decision(): EscalationDecision
    {
        return EscalationDecision::issue(
            runId: 'run-1',
            taskContractHash: 'tch',
            triggeredAt: '2026-05-18T10:00:00Z',
            target: EscalationDecision::TARGET_FORGE,
            reasons: ['scope_too_large', 'sdd_required'],
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
