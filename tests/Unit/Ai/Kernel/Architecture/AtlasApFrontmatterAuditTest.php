<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApFrontmatterAudit;
use Tests\TestCase;

final class AtlasApFrontmatterAuditTest extends TestCase
{
    public function test_current_declared_ap_frontmatter_is_well_formed(): void
    {
        $payload = app(AtlasApFrontmatterAudit::class)->audit();

        $this->assertSame('atlas.ap_frontmatter_audit.v1', $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('read_only_audit', $payload['mode']);
        $this->assertSame('ap_frontmatter_shape_only_no_file_writes', $payload['authority']);
        $this->assertSame(0, $payload['violation_count']);
        $this->assertGreaterThanOrEqual(1, $payload['ap_with_frontmatter_count']);
        $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
        $this->assertFalse(data_get($payload, 'guardrails.requires_frontmatter_for_all_aps'));
        $this->assertTrue(data_get($payload, 'guardrails.blocks_on_malformed_declared_frontmatter'));
    }

    public function test_audit_detects_missing_required_declared_frontmatter_fields(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-frontmatter-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-600-frontmatter.md', implode("\n", [
                '---',
                'title:',
                'line_limit: nope',
                '---',
                '# AP-600 Frontmatter',
                '',
            ]));

            $payload = app(AtlasApFrontmatterAudit::class)->audit($dir);

            $this->assertSame('attention', $payload['status']);
            $this->assertSame(1, $payload['ap_with_frontmatter_count']);
            $this->assertSame(3, $payload['violation_count']);
            $this->assertContains('missing_title', data_get($payload, 'entries.0.violations'));
            $this->assertContains('missing_status', data_get($payload, 'entries.0.violations'));
            $this->assertContains('line_limit_must_be_positive_integer', data_get($payload, 'entries.0.violations'));
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function test_audit_rejects_zero_line_limit_as_non_positive(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-frontmatter-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-601-frontmatter.md', implode("\n", [
                '---',
                'title: AP-601 Frontmatter',
                'status: foundation-audit-implemented',
                'line_limit: 0',
                '---',
                '# AP-601 Frontmatter',
                '',
            ]));

            $payload = app(AtlasApFrontmatterAudit::class)->audit($dir);

            $this->assertSame('attention', $payload['status']);
            $this->assertSame(1, $payload['violation_count']);
            $this->assertSame(['line_limit_must_be_positive_integer'], data_get($payload, 'entries.0.violations'));
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
