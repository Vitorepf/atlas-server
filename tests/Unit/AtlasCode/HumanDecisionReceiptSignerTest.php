<?php

declare(strict_types=1);

namespace Tests\Unit\AtlasCode;

use App\Services\AtlasCode\HumanDecisionReceiptSigner;
use Tests\TestCase;

class HumanDecisionReceiptSignerTest extends TestCase
{
    public function test_signing_unavailable_when_no_keypair_configured(): void
    {
        config()->set('atlas_code_signing.keypair_base64', null);
        // Audit log to ephemeral tmp so we don't pollute repo.
        config()->set('atlas_code_signing.audit_log_path', sys_get_temp_dir().'/atlas-test-audit-'.bin2hex(random_bytes(4)).'.jsonl');
        $signer = new HumanDecisionReceiptSigner();
        $r = $signer->signDecision(['session_id' => 'os_abc', 'action' => 'accept']);
        $this->assertSame('unavailable', $r['signing_status']);
        $this->assertNull($r['signature']);
        $this->assertNull($r['public_key']);
        $this->assertSame(64, strlen($r['canonical_payload_hash'])); // sha256 hex
    }

    public function test_signing_produces_verifiable_signature(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        config()->set('atlas_code_signing.keypair_base64', base64_encode($keypair));
        config()->set('atlas_code_signing.audit_log_path', sys_get_temp_dir().'/atlas-test-audit-'.bin2hex(random_bytes(4)).'.jsonl');

        $signer = new HumanDecisionReceiptSigner();
        $payload = [
            'session_id' => 'os_abc',
            'obra_id' => 'obra-1',
            'action' => 'accept',
            'reason' => 'gates ok',
            'decided_at' => '2026-05-16T12:00:00Z',
        ];
        $r = $signer->signDecision($payload);

        $this->assertSame('signed', $r['signing_status']);
        $this->assertNotNull($r['signature']);
        $this->assertNotNull($r['public_key']);

        // Re-canonicalize the same payload and verify.
        $canonical = $signer->canonicalJson($payload);
        $this->assertTrue($signer->verify($canonical, $r['signature'], $r['public_key']));

        // Mutating the canonical payload must invalidate the signature.
        $mutated = $canonical.' ';
        $this->assertFalse($signer->verify($mutated, $r['signature'], $r['public_key']));
    }

    public function test_canonical_json_sorts_keys_recursively(): void
    {
        $signer = new HumanDecisionReceiptSigner();
        $a = $signer->canonicalJson(['z' => 1, 'a' => ['y' => 2, 'x' => 3]]);
        $b = $signer->canonicalJson(['a' => ['x' => 3, 'y' => 2], 'z' => 1]);
        $this->assertSame($a, $b);
    }
}
