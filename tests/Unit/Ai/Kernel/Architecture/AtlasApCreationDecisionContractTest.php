<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApCreationDecisionContract;
use App\Services\Ai\Kernel\Architecture\AtlasApDocumentationGovernanceRegistry;
use Tests\TestCase;

final class AtlasApCreationDecisionContractTest extends TestCase
{
    public function test_current_ap_creation_decision_allows_next_suggested_number(): void
    {
        $nextSuggestedNumber = app(AtlasApDocumentationGovernanceRegistry::class)->summary()['next_suggested_number'];

        $payload = app(AtlasApCreationDecisionContract::class)->decide(
            requestedNumber: $nextSuggestedNumber,
            proposedSlug: 'safe-ap-creation-contract',
        );

        $this->assertSame('atlas.ap_creation_decision_contract.v1', $payload['schema_version']);
        $this->assertSame('allowed', $payload['status']);
        $this->assertTrue($payload['allowed']);
        $this->assertSame($nextSuggestedNumber, $payload['selected_number']);
        $this->assertSame(
            sprintf('docs/ap/AP-%03d-safe-ap-creation-contract.md', $nextSuggestedNumber),
            $payload['recommended_doc_path'],
        );
        $this->assertSame([], $payload['violations']);
        $this->assertSame('ok', data_get($payload, 'governance.status'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
        $this->assertFalse(data_get($payload, 'guardrails.creates_ap_doc'));
        $this->assertTrue(data_get($payload, 'guardrails.requires_human_or_agent_to_write_doc_after_decision'));
    }

    public function test_creation_decision_blocks_when_requested_number_skips_registry_sequence(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-creation-decision-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-700-existing.md', "# AP-700 Existing\n");

            $payload = app(AtlasApCreationDecisionContract::class)->decide(
                docsApPath: $dir,
                requestedNumber: 702,
                proposedSlug: 'skipped-number',
            );

            $this->assertSame('blocked', $payload['status']);
            $this->assertFalse($payload['allowed']);
            $this->assertSame(701, $payload['next_suggested_number']);
            $this->assertSame('requested_ap_number_must_match_next_suggested_number', data_get($payload, 'violations.0.reason'));
            $this->assertSame('repair_governance_or_use_next_suggested_ap_number_before_creation', $payload['next_action']);
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function test_creation_decision_blocks_invalid_slug_and_governance_blockers(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-creation-decision-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-800-first.md', "# AP-800 First\n");
            file_put_contents($dir.'/AP-800-second.md', "# AP-800 Second\n");

            $payload = app(AtlasApCreationDecisionContract::class)->decide(
                docsApPath: $dir,
                requestedNumber: 801,
                proposedSlug: 'Bad Slug',
            );

            $reasons = array_column($payload['violations'], 'reason');

            $this->assertSame('blocked', $payload['status']);
            $this->assertContains('ap_documentation_governance_blocked', $reasons);
            $this->assertContains('proposed_ap_slug_must_be_lowercase_kebab_case', $reasons);
            $this->assertNull($payload['recommended_doc_path']);
            $this->assertSame('attention', data_get($payload, 'governance.status'));
            $this->assertSame(1, data_get($payload, 'governance.blocker_count'));
            $this->assertSame('duplicate_ap_numbers', data_get($payload, 'governance.blockers.0.reason'));
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
