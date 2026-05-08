<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApWorkIntakeContract;
use Tests\TestCase;

final class AtlasApWorkIntakeContractTest extends TestCase
{
    public function test_work_intake_recommends_existing_ap_when_paths_are_already_documented(): void
    {
        $payload = app(AtlasApWorkIntakeContract::class)->evaluate(
            workTitle: 'Improve AP Manifest',
            intendedPaths: [
                'app/Services/Ai/Kernel/Architecture/AtlasApDocumentationManifest.php',
            ],
        );

        $this->assertSame('atlas.ap_work_intake_contract.v1', $payload['schema_version']);
        $this->assertSame('existing_ap_review_required', $payload['status']);
        $this->assertSame('update_existing_ap_scope', data_get($payload, 'recommendation.decision'));
        $this->assertSame('intended_paths_already_documented', data_get($payload, 'recommendation.reason'));
        $this->assertContains('AP-193', array_column(data_get($payload, 'recommendation.target_aps'), 'ap'));
        $this->assertSame(0, data_get($payload, 'change_impact.uncovered_changed_path_count'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
        $this->assertFalse(data_get($payload, 'guardrails.runs_semantic_ai_matching'));
        $this->assertTrue(data_get($payload, 'guardrails.requires_agent_to_review_recommended_ap_before_editing'));
    }

    public function test_work_intake_allows_new_ap_when_paths_are_uncovered_and_creation_is_allowed(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-work-intake-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-040-existing.md', "# AP-040 - Existing\n");

            $payload = app(AtlasApWorkIntakeContract::class)->evaluate(
                workTitle: 'New Safe Block',
                intendedPaths: ['app/New/SafeBlock.php'],
                docsApPath: $dir,
                requestedApNumber: 41,
            );

            $this->assertSame('new_ap_allowed', $payload['status']);
            $this->assertSame('create_new_ap_for_uncovered_scope', data_get($payload, 'recommendation.decision'));
            $this->assertSame('new-safe-block', $payload['proposed_slug']);
            $this->assertSame('docs/ap/AP-041-new-safe-block.md', data_get($payload, 'recommendation.recommended_doc_path'));
            $this->assertSame(1, data_get($payload, 'change_impact.uncovered_changed_path_count'));
            $this->assertSame('allowed', data_get($payload, 'creation_decision.status'));
            $this->assertSame('create_new_ap_via_ap192_template_then_update_related_paths', $payload['next_action']);
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function test_work_intake_blocks_invalid_inputs_before_recommending_scope(): void
    {
        $payload = app(AtlasApWorkIntakeContract::class)->evaluate(
            workTitle: '',
            proposedSlug: 'Bad Slug',
        );

        $reasons = array_column($payload['violations'], 'reason');

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('repair_intake_inputs', data_get($payload, 'recommendation.decision'));
        $this->assertContains('work_title_required', $reasons);
        $this->assertContains('work_slug_must_be_lowercase_kebab_case', $reasons);
        $this->assertSame('repair_work_title_or_slug_before_ap_intake', $payload['next_action']);
    }

    public function test_work_intake_blocks_when_ap_governance_is_attention(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-work-intake-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-050-first.md', "# AP-050 - First\n");
            file_put_contents($dir.'/AP-050-second.md', "# AP-050 - Second\n");

            $payload = app(AtlasApWorkIntakeContract::class)->evaluate(
                workTitle: 'Blocked Work',
                intendedPaths: ['app/Blocked.php'],
                docsApPath: $dir,
                requestedApNumber: 51,
            );

            $this->assertSame('blocked', $payload['status']);
            $this->assertSame('repair_ap_governance', data_get($payload, 'recommendation.decision'));
            $this->assertSame('ap_governance_attention', data_get($payload, 'recommendation.reason'));
            $this->assertSame('attention', data_get($payload, 'change_impact.status'));
            $this->assertSame('blocked', data_get($payload, 'creation_decision.status'));
            $this->assertSame('repair_ap_governance_before_work_intake', $payload['next_action']);
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
