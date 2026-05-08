<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApStatusTaxonomyAudit;
use Tests\TestCase;

final class AtlasApStatusTaxonomyAuditTest extends TestCase
{
    public function test_current_declared_ap_statuses_are_in_taxonomy(): void
    {
        $payload = app(AtlasApStatusTaxonomyAudit::class)->audit();

        $this->assertSame('atlas.ap_status_taxonomy_audit.v1', $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('read_only_audit', $payload['mode']);
        $this->assertSame('ap_status_taxonomy_only_no_doc_writes', $payload['authority']);
        $this->assertGreaterThanOrEqual(1, $payload['declared_status_count']);
        $this->assertSame(0, $payload['invalid_status_count']);
        $this->assertContains('foundation-contract-implemented', $payload['allowed_statuses']);
        $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
        $this->assertFalse(data_get($payload, 'guardrails.normalizes_statuses'));
        $this->assertTrue(data_get($payload, 'guardrails.blocks_on_unknown_declared_status'));
    }

    public function test_audit_detects_status_outside_taxonomy(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-status-taxonomy-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-610-bad-status.md', implode("\n", [
                '---',
                'title: AP-610 Bad Status',
                'status: building wildly',
                '---',
                '# AP-610 Bad Status',
                '',
            ]));

            $payload = app(AtlasApStatusTaxonomyAudit::class)->audit($dir);

            $this->assertSame('attention', $payload['status']);
            $this->assertSame(1, $payload['declared_status_count']);
            $this->assertSame(1, $payload['invalid_status_count']);
            $this->assertSame('building wildly', data_get($payload, 'violations.0.status'));
            $this->assertSame('status_not_in_ap_taxonomy', data_get($payload, 'violations.0.violation'));
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
