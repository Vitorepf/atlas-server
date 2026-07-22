<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\MemoryIntegration;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MemoryIntegration\AtlasCortexMemoryReader;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

final class AtlasCortexMemoryReaderTest extends TestCase
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

    #[Test]
    public function it_returns_only_entries_explicitly_published_to_cortex(): void
    {
        $publishedByTag = $this->memory([
            'title' => 'Published tag',
            'redacted_body' => 'provider-safe tag body',
            'tags' => ['publish:cortex'],
            'recorded_at' => CarbonImmutable::parse('2026-06-24T06:00:00+00:00'),
        ]);
        $publishedByFlag = $this->memory([
            'title' => 'Published flag',
            'redacted_body' => 'provider-safe flag body',
            'metadata' => ['publish_to_cortex' => true],
            'recorded_at' => CarbonImmutable::parse('2026-06-24T06:01:00+00:00'),
        ]);
        $this->memory([
            'title' => 'Not published',
            'redacted_body' => 'should stay out',
            'tags' => ['other'],
            'recorded_at' => CarbonImmutable::parse('2026-06-24T06:02:00+00:00'),
        ]);

        $result = (new AtlasCortexMemoryReader)->read();

        self::assertSame([$publishedByTag->id, $publishedByFlag->id], array_column($result, 'memory_entry_id'));
        self::assertSame(['provider-safe tag body', 'provider-safe flag body'], array_column($result, 'body'));
    }

    #[Test]
    public function it_is_read_only_against_the_operator_memory_store(): void
    {
        $this->memory([
            'title' => 'Published tag',
            'redacted_body' => 'provider-safe tag body',
            'tags' => ['publish:cortex'],
        ]);
        $beforeCount = AtlasMemoryEntry::query()->count();

        (new AtlasCortexMemoryReader)->read();

        $afterCount = AtlasMemoryEntry::query()->count();
        $source = file_get_contents(app_path('Services/Ai/AutonomousEvolution/Discovery/Cortex/MemoryIntegration/AtlasCortexMemoryReader.php'));

        self::assertSame($beforeCount, $afterCount);
        self::assertStringNotContainsString('->create(', (string) $source);
        self::assertStringNotContainsString('->update(', (string) $source);
        self::assertStringNotContainsString('->delete(', (string) $source);
        self::assertStringNotContainsString('->save(', (string) $source);
    }

    #[Test]
    public function it_returns_an_empty_collection_when_no_entries_are_opted_in(): void
    {
        $this->memory([
            'title' => 'Not published',
            'tags' => ['other'],
            'redacted_body' => 'stays out',
        ]);

        self::assertSame([], (new AtlasCortexMemoryReader)->read());
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function memory(array $overrides): AtlasMemoryEntry
    {
        return AtlasMemoryEntry::create(array_merge([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Memory',
            'body' => 'operator body',
            'redacted_body' => 'provider-safe body',
            'summary' => 'summary',
            'redacted_summary' => 'provider-safe summary',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'status' => 'active',
            'tags' => [],
            'metadata' => [],
            'recorded_at' => CarbonImmutable::parse('2026-06-24T06:00:00+00:00'),
        ], $overrides));
    }
}
