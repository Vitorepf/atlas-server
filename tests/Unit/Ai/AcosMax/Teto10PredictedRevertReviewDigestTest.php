<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\AcosMax\Teto10PredictedRevertReviewDigest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TETO-10 acceptance (§3144-3147): digest as review product.
 */
final class Teto10PredictedRevertReviewDigestTest extends TestCase
{
    #[Test]
    public function groups_by_decision_or_family_and_orders_by_predicted_revert_band(): void
    {
        $digest = Teto10PredictedRevertReviewDigest::compose([
            $this->item('low-1', 'Docs cleanup', 'low', null, 'docs'),
            $this->item('high-1', 'Rollback lineage fix', 'high', 'ASI-11', 'lineage'),
            $this->item('sweet-1', 'Ask cadence tune', 'sweet', 'MULTN15-05', 'operator-asks'),
            $this->item('high-2', 'Review advisory ordering', 'high', 'MULTN15-08', 'review'),
            $this->item('low-2', 'Scoreboard wording', 'low', null, 'docs'),
            $this->item('sweet-2', 'Held queue surfacing', 'sweet', 'FEE-12', 'review'),
            $this->item('high-3', 'Obra lineage stamped', 'high', 'MULTH-06', 'lineage'),
            $this->item('low-3', 'Markdown cap', 'low', null, 'review'),
            $this->item('sweet-3', 'Diff ref normalization', 'sweet', 'MAXH-04', 'handles'),
            $this->item('low-4', 'Evidence label', 'low', null, 'evidence'),
        ]);

        $this->assertSame(Teto10PredictedRevertReviewDigest::SCHEMA_VERSION, $digest['schema_version']);
        $this->assertSame('ok', $digest['status']);
        $this->assertSame(10, $digest['item_count']);
        $this->assertGreaterThanOrEqual(5, $digest['group_count']);

        $firstGroup = $digest['groups'][0];
        $this->assertSame('decision:ASI-11', $firstGroup['group_key']);
        $this->assertSame('high', $firstGroup['highest_predicted_revert_band']);
        $this->assertSame('high-1', $firstGroup['items'][0]['id']);

        $bandsInOrder = array_map(
            static fn (array $group): string => (string) $group['highest_predicted_revert_band'],
            $digest['groups'],
        );
        $this->assertSame(['high', 'high', 'high'], array_slice($bandsInOrder, 0, 3));

        $firstItem = $firstGroup['items'][0];
        $this->assertSame(['evidence:high-1'], $firstItem['evidence_refs']);
        $this->assertSame('diff:high-1', $firstItem['diff_ref']);
        $this->assertSame('php artisan atlas:rollback-cascade --decision-id=ASI-11 --dry-run', $firstItem['reverse_command']);
        $this->assertSame('reversible', $firstItem['review_mode']);
    }

    #[Test]
    public function item_without_ready_reverse_command_is_manual_review_not_reversible(): void
    {
        $digest = Teto10PredictedRevertReviewDigest::compose([
            [
                'id' => 'manual-1',
                'title' => 'Needs human diff inspection',
                'family' => 'manual',
                'predicted_revert_band' => 'high',
                'evidence_refs' => ['evidence:manual-1'],
                'diff_ref' => 'diff:manual-1',
            ],
        ]);

        $item = $digest['groups'][0]['items'][0];

        $this->assertSame('manual_review', $item['review_mode']);
        $this->assertSame('manual_review', $item['reverse_command']);

        $markdown = Teto10PredictedRevertReviewDigest::renderMarkdown($digest);
        $this->assertStringContainsString('manual_review', $markdown);
        $this->assertStringContainsString('reverse: `manual_review`', $markdown);
    }

    #[Test]
    public function consolidates_pending_flips_and_batched_asks(): void
    {
        $digest = Teto10PredictedRevertReviewDigest::compose([
            $this->item('flip-1', 'Enforce flip candidate', 'high', 'ELEV-26', 'flips', [
                'pending_flip' => true,
                'flip_ref' => 'flip:ELEV-26',
            ]),
            $this->item('ask-1', 'Operator ask batched', 'sweet', 'MULTN15-05', 'asks', [
                'batched_ask' => true,
                'ask_ref' => 'ask:MULTN15-05',
            ]),
        ]);

        $this->assertSame(1, $digest['pending_flips']['count']);
        $this->assertSame('flip:ELEV-26', $digest['pending_flips']['items'][0]['flip_ref']);
        $this->assertSame(1, $digest['batched_asks']['count']);
        $this->assertSame('ask:MULTN15-05', $digest['batched_asks']['items'][0]['ask_ref']);

        $markdown = Teto10PredictedRevertReviewDigest::renderMarkdown($digest);
        $this->assertStringContainsString('## Pending flips', $markdown);
        $this->assertStringContainsString('## Batched asks', $markdown);
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function item(string $id, string $title, string $band, ?string $decisionId, string $family, array $extra = []): array
    {
        return $extra + [
            'id' => $id,
            'title' => $title,
            'decision_id' => $decisionId,
            'family' => $family,
            'predicted_revert_band' => $band,
            'evidence_refs' => ['evidence:'.$id],
            'diff_ref' => 'diff:'.$id,
            'reverse_command' => 'php artisan atlas:rollback-cascade --decision-id='.($decisionId ?? $family).' --dry-run',
        ];
    }
}
