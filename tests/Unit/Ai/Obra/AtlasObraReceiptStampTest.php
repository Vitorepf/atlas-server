<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Obra;

use App\Services\Ai\Obra\AtlasObraReceiptStamp;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * L4-10 — the EXECUTOR-SELF-STAMPED RECEIPT cryptographic keystone.
 *
 * Frozen contract: a stamp signs the load-bearing facts with an HMAC and a verifier
 * recomputes that HMAC from the receipt's OWN fields. A clean stamp verifies; a
 * hand-edit of ANY sealed fact (certified / provider / model / node_count / a step
 * commit / main_untouched / branch / receipt_hash) breaks the signature; a missing or
 * forged provenance block is rejected; presentation-only fields outside the signed body
 * never break the seal. This is what makes a hand-assembled receipt impossible to pass
 * off as a real executor run.
 */
final class AtlasObraReceiptStampTest extends TestCase
{
    private function facts(array $overrides = []): array
    {
        return array_merge([
            'obra_id' => 'obra-l4-10-real-20260613',
            'branch' => 'atlas/obra/obra-l4-10-real-20260613',
            'base_head' => 'a1b2c3d4e5f6',
            'status' => 'done',
            'certified' => true,
            'node_count' => 6,
            'delivered_nodes' => 6,
            'provider' => 'hermes_cli',
            'model' => 'gpt-5.5',
            'resumed' => true,
            'resume_count' => 1,
            'main_untouched' => true,
            'never_merged' => true,
            'receipt_hash' => 'deadbeefcafe',
            'delivered_item_id' => 'L4-6',
            'delivered_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'steps' => [
                ['id' => 'n0', 'status' => 'done', 'commit' => 'c0'],
                ['id' => 'n1', 'status' => 'done', 'commit' => 'c1'],
            ],
            'integrated' => ['supplied' => true, 'ran' => true, 'passed' => true, 'exit_code' => 0, 'cmd' => 'php artisan test'],
        ], $overrides);
    }

    public function test_a_clean_stamp_verifies(): void
    {
        $stamp = new AtlasObraReceiptStamp;
        $receipt = $stamp->stamp($this->facts());

        $this->assertTrue((bool) data_get($receipt, 'provenance.executor_stamped'));
        $this->assertSame('sha256', data_get($receipt, 'provenance.hmac_algo'));
        $this->assertIsString(data_get($receipt, 'provenance.signature'));

        $verdict = $stamp->verify($receipt);
        $this->assertTrue($verdict['verified']);
        $this->assertNull($verdict['reason']);
    }

    public function test_stamping_is_deterministic_for_the_same_facts(): void
    {
        $stamp = new AtlasObraReceiptStamp;
        $a = $stamp->stamp($this->facts());
        $b = $stamp->stamp($this->facts());

        $this->assertSame(
            data_get($a, 'provenance.signature'),
            data_get($b, 'provenance.signature'),
            'the same observed run state yields the same signature',
        );
    }

    #[DataProvider('sealedFieldTamperProvider')]
    public function test_a_hand_edit_of_any_sealed_field_breaks_the_signature(string $field, mixed $tamperedValue): void
    {
        $stamp = new AtlasObraReceiptStamp;
        $receipt = $stamp->stamp($this->facts());

        $receipt[$field] = $tamperedValue;

        $verdict = $stamp->verify($receipt);
        $this->assertFalse($verdict['verified'], "editing sealed field '$field' must break the signature");
        $this->assertSame('signature_mismatch', $verdict['reason']);
    }

    /**
     * @return iterable<string,array{0:string,1:mixed}>
     */
    public static function sealedFieldTamperProvider(): iterable
    {
        yield 'certified flipped' => ['certified', false];
        yield 'provider swapped' => ['provider', 'codex_cli'];
        yield 'model swapped' => ['model', 'gpt-4'];
        yield 'node_count inflated' => ['node_count', 99];
        yield 'status changed' => ['status', 'needs_review'];
        yield 'branch changed' => ['branch', 'atlas/obra/other'];
        yield 'base_head changed' => ['base_head', 'ffffffffffff'];
        yield 'main_untouched flipped' => ['main_untouched', false];
        yield 'receipt_hash changed' => ['receipt_hash', 'tampered'];
        yield 'resume_count changed' => ['resume_count', 7];
        yield 'delivered_item_id changed' => ['delivered_item_id', 'L9-9'];
    }

    public function test_tampering_a_step_commit_breaks_the_signature(): void
    {
        $stamp = new AtlasObraReceiptStamp;
        $receipt = $stamp->stamp($this->facts());

        $receipt['steps'][1]['commit'] = 'forged-commit';

        $verdict = $stamp->verify($receipt);
        $this->assertFalse($verdict['verified']);
        $this->assertSame('signature_mismatch', $verdict['reason']);
    }

    public function test_a_missing_provenance_block_is_rejected(): void
    {
        $stamp = new AtlasObraReceiptStamp;
        $receipt = $stamp->stamp($this->facts());
        unset($receipt['provenance']);

        $verdict = $stamp->verify($receipt);
        $this->assertFalse($verdict['verified']);
        $this->assertSame('provenance_block_missing', $verdict['reason']);
    }

    public function test_a_forged_signature_is_rejected(): void
    {
        $stamp = new AtlasObraReceiptStamp;
        $receipt = $stamp->stamp($this->facts());
        $receipt['provenance']['signature'] = str_repeat('0', 64);

        $verdict = $stamp->verify($receipt);
        $this->assertFalse($verdict['verified']);
        $this->assertSame('signature_mismatch', $verdict['reason']);
    }

    public function test_a_receipt_not_marked_executor_stamped_is_rejected(): void
    {
        $stamp = new AtlasObraReceiptStamp;
        $receipt = $stamp->stamp($this->facts());
        $receipt['provenance']['executor_stamped'] = false;

        $verdict = $stamp->verify($receipt);
        $this->assertFalse($verdict['verified']);
        $this->assertSame('not_marked_executor_stamped', $verdict['reason']);
    }

    public function test_adding_presentation_only_fields_does_not_break_the_seal(): void
    {
        $stamp = new AtlasObraReceiptStamp;
        $receipt = $stamp->stamp($this->facts());

        // Fields OUTSIDE the signed canonical body (e.g. live run-evidence the proof
        // wraps around the core) must not affect the signature.
        $receipt['kill_resume'] = ['kill_exercised' => true, 'resume_exercised' => true];
        $receipt['command_results'] = [['command' => 'php artisan x', 'exit_code' => 0]];
        $receipt['generated_at'] = '2026-06-13T00:00:00Z';

        $verdict = $stamp->verify($receipt);
        $this->assertTrue($verdict['verified'], 'unsigned presentation fields never break the seal');
    }

    public function test_a_different_secret_cannot_verify_the_stamp(): void
    {
        // The executor that runs and the proof that certifies share the SAME secret. An
        // out-of-band forger with a DIFFERENT secret cannot reproduce the digest.
        config()->set('atlas.obra.receipt_secret', 'secret-A');
        $signer = new AtlasObraReceiptStamp;
        $receipt = $signer->stamp($this->facts());

        config()->set('atlas.obra.receipt_secret', 'secret-B');
        $verifier = new AtlasObraReceiptStamp;
        $verdict = $verifier->verify($receipt);

        $this->assertFalse($verdict['verified'], 'a different secret must not verify');
        $this->assertSame('signature_mismatch', $verdict['reason']);

        // Same secret on both sides verifies (the in-deployment contract).
        config()->set('atlas.obra.receipt_secret', 'secret-A');
        $this->assertTrue((new AtlasObraReceiptStamp)->verify($receipt)['verified']);
    }

    public function test_the_signed_body_carries_only_provider_safe_facts(): void
    {
        // No source, no diffs, no prompts — labels / ids / commits / booleans only.
        $stamp = new AtlasObraReceiptStamp;
        $receipt = $stamp->stamp($this->facts());
        $signedFields = (array) data_get($receipt, 'provenance.signed_fields');

        $this->assertContains('certified', $signedFields);
        $this->assertContains('provider', $signedFields);
        $this->assertContains('node_count', $signedFields);
        $this->assertNotContains('files', $signedFields);
        $this->assertNotContains('content', $signedFields);
        $this->assertNotContains('diff', $signedFields);
    }
}
