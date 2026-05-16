<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Escalation;

use App\Services\Ai\Programming\AtlasDev\Escalation\ForgePromotionPreviewBuilder;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EscalationSignals;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationDecision;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ForgePromotionPreviewBuilderTest extends TestCase
{
    public function test_forge_preview_emits_canonical_payload(): void
    {
        $builder = new ForgePromotionPreviewBuilder();
        $decision = $this->decision(
            target: EscalationDecision::TARGET_FORGE,
            riskLevel: 'R4',
            score: 8,
            humanActionRequired: true,
        );

        $payload = $builder->build(
            $decision,
            intentSummary: 'Refactor auth flow',
            changedFiles: ['app/Auth/Login.php'],
            contextRefs: ['docs/auth.md#sha256:abc'],
            workspaceHash: 'sha256:ws',
            threadId: null,
        );

        $this->assertSame(ForgePromotionPreviewBuilder::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('forge_obra', $payload['promotion_tier']);
        $this->assertSame('pending_human_action', $payload['submission_state']);
        $this->assertTrue($payload['human_action_required']);
        $this->assertSame(EscalationDecision::TARGET_FORGE, $payload['target']);
        $this->assertSame($decision->decisionHash, $payload['decision_hash']);
        $this->assertSame('Refactor auth flow', $payload['intent_summary']);
        $this->assertSame(['app/Auth/Login.php'], $payload['changed_files']);
        $this->assertSame(['docs/auth.md#sha256:abc'], $payload['context_refs']);
        $this->assertSame('sha256:ws', $payload['workspace_hash']);
    }

    public function test_obra_candidate_preview_uses_obra_tier(): void
    {
        $builder = new ForgePromotionPreviewBuilder();
        $decision = $this->decision(
            target: EscalationDecision::TARGET_OBRA_CANDIDATE,
            riskLevel: 'R2',
            score: 4,
            humanActionRequired: false,
        );

        $payload = $builder->build(
            $decision,
            intentSummary: 'Maybe deserves an Obra',
            changedFiles: [],
        );

        $this->assertSame('obra_candidate', $payload['promotion_tier']);
        $this->assertFalse($payload['human_action_required']);
    }

    public function test_empty_intent_summary_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ForgePromotionPreviewBuilder())->build(
            $this->decision(),
            intentSummary: '   ',
            changedFiles: [],
        );
    }

    public function test_invalid_changed_file_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ForgePromotionPreviewBuilder())->build(
            $this->decision(),
            intentSummary: 'intent',
            // @phpstan-ignore-next-line — test invalid input intentionally
            changedFiles: ['app/Foo.php', ''],
        );
    }

    public function test_payload_keys_are_alphabetically_sorted(): void
    {
        $builder = new ForgePromotionPreviewBuilder();
        $payload = $builder->build(
            $this->decision(),
            intentSummary: 'intent',
            changedFiles: ['app/Foo.php'],
        );

        $keys = array_keys($payload);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys);
    }

    private function decision(
        string $target = EscalationDecision::TARGET_FORGE,
        string $riskLevel = 'R4',
        int $score = 8,
        bool $humanActionRequired = true,
    ): EscalationDecision {
        return EscalationDecision::issue(
            runId: 'run-1',
            taskContractHash: 'tch',
            triggeredAt: '2026-05-16T10:00:00Z',
            target: $target,
            reasons: ['risk_level_r4_forces_forge', 'file_count_gt_5(+2)'],
            signals: new EscalationSignals(
                fileCount: 7,
                layersTouched: 3,
                riskKeywords: ['auth'],
                contextRequiredChars: 12000,
                threadMessages: 6,
                priorFailureCount: 1,
            ),
            score: $score,
            riskLevel: $riskLevel,
            humanActionRequired: $humanActionRequired,
            previewArtifactPath: '/storage/run-1/preview.json',
        );
    }
}
