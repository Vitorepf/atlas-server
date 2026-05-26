<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Attachments\Governance;

use App\Services\Ai\Attachments\Governance\AttachmentContextPolicyGate;
use Tests\TestCase;

final class AttachmentContextPolicyGateTest extends TestCase
{
    private AttachmentContextPolicyGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new AttachmentContextPolicyGate;
    }

    private function compliant(): array
    {
        return [
            'attachment_id' => 'att-001',
            'kind' => 'image',
            'mime_type' => 'image/png',
            'size_bytes' => 500 * 1024,
            'privacy_class' => 'personal',
            'source_authority' => 'operator_upload',
            'content_hash' => str_repeat('a', 64),
        ];
    }

    public function test_admits_compliant_image(): void
    {
        $r = $this->gate->evaluate($this->compliant());
        $this->assertSame('admitted_to_context', $r['gate_decision']);
        $this->assertSame([], $r['failed_checks']);
    }

    public function test_blocks_invalid_kind(): void
    {
        $a = $this->compliant();
        $a['kind'] = 'meme';
        $r = $this->gate->evaluate($a);
        $this->assertArrayHasKey('kind', $r['failed_checks']);
    }

    public function test_blocks_wildcard_mime(): void
    {
        $a = $this->compliant();
        $a['mime_type'] = 'image/*';
        $r = $this->gate->evaluate($a);
        $this->assertArrayHasKey('mime_type', $r['failed_checks']);
    }

    public function test_blocks_oversize_image(): void
    {
        $a = $this->compliant();
        $a['size_bytes'] = 100 * 1024 * 1024; // 100MB > 25MB image cap
        $r = $this->gate->evaluate($a);
        $this->assertArrayHasKey('size_bytes', $r['failed_checks']);
    }

    public function test_blocks_invalid_privacy_class(): void
    {
        $a = $this->compliant();
        $a['privacy_class'] = 'wibble';
        $r = $this->gate->evaluate($a);
        $this->assertArrayHasKey('privacy_class', $r['failed_checks']);
    }

    public function test_blocks_invalid_source_authority(): void
    {
        $a = $this->compliant();
        $a['source_authority'] = 'rogue_actor';
        $r = $this->gate->evaluate($a);
        $this->assertArrayHasKey('source_authority', $r['failed_checks']);
    }

    public function test_blocks_malformed_content_hash(): void
    {
        $a = $this->compliant();
        $a['content_hash'] = 'not-sha256';
        $r = $this->gate->evaluate($a);
        $this->assertArrayHasKey('content_hash', $r['failed_checks']);
    }

    public function test_external_url_requires_anti_ssrf_ack(): void
    {
        $a = $this->compliant();
        $a['source_authority'] = 'external_url';
        $a['kind'] = 'url';
        $a['mime_type'] = 'text/uri-list';
        $a['size_bytes'] = 256;
        $r = $this->gate->evaluate($a);
        $this->assertArrayHasKey('anti_ssrf_acknowledged', $r['failed_checks']);
    }

    public function test_external_url_passes_with_ack(): void
    {
        $a = $this->compliant();
        $a['source_authority'] = 'external_url';
        $a['kind'] = 'url';
        $a['mime_type'] = 'text/uri-list';
        $a['size_bytes'] = 256;
        $a['anti_ssrf_acknowledged'] = true;
        $r = $this->gate->evaluate($a);
        $this->assertSame('admitted_to_context', $r['gate_decision']);
    }

    public function test_envelope_shape_is_stable(): void
    {
        $r = $this->gate->evaluate($this->compliant());
        $this->assertSame([
            'schema_version', 'attachment_id', 'gate_decision', 'passed_checks',
            'failed_checks', 'detail', 'evaluated_at',
        ], array_keys($r));
    }
}
