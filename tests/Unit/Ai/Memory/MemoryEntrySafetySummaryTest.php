<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\MemoryEntrySafetySummary;
use PHPUnit\Framework\TestCase;

final class MemoryEntrySafetySummaryTest extends TestCase
{
    public function test_blocks_secret_from_provider_export(): void
    {
        $entry = new AtlasMemoryEntry;
        $entry->status = 'active';
        $entry->external_ai_allowed = true;
        $entry->privacy_class = 'secret';
        $entry->redaction_status = 'clean';
        $entry->content_hash = 'abc';

        $summary = MemoryEntrySafetySummary::forEntry($entry);
        $this->assertSame('atlas.memory_entry.safety.v1', $summary['schema_version']);
        $this->assertTrue($summary['memory_eligible']);
        $this->assertFalse($summary['provider_export_allowed']);
        $this->assertFalse($summary['raw_content_exposed']);
    }

    public function test_active_normal_entry_is_exportable(): void
    {
        $entry = new AtlasMemoryEntry;
        $entry->status = 'active';
        $entry->external_ai_allowed = true;
        $entry->privacy_class = 'normal';
        $entry->redaction_status = 'clean';
        $entry->content_hash = 'def';

        $summary = MemoryEntrySafetySummary::forEntry($entry);
        $this->assertTrue($summary['provider_export_allowed']);
        $this->assertTrue($summary['context_eligible']);
    }
}
