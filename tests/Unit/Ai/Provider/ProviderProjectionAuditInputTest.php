<?php

namespace Tests\Unit\Ai\Provider;

use App\Services\Ai\Provider\ProviderProjectionAuditInput;
use Tests\TestCase;

class ProviderProjectionAuditInputTest extends TestCase
{
    public function test_normalizes_provider_projection_audit_windows_with_canonical_caps(): void
    {
        $input = new ProviderProjectionAuditInput;

        $this->assertSame(ProviderProjectionAuditInput::DEFAULT_AUDIT_LIMIT, $input->auditLimit(null));
        $this->assertSame(ProviderProjectionAuditInput::DEFAULT_SUMMARY_DAYS, $input->summaryDays('bad'));
        $this->assertSame(ProviderProjectionAuditInput::DEFAULT_PURGE_OLDER_THAN_DAYS, $input->purgeOlderThanDays(null));
        $this->assertSame(1, $input->auditLimit(-10));
        $this->assertSame(ProviderProjectionAuditInput::MAX_AUDIT_LIMIT, $input->auditLimit(9999));
        $this->assertSame(ProviderProjectionAuditInput::MAX_SUMMARY_DAYS, $input->summaryDays(9999));
        $this->assertSame(ProviderProjectionAuditInput::MAX_PURGE_OLDER_THAN_DAYS, $input->purgeOlderThanDays(99999));
    }
}
