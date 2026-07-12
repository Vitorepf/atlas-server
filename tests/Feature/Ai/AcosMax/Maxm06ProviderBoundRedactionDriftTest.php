<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AcosMax;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AtlasProviderProjectionService;
use App\Services\Ai\Cognition\Watchdog\Checks\ProviderBoundRedactionDriftWatchdogCheck;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

final class Maxm06ProviderBoundRedactionDriftTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasMemoryEntryTable();
    }

    protected function tearDown(): void
    {
        $this->dropAtlasMemoryEntryTable();
        parent::tearDown();
    }

    public function test_projection_attaches_redaction_receipt_without_raw_value(): void
    {
        $this->entry([
            'title' => 'MAXM-06 receipt fixture',
            'body' => 'MAXM06-RAW-MARKER must not appear in provider projection.',
            'summary' => 'MAXM06-RAW-MARKER summary must not appear.',
            'redacted_body' => 'Provider-safe body after redaction.',
            'redacted_summary' => 'Provider-safe summary after redaction.',
            'redaction_status' => 'redacted',
        ]);

        $projection = app(AtlasProviderProjectionService::class)->generate('claude', [
            'workspace' => base_path(),
        ], [
            'max_lines' => 40,
            'memory_limit' => 5,
        ]);

        $this->assertStringContainsString('Provider-safe summary after redaction.', (string) $projection['content']);
        $this->assertStringNotContainsString('MAXM06-RAW-MARKER', (string) $projection['content']);
        $this->assertSame('atlas.provider_bound_redaction_receipt.v1', data_get($projection, 'redaction_receipts.0.schema'));
        $this->assertSame(['atlas_security_redaction'], data_get($projection, 'redaction_receipts.0.patterns_fired'));
        $this->assertSame('redacted_projection_field', data_get($projection, 'redaction_receipts.0.verified_by'));
        $this->assertStringStartsWith('memory:', (string) data_get($projection, 'redaction_receipts.0.memory_ref'));
    }

    public function test_watchdog_alerts_when_redacted_row_emits_raw_text_through_fake_stamp(): void
    {
        $this->entry([
            'title' => 'MAXM-06 fake stamp fixture',
            'body' => 'MAXM06-DRIFT-BODY',
            'summary' => 'MAXM06-DRIFT-SUMMARY',
            'redacted_body' => 'MAXM06-DRIFT-BODY',
            'redacted_summary' => 'MAXM06-DRIFT-SUMMARY',
            'redaction_status' => 'redacted',
            'metadata' => [
                'privacy' => [
                    'class' => 'normal',
                    'external_ai_allowed' => true,
                    'provider_body_verified' => true,
                    'provider_body_verified_by' => 'local-hash-v1',
                ],
            ],
        ]);

        $result = app(ProviderBoundRedactionDriftWatchdogCheck::class)->run()->toArray();

        $this->assertSame('alert', $result['status']);
        $this->assertSame('provider_bound_redaction_drift', data_get($result, 'alert.code'));
        $this->assertSame(1, data_get($result, 'evidence.drift_count'));
        $this->assertContains('provider_body_contains_raw_body', data_get($result, 'evidence.drift.0.signals'));
        $this->assertContains('provider_summary_contains_raw_summary', data_get($result, 'evidence.drift.0.signals'));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function entry(array $overrides = []): AtlasMemoryEntry
    {
        $metadata = (array) ($overrides['metadata'] ?? [
            'privacy' => [
                'class' => 'normal',
                'external_ai_allowed' => true,
            ],
        ]);
        unset($overrides['metadata']);

        $entry = new AtlasMemoryEntry;
        $entry->forceFill(array_merge([
            'id' => (string) Str::uuid(),
            'memory_type' => 'technical_context',
            'scope_type' => 'global',
            'scope_id' => null,
            'title' => 'MAXM-06 fixture',
            'body' => 'body',
            'summary' => 'summary',
            'redacted_title' => null,
            'redacted_body' => null,
            'redacted_summary' => null,
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'source_type' => 'maxm06_fixture',
            'status' => 'active',
            'tags' => [],
            'metadata' => $metadata,
            'priority' => 95,
            'importance' => 5,
        ], $overrides))->save();

        return $entry->refresh();
    }
}
