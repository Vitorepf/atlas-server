<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskRespecPlanBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasTaskRespecPlanBuilder: clean facts ⇒ keep; hidden poison ⇒ give_back_hint;
 * too_many_deficiencies ⇒ quarantine_candidate; contradictory_acceptance ⇒ rewrite_objective;
 * missing_files ⇒ add_missing_allowed_file_candidate; too_broad_scope ⇒ split_task_candidate;
 * autonomy_regression ⇒ split_task_candidate with replaces_authority='non_atlas_native' (Atlas-native
 * replacement, never human dependency).
 */
final class AtlasTaskRespecPlanBuilderTest extends TestCase
{
    public function test_clean_facts_yield_keep(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build(['packet_id' => 'p-clean']);
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_KEEP, $r['action']);
    }

    public function test_too_many_deficiencies_yield_quarantine(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build(['packet_id' => 'p-too-many', 'too_many_deficiencies' => true]);
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_QUARANTINE, $r['action']);
    }

    public function test_cli_clobber_yields_quarantine(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build(['packet_id' => 'p-cli', 'cli_clobber' => true]);
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_QUARANTINE, $r['action']);
    }

    public function test_contradictory_acceptance_yields_rewrite_objective(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build(['packet_id' => 'p-c', 'contradictory_acceptance' => true]);
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_REWRITE_OBJECTIVE, $r['action']);
    }

    public function test_missing_files_yield_add_missing_allowed_file_candidate(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build(['packet_id' => 'p-m', 'missing_files' => ['app/X.php']]);
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_ADD_FILE, $r['action']);
    }

    public function test_autonomy_regression_yields_split_candidate_with_atlas_native_replacement(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build(['packet_id' => 'p-ar', 'autonomy_regression' => true]);
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_SPLIT, $r['action']);
        $this->assertSame('non_atlas_native', $r['replaces_authority']);
    }

    public function test_too_broad_scope_yields_split_candidate(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build(['packet_id' => 'p-b', 'hidden_poison_facts' => ['too_broad_scope']]);
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_SPLIT, $r['action']);
    }

    public function test_named_hidden_poison_yields_give_back_hint(): void
    {
        $r = (new AtlasTaskRespecPlanBuilder)->build(['packet_id' => 'p-h', 'hidden_poison_facts' => ['stale_dependency']]);
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_GIVE_BACK, $r['action']);
    }
}
