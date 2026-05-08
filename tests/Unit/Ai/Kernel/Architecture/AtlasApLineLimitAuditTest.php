<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApLineLimitAudit;
use Tests\TestCase;

final class AtlasApLineLimitAuditTest extends TestCase
{
    public function test_current_declared_ap_line_limits_are_respected(): void
    {
        $payload = app(AtlasApLineLimitAudit::class)->audit();

        $this->assertSame('atlas.ap_line_limit_audit.v1', $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('read_only_audit', $payload['mode']);
        $this->assertSame('ap_line_limit_only_no_file_writes', $payload['authority']);
        $this->assertSame(0, $payload['oversized_count']);
        $this->assertGreaterThanOrEqual(1, $payload['ap_with_line_limit_count']);
        $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
        $this->assertFalse(data_get($payload, 'guardrails.truncates_docs'));
        $this->assertTrue(data_get($payload, 'guardrails.blocks_on_declared_line_limit_overflow'));
    }

    public function test_audit_detects_ap_docs_that_exceed_declared_line_limit(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-line-limit-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-500-too-long.md', implode("\n", [
                '---',
                'title: AP-500 Too Long',
                'line_limit: 5',
                '---',
                '# AP-500 Too Long',
                'one',
                'two',
                'three',
                'four',
                'five',
                'six',
                '',
            ]));

            $payload = app(AtlasApLineLimitAudit::class)->audit($dir);

            $this->assertSame('attention', $payload['status']);
            $this->assertSame(1, $payload['ap_with_line_limit_count']);
            $this->assertSame(1, $payload['oversized_count']);
            $this->assertSame('AP-500-too-long.md', basename(data_get($payload, 'oversized_docs.0.ap_doc')));
            $this->assertSame(5, data_get($payload, 'oversized_docs.0.line_limit'));
            $this->assertGreaterThan(0, data_get($payload, 'oversized_docs.0.excess_lines'));
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
