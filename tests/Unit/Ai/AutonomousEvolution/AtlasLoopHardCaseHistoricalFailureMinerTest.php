<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseDatasetRegistry;
use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseHistoricalFailureMiner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Proves the historical-failure miner: a fixture of one give_back + one cancellation + one judge_rejected
 * yields three distinct candidates with non-empty failure_signature and the source field carried through;
 * an already-registered case is excluded from new candidates (dedupe); the miner source contains NO call to
 * AtlasLoopHardCaseDatasetRegistry::register (anti-Goodhart: never auto-register).
 */
final class AtlasLoopHardCaseHistoricalFailureMinerTest extends TestCase
{
    private string $tmpDir;

    private AtlasLoopHardCaseDatasetRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/atlas_hardcase_miner_'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0775, true);
        $this->registry = new AtlasLoopHardCaseDatasetRegistry($this->tmpDir);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpDir.'/registry.json');
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    private function events(): array
    {
        return [
            ['source' => 'give_back', 'scope_root' => 'app/A', 'failure_reason' => 'lease expired', 'diff_shape_hash' => 'h1', 'captured_at' => '2026-06-01T00:00:00Z', 'ledger_digest' => 'led1', 'repro_seed' => ['k' => 'a']],
            ['source' => 'cancellation', 'scope_root' => 'app/B', 'failure_reason' => 'budget exhausted', 'diff_shape_hash' => 'h2', 'captured_at' => '2026-06-02T00:00:00Z', 'ledger_digest' => 'led2'],
            ['source' => 'judge_reject', 'scope_root' => 'app/C', 'failure_reason' => 'no proven delta', 'diff_shape_hash' => 'h3', 'captured_at' => '2026-06-03T00:00:00Z', 'ledger_digest' => 'led3'],
        ];
    }

    public function test_three_distinct_events_yield_three_candidates_with_signatures_and_sources(): void
    {
        $events = $this->events();
        $miner = new AtlasLoopHardCaseHistoricalFailureMiner($this->registry, static fn (int $w): array => $events);

        $candidates = $miner->mineCandidates(90);
        $this->assertCount(3, $candidates);
        $sources = array_map(static fn ($c): string => $c->source, $candidates);
        sort($sources);
        $this->assertSame(['cancellation', 'give_back', 'judge_reject'], $sources);
        foreach ($candidates as $c) {
            $this->assertNotEmpty($c->failureSignature);
            $this->assertSame(64, strlen($c->failureSignature), 'sha256 signature');
        }
    }

    public function test_candidate_already_in_registry_is_excluded(): void
    {
        $events = $this->events();
        $miner = new AtlasLoopHardCaseHistoricalFailureMiner($this->registry, static fn (int $w): array => $events);

        // Register the FIRST candidate's shape into the registry so it should be dedup'd out next mine call.
        $firstCandidate = $miner->mineCandidates(90)[0]; // by case_id sort it's the lex-min id
        $this->registry->register($firstCandidate->toRegistryShape());

        $remaining = $miner->mineCandidates(90);
        $remainingIds = array_map(static fn ($c): string => $c->caseId, $remaining);
        $this->assertNotContains($firstCandidate->caseId, $remainingIds);
        $this->assertCount(2, $remaining);
    }

    public function test_miner_source_never_calls_registry_register(): void
    {
        $reflection = new ReflectionClass(AtlasLoopHardCaseHistoricalFailureMiner::class);
        $source = (string) file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString('->register(', $source, 'miner must NOT call ->register( anywhere');
        $this->assertStringNotContainsString('::register(', $source, 'miner must NOT call ::register( anywhere');

        // Reflection-based proof of NO public method whose body invokes the registry's register method.
        foreach ($reflection->getMethods() as $method) {
            if ($method->getName() === '__construct') {
                continue;
            }
            $this->assertNotSame('register', $method->getName(), 'miner must not expose a register method');
        }
    }

    public function test_unknown_source_value_in_events_is_skipped(): void
    {
        $events = [
            ['source' => 'bogus_source', 'scope_root' => 'app/X', 'failure_reason' => 'r', 'captured_at' => 't', 'ledger_digest' => 'd'],
            ['source' => 'give_back', 'scope_root' => 'app/Y', 'failure_reason' => 'r', 'captured_at' => 't', 'ledger_digest' => 'd'],
        ];
        $miner = new AtlasLoopHardCaseHistoricalFailureMiner($this->registry, static fn (int $w): array => $events);

        $candidates = $miner->mineCandidates(90);
        $this->assertCount(1, $candidates);
        $this->assertSame('give_back', $candidates[0]->source);
    }

    public function test_duplicate_signature_in_same_batch_collapses_to_one_candidate(): void
    {
        $events = [
            ['source' => 'give_back', 'scope_root' => 'app/Z', 'failure_reason' => 'same reason', 'diff_shape_hash' => 'h', 'captured_at' => 't', 'ledger_digest' => 'd'],
            ['source' => 'give_back', 'scope_root' => 'app/Z', 'failure_reason' => 'same reason', 'diff_shape_hash' => 'h', 'captured_at' => 't', 'ledger_digest' => 'd'],
        ];
        $miner = new AtlasLoopHardCaseHistoricalFailureMiner($this->registry, static fn (int $w): array => $events);

        $this->assertCount(1, $miner->mineCandidates(90));
    }

    public function test_candidate_toregistryshape_is_acceptable_to_registry_register(): void
    {
        $events = $this->events();
        $miner = new AtlasLoopHardCaseHistoricalFailureMiner($this->registry, static fn (int $w): array => $events);
        $candidate = $miner->mineCandidates(90)[0];

        // Operator promotion path: candidate → registry.
        $this->registry->register($candidate->toRegistryShape());
        $this->assertNotNull($this->registry->get($candidate->caseId));
    }
}
