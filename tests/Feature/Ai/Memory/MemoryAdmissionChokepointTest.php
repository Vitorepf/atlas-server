<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use App\Services\Ai\AtlasOpenBrainWriteBackService;
use App\Services\Ai\Memory\AtlasMemoryCandidateGateService;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

final class MemoryAdmissionChokepointTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    /** @var list<object> */
    private array $migrations = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAtlasMemoryEntryTable();
        $this->runMigration('2026_07_11_000100_create_atlas_memory_candidates_table.php');
        $this->runMigration('2026_05_19_030000_strengthen_rag_feedback_and_create_learning_proposals.php');

        config()->set('atlas.aurg.ingest_on_write', false);
        config()->set('atlas.ai.capture_quality_gate.mode', 'observe');
        config()->set('atlas.memory_admission.mode', 'observe');
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->migrations) as $migration) {
            $migration->down();
        }

        $this->dropAtlasMemoryEntryTable();

        parent::tearDown();
    }

    public function test_secret_candidate_writeback_and_direct_record_are_observed_with_g3_receipts(): void
    {
        $candidateResult = app(AtlasMemoryCandidateGateService::class)->capture($this->candidatePayload([
            'body' => "motivo: synthetic scanner fixture. provenance: \"MAXI-01 G3 scanner fixture.\"\nAWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE",
            'privacy_class' => 'normal',
            'immune_signals' => ['contains_secret' => false],
        ]), apply: true);

        $candidateEntry = $candidateResult['entry'];
        $this->assertInstanceOf(AtlasMemoryEntry::class, $candidateEntry);
        $this->assertSame('admitted', $candidateResult['candidate']->status);
        $this->assertSame('observe', data_get($candidateEntry->metadata, 'acos_max.asi_02.admission.mode'));
        $this->assertContains('G3', data_get($candidateEntry->metadata, 'acos_max.asi_02.admission.verdict.blocking_gate_ids'));
        $this->assertSame('secret_or_sensitive_present', data_get($candidateEntry->metadata, 'acos_max.asi_02.admission.verdict.reasons.G3'));
        $this->assertTrue(data_get($candidateEntry->metadata, 'acos_max.asi_02.admission.derived_signals.contains_secret'));
        $this->assertContains('CodeGraphSecretScanner', data_get($candidateEntry->metadata, 'acos_max.asi_02.admission.signal_sources.G3'));
        $this->assertContains('G3', data_get($candidateResult['quality'], 'admission.verdict.blocking_gate_ids'));

        $writeback = app(AtlasOpenBrainWriteBackService::class)->proposeLearning([
            'kind' => 'retrieval_hint',
            'summary' => "cache workspace resolution per request after a measured repeated lookup path\n-----BEGIN PRIVATE KEY-----\nabc123synthetic\n-----END PRIVATE KEY-----",
            'evidence_refs' => ['app/Services/Engineering/CodeGraph/CodeGraphWorkspaceIdentity.php:42'],
            'immune_signals' => ['contains_secret' => false],
        ]);

        $this->assertTrue($writeback['ok']);
        $this->assertSame('observe', data_get($writeback, 'memory_admission.mode'));
        $this->assertContains('G3', data_get($writeback, 'memory_admission.verdict.blocking_gate_ids'));
        $this->assertTrue(data_get($writeback, 'memory_admission.derived_signals.contains_secret'));

        $directEntry = app(AtlasMemoryRegistryService::class)->record($this->entryAttributes([
            'source_type' => 'direct_record_test',
            'privacy_class' => 'normal',
            'body' => "motivo: synthetic PEM fixture.\n-----BEGIN PRIVATE KEY-----\nabc123synthetic\n-----END PRIVATE KEY-----",
            'metadata' => [
                'immune_signals' => ['contains_secret' => false],
            ],
        ]));

        $this->assertSame('observe', data_get($directEntry->metadata, 'acos_max.asi_02.admission.mode'));
        $this->assertContains('G3', data_get($directEntry->metadata, 'acos_max.asi_02.admission.verdict.blocking_gate_ids'));
        $this->assertTrue(data_get($directEntry->metadata, 'acos_max.asi_02.admission.derived_signals.contains_secret'));
    }

    public function test_contradictory_number_against_newer_same_scope_memory_is_observed_with_g4_receipt(): void
    {
        app(AtlasMemoryRegistryService::class)->record($this->entryAttributes([
            'source_type' => 'newer_authority',
            'title' => 'Authoritative latency fact',
            'summary' => 'Atlas admission latency p95 is 120ms.',
            'body' => 'motivo: newer measured authority. Atlas admission latency p95 is 120ms.',
            'recorded_at' => now(),
        ]));

        $entry = app(AtlasMemoryRegistryService::class)->record($this->entryAttributes([
            'source_type' => 'older_candidate',
            'title' => 'Candidate latency fact',
            'summary' => 'Atlas admission latency p95 is 240ms.',
            'body' => 'motivo: stale claim under admission. Atlas admission latency p95 is 240ms.',
            'recorded_at' => now()->subDay(),
            'metadata' => [
                'immune_signals' => ['contradicts_newer' => false],
            ],
        ]));

        $receipt = data_get($entry->metadata, 'acos_max.asi_02.admission');

        $this->assertSame('observe', $receipt['mode']);
        $this->assertContains('G4', data_get($receipt, 'verdict.blocking_gate_ids'));
        $this->assertSame('contradicts_newer_authority', data_get($receipt, 'verdict.reasons.G4'));
        $this->assertTrue(data_get($receipt, 'derived_signals.contradicts_newer'));
        $this->assertContains('FactPairPolarityContradictionDetector', data_get($receipt, 'signal_sources.G4'));
    }

    public function test_safe_direct_record_is_not_marked_as_g3_blocked(): void
    {
        $entry = app(AtlasMemoryRegistryService::class)->record($this->entryAttributes([
            'source_type' => 'safe_record_test',
            'privacy_class' => 'normal',
        ]));

        $receipt = data_get($entry->metadata, 'acos_max.asi_02.admission');

        $this->assertSame('observe', $receipt['mode']);
        $this->assertSame('pass', data_get($receipt, 'verdict.gate_statuses.G3'));
        $this->assertSame('pass', data_get($receipt, 'verdict.gate_statuses.G4'));
        $this->assertNotContains('G3', data_get($receipt, 'verdict.blocking_gate_ids'));
        $this->assertNotContains('G4', data_get($receipt, 'verdict.blocking_gate_ids'));
        $this->assertFalse(data_get($receipt, 'derived_signals.contains_secret'));
        $this->assertFalse(data_get($receipt, 'derived_signals.contradicts_newer'));
    }

    public function test_admission_window_reports_pending_window_until_fifty_real_writes_exist(): void
    {
        foreach (range(1, 3) as $i) {
            app(AtlasMemoryRegistryService::class)->record($this->entryAttributes([
                'source_type' => 'window_test_'.$i,
            ]));
        }

        $window = app(AtlasMemoryRegistryService::class)->admissionWindowReport(minWrites: 50);

        $this->assertSame('pending_window', $window['status']);
        $this->assertSame(3, $window['writes']);
        $this->assertSame(3, $window['gate_evaluations']);
        $this->assertStringContainsString('ELEV-14', $window['note']);
    }

    public function test_memory_entry_admission_producers_delegate_to_registry_and_db_level_mutations_are_absent(): void
    {
        $this->assertSame([], $this->directMemoryEntryWritesOutsideRegistry());

        $producerContracts = [
            'app/Services/Ai/Memory/AtlasMemoryCandidateGateService.php' => '->registry->record(',
            'app/Services/Ai/Memory/AtlasMemoryDeltaPromotionService.php' => '->registry->record(',
            'app/Services/Ai/Memory/AtlasVerbatimMemoryService.php' => '->registry->curate(',
            'app/Services/Ai/AtlasOpenBrainMcpService.php' => '->registry->record(',
        ];

        foreach ($producerContracts as $relativePath => $needle) {
            $contents = (string) file_get_contents(base_path($relativePath));
            $this->assertStringContainsString($needle, $contents, $relativePath.' must delegate memory admission through AtlasMemoryRegistryService');
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function candidatePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Canonical memory admission test candidate',
            'summary' => 'Candidate admission must run cognitive immune checks before entering memory.',
            'body' => 'motivo: ASI-02 requires one registry chokepoint for memory admission. provenance: "Porta única de admissão de memória."',
            'paths' => ['docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md'],
            'domain' => 'acos-max',
            'memory_type' => 'technical_context',
            'scope_type' => 'global',
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function entryAttributes(array $overrides = []): array
    {
        return array_merge([
            'memory_type' => 'technical_context',
            'scope_type' => 'global',
            'title' => 'Canonical memory admission direct record',
            'summary' => 'Direct registry writes must carry a cognitive immune admission receipt.',
            'body' => 'motivo: direct memory admission needs an auditable G0-G8 receipt. provenance: "ASI-02 memory admission chokepoint."',
            'importance' => 3,
            'priority' => 60,
            'confidence' => 0.8,
            'source_type' => 'memory_admission_test',
            'source_label' => 'MemoryAdmissionChokepointTest',
        ], $overrides);
    }

    private function runMigration(string $file): void
    {
        $migration = require database_path('migrations/'.$file);
        $migration->up();
        $this->migrations[] = $migration;
    }

    /**
     * @return list<string>
     */
    private function directMemoryEntryWritesOutsideRegistry(): array
    {
        $root = base_path('app');
        $violations = [];
        $patterns = [
            '/AtlasMemoryEntry::\s*create\s*\(/s',
            '/AtlasMemoryEntry::query\s*\(\s*\)\s*->\s*(create|updateOrCreate)\s*\(/s',
            '/DB::table\s*\(\s*[\'"]atlas_memory_entries[\'"]\s*\)\s*->\s*(insert|upsert|update|delete)\s*\(/s',
        ];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            if ($relativePath === 'app/Services/Ai/Memory/AtlasMemoryRegistryService.php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $contents) === 1) {
                    $violations[] = $relativePath;
                    break;
                }
            }
        }

        sort($violations);

        return array_values(array_unique($violations));
    }
}
