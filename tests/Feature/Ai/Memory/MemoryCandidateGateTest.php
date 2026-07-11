<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Models\AtlasMemoryCandidate;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Autonomy\AtlasWeeklyMemoryDigestService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class MemoryCandidateGateTest extends TestCase
{
    /** @var list<object> */
    private array $migrations = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'database/migrations/2026_05_02_000000_create_atlas_memory_entries_table.php',
            'database/migrations/2026_07_11_000100_create_atlas_memory_candidates_table.php',
        ] as $path) {
            $migration = require base_path($path);
            $migration->down();
            $migration->up();
            $this->migrations[] = $migration;
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->migrations) as $migration) {
            $migration->down();
        }

        parent::tearDown();
    }

    public function test_failing_candidate_stays_outside_active_memory_with_named_missing_evidence(): void
    {
        $source = base_path('docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md');

        $code = Artisan::call('atlas:memory:capture-candidates', [
            '--source' => $source,
            '--title' => 'Candidate without provenance',
            '--summary' => 'A useful summary that is not a title clone',
            '--body' => 'This body has no rationale marker or provenance quote.',
            '--domain' => 'governance',
            '--apply' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $code);
        $candidate = AtlasMemoryCandidate::query()->firstOrFail();

        $this->assertSame('rejected', $candidate->status);
        $this->assertContains('rationale_with_provenance_required', $candidate->missing_checks);
        $this->assertNull($candidate->memory_entry_id);
        $this->assertSame(0, AtlasMemoryEntry::query()->where('source_type', 'memory_candidate')->count());
    }

    public function test_passing_candidate_auto_admits_through_registry_and_appears_in_digest_with_forget_handle(): void
    {
        $source = base_path('docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md');

        $code = Artisan::call('atlas:memory:capture-candidates', [
            '--source' => $source,
            '--title' => 'Canonical docs govern implementation context',
            '--summary' => 'Implementation context must cite canonical engineering knowledge docs before trusting derived read models.',
            '--body' => 'motivo: canonical governance doc requires repo docs as the authoring source of truth. provenance: "Repo docs in docs/engineering-knowledge-base are the authoring source of truth."',
            '--path' => ['docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md'],
            '--domain' => 'governance',
            '--apply' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $code);

        $candidate = AtlasMemoryCandidate::query()->firstOrFail();
        $entry = AtlasMemoryEntry::query()->where('source_type', 'memory_candidate')->firstOrFail();

        $this->assertSame('admitted', $candidate->status);
        $this->assertSame($entry->id, $candidate->memory_entry_id);
        $this->assertNotSame($entry->title, $entry->summary);
        $this->assertSame('governance', data_get($entry->metadata, 'domains.0'));
        $this->assertSame($source, data_get($entry->metadata, 'candidate.source'));
        $this->assertStringContainsString('memory-forget '.$entry->id, (string) data_get($entry->metadata, 'candidate.reverse_handle'));

        $digest = (new AtlasWeeklyMemoryDigestService)->digest(7);
        $digestItem = collect($digest['memory_candidates']['items'])->firstWhere('id', $candidate->id);

        $this->assertNotNull($digestItem);
        $this->assertSame('admitted', $digestItem['status']);
        $this->assertStringContainsString('memory-forget '.$entry->id, $digestItem['reverse_handle']);
    }

    public function test_growth_report_is_read_only_and_reports_channel_health(): void
    {
        AtlasMemoryCandidate::query()->create([
            'source_type' => 'docs',
            'source_id' => 'example',
            'title' => 'Rejected',
            'body' => 'missing',
            'summary' => 'missing summary',
            'candidate_payload' => [],
            'quality_report' => ['missing_checks' => ['meta_paths_required']],
            'missing_checks' => ['meta_paths_required'],
            'status' => 'rejected',
        ]);

        $beforeEntries = AtlasMemoryEntry::query()->count();
        $beforeCandidates = AtlasMemoryCandidate::query()->count();

        $code = Artisan::call('atlas:memory:growth-report', ['--days' => 7, '--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $code);
        $this->assertSame($beforeEntries, AtlasMemoryEntry::query()->count());
        $this->assertSame($beforeCandidates, AtlasMemoryCandidate::query()->count());
        $this->assertSame(0, data_get($payload, 'memory_growth.novas_7d'));
        $this->assertSame(1, data_get($payload, 'memory_growth.rejeitadas_por_check_7d'));
        $this->assertSame('capture_channel_stalled', data_get($payload, 'memory_growth.alert'));
    }
}
