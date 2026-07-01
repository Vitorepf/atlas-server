<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\ControlPlane\TerminalLoopHealthDigestPayloadNormalizer;
use Tests\TestCase;

final class TerminalLoopHealthDigestPayloadNormalizerTest extends TestCase
{
    private function normalizer(): TerminalLoopHealthDigestPayloadNormalizer
    {
        return new TerminalLoopHealthDigestPayloadNormalizer;
    }

    // ── stringOption fallback behavior ──────────────────────────────────────────

    public function test_string_option_falls_back_to_default_when_key_absent(): void
    {
        $this->assertSame('fallback', $this->normalizer()->stringOption([], 'missing_key', 'fallback'));
    }

    public function test_string_option_falls_back_to_default_when_value_blank_after_trim(): void
    {
        $this->assertSame('fallback', $this->normalizer()->stringOption(['k' => '   '], 'k', 'fallback'));
    }

    public function test_string_option_prefers_supplied_trimmed_value_over_default(): void
    {
        $this->assertSame('present', $this->normalizer()->stringOption(['k' => '  present  '], 'k', 'fallback'));
    }

    // ── stringList trimming / filtering ─────────────────────────────────────────

    public function test_string_list_trims_and_drops_blank_entries(): void
    {
        $result = $this->normalizer()->stringList(['  keep  ', '', '   ', 'also-keep']);

        $this->assertSame(['keep', 'also-keep'], $result);
    }

    public function test_string_list_coerces_non_string_scalars(): void
    {
        $result = $this->normalizer()->stringList([1, 2.5, true, null, 'x']);

        $this->assertSame(['1', '2.5', '1', 'x'], $result);
    }

    // ── hashPayload stability: volatile fields excluded, real fields still count ──

    public function test_hash_payload_is_stable_across_volatile_digest_identity_field_changes(): void
    {
        $normalizer = $this->normalizer();
        $base = [
            'queue_health' => 'green',
            'digest_id' => 'digest-1',
            'generated_at' => 1_700_000_000,
            'terminal_loop_health_digest_hash' => 'hash-a',
        ];
        $mutatedVolatileOnly = [
            'queue_health' => 'green',
            'digest_id' => 'digest-2',
            'generated_at' => 1_800_000_000,
            'terminal_loop_health_digest_hash' => 'hash-b',
        ];

        $this->assertSame($normalizer->hashPayload($base), $normalizer->hashPayload($mutatedVolatileOnly));
    }

    public function test_hash_payload_changes_when_non_volatile_queue_health_data_changes(): void
    {
        $normalizer = $this->normalizer();
        $base = ['queue_health' => 'green', 'digest_id' => 'digest-1'];
        $changed = ['queue_health' => 'red', 'digest_id' => 'digest-1'];

        $this->assertNotSame($normalizer->hashPayload($base), $normalizer->hashPayload($changed));
    }

    // ── stateless / no side effects ─────────────────────────────────────────────

    public function test_normalizer_source_has_no_io_provider_dispatch_or_git_calls(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/ControlPlane/TerminalLoopHealthDigestPayloadNormalizer.php'));
        foreach (['exec(', 'shell_exec', 'proc_open', 'Http::', 'DB::', 'Storage::', 'Artisan::', '`git '] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "normalizer must not contain {$forbidden}");
        }
    }
}
