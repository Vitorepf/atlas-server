<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApDocTemplateContract;
use App\Services\Ai\Kernel\Architecture\AtlasApDocumentationGovernanceRegistry;
use Tests\TestCase;

final class AtlasApDocTemplateContractTest extends TestCase
{
    public function test_template_contract_renders_canonical_ap_markdown_for_next_suggested_number(): void
    {
        $nextSuggestedNumber = app(AtlasApDocumentationGovernanceRegistry::class)->summary()['next_suggested_number'];

        $payload = app(AtlasApDocTemplateContract::class)->render(
            title: 'AP Doc Template Contract',
            slug: 'ap-doc-template-contract',
            owner: 'Atlas Documentation Operating System',
            requestedNumber: $nextSuggestedNumber,
            status: 'foundation-contract-implemented',
            lineLimit: 140,
            relatedPaths: [
                'app/Services/Ai/Kernel/Architecture/AtlasApDocTemplateContract.php',
                'tests/Unit/Ai/Kernel/Architecture/AtlasApDocTemplateContractTest.php',
            ],
        );

        $this->assertSame('atlas.ap_doc_template_contract.v1', $payload['schema_version']);
        $this->assertSame('rendered', $payload['status']);
        $this->assertTrue($payload['allowed']);
        $this->assertSame($nextSuggestedNumber, $payload['selected_number']);
        $this->assertSame(
            sprintf('docs/ap/AP-%03d-ap-doc-template-contract.md', $nextSuggestedNumber),
            $payload['doc_path'],
        );
        $this->assertStringContainsString('title: AP Doc Template Contract', $payload['template']);
        $this->assertStringContainsString('line_limit: 140', $payload['template']);
        $this->assertStringContainsString('related_paths:', $payload['template']);
        $this->assertStringContainsString('# AP-'.sprintf('%03d', $nextSuggestedNumber).' - AP Doc Template Contract', $payload['template']);
        $this->assertStringContainsString('## 6. Definition of Done', $payload['template']);
        $this->assertSame([], $payload['violations']);
        $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
        $this->assertFalse(data_get($payload, 'guardrails.creates_ap_doc'));
        $this->assertTrue(data_get($payload, 'guardrails.requires_post_write_governance_validation'));
    }

    public function test_template_contract_blocks_invalid_required_inputs_before_rendering(): void
    {
        $payload = app(AtlasApDocTemplateContract::class)->render(
            title: '',
            slug: 'bad-template',
            owner: '',
            status: '',
            lineLimit: 0,
            relatedPaths: [''],
        );

        $reasons = array_column($payload['violations'], 'reason');

        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse($payload['allowed']);
        $this->assertNull($payload['template']);
        $this->assertContains('ap_template_required_field_missing', $reasons);
        $this->assertContains('ap_template_line_limit_must_be_positive_integer', $reasons);
        $this->assertContains('ap_template_related_path_must_be_non_empty_string', $reasons);
        $this->assertSame('repair_template_inputs_or_ap_governance_before_writing_doc', $payload['next_action']);
    }

    public function test_template_contract_blocks_when_ap_governance_is_blocked(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-template-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-900-first.md', "# AP-900 First\n");
            file_put_contents($dir.'/AP-900-second.md', "# AP-900 Second\n");

            $payload = app(AtlasApDocTemplateContract::class)->render(
                title: 'Blocked Template',
                slug: 'blocked-template',
                owner: 'Atlas Documentation Operating System',
                requestedNumber: 901,
                docsApPath: $dir,
            );

            $this->assertSame('blocked', $payload['status']);
            $this->assertFalse($payload['allowed']);
            $this->assertNull($payload['template']);
            $this->assertSame('ap_documentation_governance_blocked', data_get($payload, 'violations.0.reason'));
            $this->assertSame('blocked', data_get($payload, 'creation_decision.status'));
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
