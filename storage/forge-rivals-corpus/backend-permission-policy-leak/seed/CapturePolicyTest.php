<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Policies\CapturePolicy;
use PHPUnit\Framework\TestCase;

/**
 * Testes do deny-first em CapturePolicy::view().
 */
final class CapturePolicyTest extends TestCase
{
    public function test_allows_same_tenant(): void
    {
        $user = ['tenant_id' => 'tenant-alpha'];
        $capture = ['tenant_id' => 'tenant-alpha'];
        $this->assertTrue((new CapturePolicy)->view($user, $capture));
    }

    public function test_denies_cross_tenant(): void
    {
        $user = ['tenant_id' => 'tenant-alpha'];
        $capture = ['tenant_id' => 'tenant-beta'];
        $this->assertFalse((new CapturePolicy)->view($user, $capture), 'tenants diferentes devem ser bloqueados');
    }

    public function test_denies_guest(): void
    {
        $capture = ['tenant_id' => 'tenant-alpha'];
        $this->assertFalse((new CapturePolicy)->view(null, $capture));
    }

    public function test_denies_when_capture_has_no_tenant(): void
    {
        $user = ['tenant_id' => 'tenant-alpha'];
        $capture = ['tenant_id' => null];
        $this->assertFalse((new CapturePolicy)->view($user, $capture), 'capture sem tenant nunca deve ser visível por default');
    }
}
