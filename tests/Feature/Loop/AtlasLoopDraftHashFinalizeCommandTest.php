<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves the runtime-promotion draft hash finalizer is live at the operator surface: over a faked disk holding
 * an operator-ready draft receipt, the command computes the receipt hash and (with the write flag) writes the
 * finalized hash into the draft artifact; without the flag it is ready-to-write but writes nothing.
 */
final class AtlasLoopDraftHashFinalizeCommandTest extends TestCase
{
    private const DRAFT_PATH = 'operator-drafts/x/runtime-promotion.json';

    private function seedDraft(): void
    {
        Storage::fake('local');
        // Operator-ready receipt: real signer + a >=32-char real reason + no forbidden runtime flags.
        Storage::disk('local')->put(self::DRAFT_PATH, (string) json_encode([
            'signed_by' => 'operator@atlas.dev',
            'reason' => 'Promote the marketing runtime after a green soak and operator sign-off review window.',
        ]));
    }

    private function finalize(bool $write): array
    {
        $exit = Artisan::call('atlas:loop:draft-hash-finalize', [
            '--options' => (string) json_encode([
                'operator_draft_workspace_path' => self::DRAFT_PATH,
                'write_computed_runtime_promotion_receipt_hash' => $write,
            ]),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_writes_finalized_hash_into_draft(): void
    {
        $this->seedDraft();

        ['exit' => $exit, 'd' => $d] = $this->finalize(true);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService::SCHEMA_VERSION, $d['schema_version']);
        $this->assertSame('draft_hash_written', $d['status'], (string) json_encode($d));
        $this->assertTrue($d['written']);
        $this->assertNotSame('', $d['computed_receipt_hash']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $d['computed_receipt_hash']);

        // the draft artifact now carries the computed hash
        $written = json_decode((string) Storage::disk('local')->get(self::DRAFT_PATH), true);
        $this->assertSame($d['computed_receipt_hash'], $written['receipt_hash']);
    }

    public function test_without_write_flag_is_ready_to_write_no_write(): void
    {
        $this->seedDraft();

        ['exit' => $exit, 'd' => $d] = $this->finalize(false);

        $this->assertSame(0, $exit);
        $this->assertSame('ready_to_write_computed_hash', $d['status'], (string) json_encode($d));
        $this->assertFalse($d['written']);
        $this->assertNotSame('', $d['computed_receipt_hash']);
        // draft unchanged — no receipt_hash written
        $written = json_decode((string) Storage::disk('local')->get(self::DRAFT_PATH), true);
        $this->assertArrayNotHasKey('receipt_hash', $written);
    }

    public function test_invalid_options_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:draft-hash-finalize', ['--options' => 'not json', '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
