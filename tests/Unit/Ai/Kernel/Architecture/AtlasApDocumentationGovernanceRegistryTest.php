<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApDocumentationGovernanceRegistry;
use Tests\TestCase;

final class AtlasApDocumentationGovernanceRegistryTest extends TestCase
{
    public function test_current_ap_governance_registry_is_ok_and_read_only(): void
    {
        $payload = app(AtlasApDocumentationGovernanceRegistry::class)->summary();

        $this->assertSame('atlas.ap_documentation_governance_registry.v1', $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('read_only_registry', $payload['mode']);
        $this->assertSame('ap_documentation_governance_only_no_file_writes', $payload['authority']);
        $this->assertSame(5, $payload['audit_count']);
        $this->assertSame(0, $payload['blocker_count']);
        $this->assertSame([], $payload['blockers']);
        $this->assertSame('continue_ap_development_with_next_suggested_number', $payload['next_action']);
        $this->assertGreaterThanOrEqual(188, $payload['next_suggested_number']);
        $this->assertSame('ok', data_get($payload, 'audits.number_registry.status'));
        $this->assertSame('ok', data_get($payload, 'audits.implementation_links.status'));
        $this->assertSame('ok', data_get($payload, 'audits.line_limits.status'));
        $this->assertSame('ok', data_get($payload, 'audits.frontmatter.status'));
        $this->assertSame('ok', data_get($payload, 'audits.status_taxonomy.status'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
        $this->assertFalse(data_get($payload, 'guardrails.renumbers_files'));
        $this->assertFalse(data_get($payload, 'guardrails.creates_missing_files'));
        $this->assertTrue(data_get($payload, 'guardrails.safe_for_architecture_readiness_embedding'));
    }

    public function test_registry_surfaces_number_and_link_blockers_from_temp_ap_docs(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-governance-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-400-first.md', "# AP-400 First\n");
            file_put_contents($dir.'/AP-400-second.md', "# AP-400 Second\n");
            file_put_contents($dir.'/AP-bad-name.md', "# Bad\n");
            file_put_contents($dir.'/AP-401-linked.md', implode("\n", [
                '---',
                'title: AP-401 Linked',
                'related_paths:',
                '  - app/Services/Ai/Kernel/Architecture/MissingRegistryTarget.php',
                '---',
                '# AP-401 Linked',
                '',
            ]));
            file_put_contents($dir.'/AP-402-too-long.md', implode("\n", [
                '---',
                'title: AP-402 Too Long',
                'line_limit: 5',
                '---',
                '# AP-402 Too Long',
                'one',
                'two',
                'three',
                'four',
                'five',
                'six',
                '',
            ]));
            file_put_contents($dir.'/AP-403-frontmatter.md', implode("\n", [
                '---',
                'title:',
                'line_limit: nope',
                '---',
                '# AP-403 Frontmatter',
                '',
            ]));

            $payload = app(AtlasApDocumentationGovernanceRegistry::class)->summary($dir);

            $this->assertSame('attention', $payload['status']);
            $this->assertSame('repair_ap_documentation_governance_blockers_before_new_ap', $payload['next_action']);
            $this->assertSame(5, $payload['blocker_count']);
            $this->assertContains('duplicate_ap_numbers', array_column($payload['blockers'], 'reason'));
            $this->assertContains('malformed_ap_filenames', array_column($payload['blockers'], 'reason'));
            $this->assertContains('missing_ap_related_paths', array_column($payload['blockers'], 'reason'));
            $this->assertContains('ap_line_limit_overflow', array_column($payload['blockers'], 'reason'));
            $this->assertContains('ap_frontmatter_shape_violation', array_column($payload['blockers'], 'reason'));
            $this->assertSame('attention', data_get($payload, 'audits.number_registry.status'));
            $this->assertSame('attention', data_get($payload, 'audits.implementation_links.status'));
            $this->assertSame('attention', data_get($payload, 'audits.line_limits.status'));
            $this->assertSame('attention', data_get($payload, 'audits.frontmatter.status'));
            $this->assertSame('ok', data_get($payload, 'audits.status_taxonomy.status'));
            $this->assertSame(1, data_get($payload, 'audits.implementation_links.missing_path_count'));
            $this->assertSame(1, data_get($payload, 'audits.line_limits.oversized_count'));
            $this->assertSame(5, data_get($payload, 'audits.frontmatter.violation_count'));
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function test_registry_surfaces_status_taxonomy_blocker_from_temp_ap_docs(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-governance-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-410-status.md', implode("\n", [
                '---',
                'title: AP-410 Status',
                'status: almost done maybe',
                '---',
                '# AP-410 Status',
                '',
            ]));

            $payload = app(AtlasApDocumentationGovernanceRegistry::class)->summary($dir);

            $this->assertSame('attention', $payload['status']);
            $this->assertContains('ap_status_taxonomy_violation', array_column($payload['blockers'], 'reason'));
            $this->assertSame('attention', data_get($payload, 'audits.status_taxonomy.status'));
            $this->assertSame(1, data_get($payload, 'audits.status_taxonomy.invalid_status_count'));
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
