<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Receipts\AtlasLoopCycleReceiptSigner;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class AtlasLoopCycleReceiptSignerTest extends TestCase
{
    private function signer(): AtlasLoopCycleReceiptSigner
    {
        $s = new AtlasLoopCycleReceiptSigner;
        $s->setClock(fn () => CarbonImmutable::parse('2026-06-24T12:00:00+00:00'));

        return $s;
    }

    private function body(): array
    {
        return [
            'schema_version' => AtlasLoopCycleReceiptSigner::BODY_SCHEMA,
            'cycle_id' => 'cycle-001',
            'facts' => [
                'impact' => [
                    ['target' => 'app/Foo.php', 'impact_score' => 7.5],
                    ['target' => 'app/Bar.php', 'impact_score' => 3.0],
                ],
            ],
        ];
    }

    public function test_sign_returns_the_signed_envelope_shape(): void
    {
        $signed = $this->signer()->sign($this->body());

        $this->assertSame('atlas.loop.cycle_receipt.signed.v1', $signed['schema_version']);
        $this->assertSame($this->body(), $signed['body']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signed['body_canonical_sha256']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signed['signature']);
        $this->assertSame('2026-06-24T12:00:00+00:00', $signed['signed_at_iso']);
    }

    public function test_signature_is_deterministic_for_same_body_key_and_frozen_time(): void
    {
        $a = $this->signer()->sign($this->body());
        $b = $this->signer()->sign($this->body());

        $this->assertSame($a['signature'], $b['signature']);
        $this->assertSame($a['body_canonical_sha256'], $b['body_canonical_sha256']);
        $this->assertSame($a['signed_at_iso'], $b['signed_at_iso']);
    }

    public function test_verify_true_on_untampered_receipt(): void
    {
        $signed = $this->signer()->sign($this->body());
        $this->assertTrue($this->signer()->verify($signed));
    }

    public function test_verify_false_when_cycle_id_is_tampered(): void
    {
        $signed = $this->signer()->sign($this->body());
        $signed['body']['cycle_id'] = 'cycle-002';
        $this->assertFalse($this->signer()->verify($signed));
    }

    public function test_verify_false_when_a_nested_impact_score_is_tampered(): void
    {
        $signed = $this->signer()->sign($this->body());
        $signed['body']['facts']['impact'][0]['impact_score'] = 999.0;
        $this->assertFalse($this->signer()->verify($signed));
    }

    public function test_verify_false_when_canonical_sha_is_tampered(): void
    {
        $signed = $this->signer()->sign($this->body());
        $signed['body_canonical_sha256'] = str_repeat('0', 64);
        $this->assertFalse($this->signer()->verify($signed));
    }
}
