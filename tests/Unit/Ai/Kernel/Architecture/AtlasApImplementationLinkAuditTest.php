<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApImplementationLinkAudit;
use Tests\TestCase;

final class AtlasApImplementationLinkAuditTest extends TestCase
{
    public function test_current_related_paths_are_not_broken(): void
    {
        $payload = app(AtlasApImplementationLinkAudit::class)->audit();

        $this->assertSame('atlas.ap_implementation_link_audit.v1', $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('read_only_audit', $payload['mode']);
        $this->assertSame('ap_related_paths_only_no_file_writes', $payload['authority']);
        $this->assertSame(0, $payload['missing_path_count']);
        $this->assertGreaterThanOrEqual(1, $payload['ap_with_related_paths_count']);
        $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
        $this->assertFalse(data_get($payload, 'guardrails.creates_missing_files'));
        $this->assertTrue(data_get($payload, 'guardrails.blocks_on_missing_related_paths'));
    }

    public function test_audit_detects_missing_related_paths_in_temp_ap_docs(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-link-audit-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-300-link-test.md', implode("\n", [
                '---',
                'title: AP-300 Link Test',
                'related_paths:',
                '  - app/Services/Ai/Kernel/Architecture/AtlasApImplementationLinkAudit.php',
                '  - app/Services/Ai/Kernel/Architecture/MissingContractForTest.php',
                '---',
                '# AP-300 Link Test',
                '',
            ]));

            $payload = app(AtlasApImplementationLinkAudit::class)->audit($dir);

            $this->assertSame('attention', $payload['status']);
            $this->assertSame(1, $payload['ap_with_related_paths_count']);
            $this->assertSame(1, $payload['missing_path_count']);
            $this->assertSame('app/Services/Ai/Kernel/Architecture/MissingContractForTest.php', data_get($payload, 'missing_paths.0.missing_path'));
            $this->assertSame(2, data_get($payload, 'entries.0.related_path_count'));
            $this->assertSame(1, data_get($payload, 'entries.0.missing_path_count'));
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
