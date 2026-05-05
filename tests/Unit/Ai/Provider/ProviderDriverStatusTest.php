<?php

namespace Tests\Unit\Ai\Provider;

use App\Services\Ai\Kernel\Provider\ProviderDriverExecutionStatus;
use App\Services\Ai\Kernel\Provider\ProviderPreparedRequestStatus;
use Tests\TestCase;

class ProviderDriverStatusTest extends TestCase
{
    public function test_execution_status_values_are_stable(): void
    {
        $this->assertSame('delegates_to_legacy_provider', ProviderDriverExecutionStatus::DelegatesToLegacyProvider->value);
        $this->assertSame('dry_run', ProviderDriverExecutionStatus::DryRun->value);
        $this->assertSame('not_executed', ProviderDriverExecutionStatus::NotExecuted->value);
    }

    public function test_prepared_request_status_value_is_stable(): void
    {
        $this->assertSame('prepared', ProviderPreparedRequestStatus::Prepared->value);
    }
}
