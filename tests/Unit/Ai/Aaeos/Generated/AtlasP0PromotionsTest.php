<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasP0PromotionsService;
use Tests\TestCase;

class AtlasP0PromotionsTest extends TestCase
{
    public function test_documented_theme_routes_to_its_canonical_owner_doc(): void
    {
        // Doc table: "Atlas Decide Final Architecture" -> kernel architecture owner doc.
        $this->assertSame('atlas-ai-kernel-architecture.md', $this->service()->destinationFor('Atlas Decide Final Architecture'));
        $this->assertSame('super-tool-runtime-core.md', $this->service()->destinationFor('Super Tool Runtime Core'));
        $this->assertSame('atlas-ai-core-vs-domain.md', $this->service()->destinationFor('Domain Profile Orchestration'));
        $this->assertSame('domains/programming.md', $this->service()->destinationFor('Atlas Programming Product Architecture'));
        // Case/whitespace insensitive routing.
        $this->assertSame('super-tool-runtime-core.md', $this->service()->destinationFor('  super   tool runtime core '));
    }

    public function test_missing_stable_decision_patches_the_owner_doc(): void
    {
        // Promotion Rule: a stable, compact, MISSING decision -> patch the owner doc.
        $receipt = $this->service()->promote([
            'source_theme' => 'Atlas Decide Final Architecture',
            'decision' => 'Decide compiles intent, risk and policy into a receipt.',
            'stable_decision' => true,
        ]);

        $this->assertSame('patch', $receipt['action']);
        $this->assertTrue($receipt['will_patch']);
        $this->assertSame('atlas-ai-kernel-architecture.md', $receipt['destination']);
        $this->assertTrue($receipt['destination_is_owner_doc']);
        $this->assertSame([], $receipt['reasons']);
        $this->assertTrue($receipt['rule_compliant']);
    }

    public function test_decision_already_in_destination_is_skipped_not_patched(): void
    {
        // "Patch the owner doc only with the missing decision" -> already present => skip.
        $receipt = $this->service()->promote([
            'source_theme' => 'Domain Profile Orchestration',
            'decision' => 'Domain != flow.',
            'stable_decision' => true,
            'already_in_destination' => true,
        ]);

        $this->assertSame('skip', $receipt['action']);
        $this->assertFalse($receipt['will_patch']);
        // Skipping a present decision is correct behaviour, not a rule violation.
        $this->assertTrue($receipt['rule_compliant']);
    }

    public function test_wholesale_copy_and_new_architecture_path_are_rejected(): void
    {
        // "promoted as compact canonical decisions, not copied wholesale" + anti-pattern
        // "do not reopen resolver files to create a new architecture path".
        $wholesale = $this->service()->promote([
            'source_theme' => 'Super Tool Runtime Core',
            'decision' => 'Entire resolver file pasted in.',
            'stable_decision' => true,
            'copied_wholesale' => true,
        ]);
        $newPath = $this->service()->promote([
            'source_theme' => 'Super Tool Runtime Core',
            'decision' => 'A brand new architecture path.',
            'stable_decision' => true,
            'new_architecture_path' => true,
        ]);

        $this->assertSame('reject', $wholesale['action']);
        $this->assertFalse($wholesale['rule_compliant']);
        $this->assertSame('reject', $newPath['action']);
        $this->assertFalse($newPath['rule_compliant']);
    }

    public function test_backlog_destination_is_not_promoted_as_owner_doc(): void
    {
        // "Gaps/ideas source" routes to the governed backlog, which is NOT an owner
        // doc — it must be governed as backlog, not promoted as canonical authority.
        $receipt = $this->service()->promote([
            'source_theme' => 'Gaps/ideas source',
            'decision' => 'A future idea.',
            'stable_decision' => true,
        ]);

        $this->assertSame('atlas-ai-governed-backlog.md', $receipt['destination']);
        $this->assertFalse($receipt['destination_is_owner_doc']);
        $this->assertSame('reject', $receipt['action']);
    }

    public function test_unknown_theme_has_no_destination_and_is_rejected(): void
    {
        // No documented destination -> cannot route a promotion.
        $this->assertNull($this->service()->destinationFor('Some Unlisted Theme'));

        $receipt = $this->service()->promote([
            'source_theme' => 'Some Unlisted Theme',
            'decision' => 'x',
            'stable_decision' => true,
        ]);

        $this->assertFalse($receipt['theme_recognized']);
        $this->assertSame('reject', $receipt['action']);
    }

    public function test_promotion_table_only_routes_known_themes_and_marks_owner_docs(): void
    {
        $table = $this->service()->promotionTable();

        // Five documented source themes in the doc table.
        $this->assertCount(5, $table);

        $backlogRow = collect($table)->firstWhere('destination', 'atlas-ai-governed-backlog.md');
        $this->assertNotNull($backlogRow);
        $this->assertFalse($backlogRow['is_owner_doc']);

        $kernelRow = collect($table)->firstWhere('destination', 'atlas-ai-kernel-architecture.md');
        $this->assertNotNull($kernelRow);
        $this->assertTrue($kernelRow['is_owner_doc']);
    }

    private function service(): AtlasP0PromotionsService
    {
        return new AtlasP0PromotionsService;
    }
}
