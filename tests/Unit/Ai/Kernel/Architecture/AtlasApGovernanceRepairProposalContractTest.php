<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApGovernanceRepairProposalContract;
use Tests\TestCase;

final class AtlasApGovernanceRepairProposalContractTest extends TestCase
{
    public function test_current_repair_contract_is_proposal_only(): void
    {
        $payload = app(AtlasApGovernanceRepairProposalContract::class)->proposals();

        $this->assertSame('atlas.ap_governance_repair_proposal_contract.v1', $payload['schema_version']);
        $this->assertContains($payload['status'], ['ok', 'attention']);
        $this->assertSame('proposal_only', $payload['mode']);
        $this->assertSame('ap_governance_repair_proposals_only_no_file_writes', $payload['authority']);
        $this->assertIsArray($payload['proposals']);
        $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
        $this->assertFalse(data_get($payload, 'guardrails.applies_repairs'));
        $this->assertFalse(data_get($payload, 'guardrails.creates_ap_docs'));
        $this->assertTrue(data_get($payload, 'guardrails.requires_human_review'));
    }

    public function test_repair_contract_returns_ok_when_temp_docs_are_clean(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-repair-proposals-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-700-clean.md', implode("\n", [
                '---',
                'title: AP-700 Clean',
                'status: implemented',
                'line_limit: 80',
                '---',
                '# AP-700 Clean',
                '',
            ]));

            $payload = app(AtlasApGovernanceRepairProposalContract::class)->proposals($dir);

            $this->assertSame('ok', $payload['status']);
            $this->assertSame(0, $payload['proposal_count']);
            $this->assertSame([], $payload['proposals']);
            $this->assertSame('continue_ap_development_with_documentation_governance_clean', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_repair_contract_translates_governance_and_dependency_findings_into_human_review_proposals(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-repair-proposals-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-710-first.md', "# AP-710 First\n");
            file_put_contents($dir.'/AP-710-second.md', "# AP-710 Second\n");
            file_put_contents($dir.'/AP-bad-name.md', "# Bad\n");
            file_put_contents($dir.'/AP-711-missing-path.md', implode("\n", [
                '---',
                'title: AP-711 Missing Path',
                'status: implemented',
                'related_paths:',
                '  - app/Services/Ai/Kernel/Architecture/MissingRepairTarget.php',
                '---',
                '# AP-711 Missing Path',
                '',
            ]));
            file_put_contents($dir.'/AP-712-too-long.md', implode("\n", [
                '---',
                'title: AP-712 Too Long',
                'status: implemented',
                'line_limit: 5',
                '---',
                '# AP-712 Too Long',
                'one',
                'two',
                'three',
                'four',
                'five',
                '',
            ]));
            file_put_contents($dir.'/AP-713-frontmatter.md', implode("\n", [
                '---',
                'title:',
                'line_limit: nope',
                '---',
                '# AP-713 Frontmatter',
                '',
            ]));
            file_put_contents($dir.'/AP-714-status.md', implode("\n", [
                '---',
                'title: AP-714 Status',
                'status: maybe-ready',
                '---',
                '# AP-714 Status',
                '',
                'This doc references AP-999.',
                '',
            ]));

            $payload = app(AtlasApGovernanceRepairProposalContract::class)->proposals($dir);
            $reasons = array_column($payload['proposals'], 'reason');

            $this->assertSame('attention', $payload['status']);
            $this->assertSame('review_repair_proposals_before_creating_or_editing_ap_docs', $payload['next_action']);
            $this->assertContains('duplicate_ap_numbers', $reasons);
            $this->assertContains('malformed_ap_filenames', $reasons);
            $this->assertContains('missing_ap_related_paths', $reasons);
            $this->assertContains('ap_line_limit_overflow', $reasons);
            $this->assertContains('ap_frontmatter_shape_violation', $reasons);
            $this->assertContains('ap_status_taxonomy_violation', $reasons);
            $this->assertContains('repair_missing_ap_reference', $reasons);
            $this->assertNotEmpty(data_get($payload, 'proposals.0.proposal_id'));
            $this->assertSame('blocking', data_get($payload, 'proposals.0.severity'));
            $this->assertIsArray(data_get($payload, 'proposals.0.source_refs'));
            $this->assertSame(1, data_get($payload, 'source_reports.dependency_map.missing_reference_count'));
        } finally {
            $this->removeDir($dir);
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}
