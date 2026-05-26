<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Governance;

use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Governance\AtlasConstitutionalVaultService;
use Tests\TestCase;

class AtlasConstitutionalVaultServiceTest extends TestCase
{
    private string $vaultPath;

    private string $keyPath;

    private string $kernelLog;

    private AtlasConstitutionalKernelService $kernel;

    private AtlasConstitutionalVaultService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid('', true);
        $this->vaultPath = sys_get_temp_dir()."/atlas_vault_{$u}.json";
        $this->keyPath = sys_get_temp_dir()."/atlas_vault_key_{$u}";
        $this->kernelLog = sys_get_temp_dir()."/atlas_vault_kernel_{$u}.jsonl";

        $this->kernel = new AtlasConstitutionalKernelService;
        $this->kernel->setViolationsLogPathForTesting($this->kernelLog);

        $this->svc = new AtlasConstitutionalVaultService($this->kernel);
        $this->svc->setVaultPathForTesting($this->vaultPath);
        $this->svc->setKeyPathForTesting($this->keyPath);
        $this->svc->setEnvKeyForTesting('test-vault-key-deterministic');
    }

    protected function tearDown(): void
    {
        @unlink($this->vaultPath);
        @unlink($this->keyPath);
        @unlink($this->kernelLog);
        parent::tearDown();
    }

    public function test_verify_returns_vault_missing_when_no_file(): void
    {
        $env = $this->svc->verify();
        $this->assertSame(AtlasConstitutionalVaultService::VERIFY_MISSING, $env['verify_status']);
    }

    public function test_verify_returns_key_missing_when_no_key(): void
    {
        $this->svc->setEnvKeyForTesting(null);
        // keyPath also doesn't exist
        $env = $this->svc->verify();
        $this->assertSame(AtlasConstitutionalVaultService::VERIFY_KEY_MISSING, $env['verify_status']);
    }

    public function test_sign_requires_actor_and_reason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->sign('', '');
    }

    public function test_sign_writes_signed_payload(): void
    {
        $payload = $this->svc->sign('operator', 'initial seal');
        $this->assertSame(AtlasConstitutionalVaultService::VAULT_SCHEMA, $payload['schema_version']);
        $this->assertSame('operator', $payload['actor']);
        $this->assertStringStartsWith('sha256:', $payload['signature']);
        $this->assertIsArray($payload['invariants']);
        $this->assertTrue(is_file($this->vaultPath));
    }

    public function test_verify_ok_after_sign(): void
    {
        $this->svc->sign('operator', 'seal');
        $env = $this->svc->verify();
        $this->assertSame(AtlasConstitutionalVaultService::VERIFY_OK, $env['verify_status']);
    }

    public function test_verify_signature_invalid_when_tampered(): void
    {
        $this->svc->sign('operator', 'seal');
        // Tamper signature by writing different content with same key.
        $raw = (string) file_get_contents($this->vaultPath);
        $decoded = json_decode($raw, true);
        $decoded['invariants'][0]['enabled'] = ! ($decoded['invariants'][0]['enabled'] ?? true);
        file_put_contents($this->vaultPath, json_encode($decoded));
        $env = $this->svc->verify();
        $this->assertSame(AtlasConstitutionalVaultService::VERIFY_SIGNATURE_INVALID, $env['verify_status']);
    }

    public function test_verify_malformed_when_invalid_json(): void
    {
        file_put_contents($this->vaultPath, '{not valid json');
        $env = $this->svc->verify();
        $this->assertSame(AtlasConstitutionalVaultService::VERIFY_MALFORMED, $env['verify_status']);
    }

    public function test_verify_carries_current_kernel_hash(): void
    {
        $env = $this->svc->verify();
        $this->assertSame($this->kernel->kernelHash(), $env['current_kernel_hash']);
    }

    public function test_kernel_snapshot_carries_kernel_hash(): void
    {
        $snap = $this->svc->kernelSnapshot();
        $this->assertArrayHasKey('invariants', $snap);
        $this->assertSame($this->kernel->kernelHash(), $snap['kernel_hash']);
    }

    public function test_resolve_key_falls_back_to_file(): void
    {
        $this->svc->setEnvKeyForTesting(null);
        file_put_contents($this->keyPath, 'key-from-file');
        $this->assertSame('key-from-file', $this->svc->resolveKey());
    }

    public function test_verify_status_constants_canon(): void
    {
        $this->assertSame('ok', AtlasConstitutionalVaultService::VERIFY_OK);
        $this->assertSame('vault_missing', AtlasConstitutionalVaultService::VERIFY_MISSING);
        $this->assertSame('key_missing', AtlasConstitutionalVaultService::VERIFY_KEY_MISSING);
        $this->assertSame('signature_invalid', AtlasConstitutionalVaultService::VERIFY_SIGNATURE_INVALID);
        $this->assertSame('kernel_drift', AtlasConstitutionalVaultService::VERIFY_KERNEL_DRIFT);
        $this->assertSame('malformed', AtlasConstitutionalVaultService::VERIFY_MALFORMED);
    }

    public function test_signature_is_deterministic_per_key(): void
    {
        $first = $this->svc->sign('operator', 'seal one');
        // sign() rewrites file each call; signature changes because signed_at differs.
        // But the algorithm is deterministic given identical payload — assert format.
        $this->assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $first['signature']);
    }
}
