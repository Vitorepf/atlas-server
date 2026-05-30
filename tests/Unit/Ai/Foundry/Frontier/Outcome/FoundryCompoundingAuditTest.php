<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Foundry\Frontier\Outcome;

use App\Services\Ai\Foundry\Frontier\Outcome\FoundryCompoundingAuditService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use PHPUnit\Framework\TestCase;

/**
 * Finding 27 · compounding audit. Proves the read-only fold over the
 * append-only evolution_outcomes.jsonl + roadmap.jsonl computes proven count,
 * revert rate and capability growth so a 1/10-proven run is no longer
 * indistinguishable from a 10/10 run.
 *
 * The audit is exercised against deterministic JSONL fixtures written EXACTLY
 * as the materializer would. The service performs ZERO writes (real-or-blocked
 * read-model); the test asserts the ledger files are untouched.
 */
final class FoundryCompoundingAuditTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/ap_e_compounding_'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    public function test_one_consolidate_nine_reverts_yields_revert_rate_point_nine_and_proven_delta_one(): void
    {
        $area = 'agentic_engineering_os';

        // 1 consolidate (proven green) + 9 reverts (refuted_by_reality).
        $outcomes = [$this->outcome('consolidate', false)];
        for ($i = 0; $i < 9; $i++) {
            $outcomes[] = $this->outcome('reverted', true);
        }
        $this->writeLedger($area, 'evolution_outcomes.jsonl', $outcomes);

        // prior roadmap version: zero proven. latest: one proven => delta +1.
        $this->writeLedger($area, 'roadmap.jsonl', [
            $this->roadmap(1, ['implemented']),
            $this->roadmap(2, ['proven']),
        ]);

        $audit = $this->service()->audit($area);

        self::assertSame(10, $audit['cycles']);
        self::assertSame(1, $audit['consolidated_count']);
        self::assertSame(9, $audit['reverted_count']);
        self::assertSame(9, $audit['refuted_by_reality_count']);
        self::assertSame(1, $audit['proven_capabilities_now']);
        self::assertSame(1, $audit['proven_delta_vs_prior_version']);
        self::assertSame(0.9, $audit['revert_rate']);
    }

    public function test_proven_count_comes_only_from_roadmap_state_never_re_derived(): void
    {
        $area = 'area';

        // 10 consolidate outcomes claiming 'improved', but the roadmap only
        // records ONE capability in state='proven'. The audit must report 1,
        // proving it reads recorded proven state and never re-derives proven
        // from the outcome 'improved'/'consolidate' flags (preserves I5).
        $outcomes = [];
        for ($i = 0; $i < 10; $i++) {
            $outcomes[] = $this->outcome('consolidate', false, improved: true);
        }
        $this->writeLedger($area, 'evolution_outcomes.jsonl', $outcomes);
        $this->writeLedger($area, 'roadmap.jsonl', [
            $this->roadmap(1, ['proven', 'implemented', 'implemented']),
        ]);

        $audit = $this->service()->audit($area);

        self::assertSame(10, $audit['consolidated_count']);
        self::assertSame(1, $audit['proven_capabilities_now'], 'proven MUST come only from roadmap state, never re-derived from outcomes');
    }

    public function test_absent_ledger_yields_zeroed_deterministic_audit(): void
    {
        $audit = $this->service()->audit('never_ran');

        self::assertSame(0, $audit['cycles']);
        self::assertSame(0, $audit['consolidated_count']);
        self::assertSame(0, $audit['reverted_count']);
        self::assertSame(0, $audit['refuted_by_reality_count']);
        self::assertSame(0, $audit['proven_capabilities_now']);
        self::assertSame(0, $audit['proven_delta_vs_prior_version']);
        self::assertSame(0.0, $audit['revert_rate']);

        // Deterministic: a second fold of the same (absent) ledgers is byte-identical.
        $again = $this->service()->audit('never_ran');
        self::assertSame($audit['audit_hash'], $again['audit_hash']);
    }

    public function test_audit_performs_zero_writes_and_zero_canonization(): void
    {
        $area = 'no_write_area';
        $this->writeLedger($area, 'evolution_outcomes.jsonl', [$this->outcome('consolidate', false)]);
        $this->writeLedger($area, 'roadmap.jsonl', [$this->roadmap(1, ['proven'])]);

        $dir = $this->tmpDir.DIRECTORY_SEPARATOR.$area;
        $before = $this->snapshotDir($dir);

        $this->service()->audit($area);

        $after = $this->snapshotDir($dir);
        self::assertSame($before, $after, 'audit is a read-model: it MUST NOT write/canonize anything');

        // No compounding_audit.jsonl (or any new file) was emitted.
        self::assertSame(
            ['evolution_outcomes.jsonl', 'roadmap.jsonl'],
            array_keys($after),
            'audit MUST NOT author any new ledger file (zero canonization)',
        );
    }

    public function test_audit_hash_is_mission_canonical_hash(): void
    {
        $area = 'hash_area';
        $this->writeLedger($area, 'evolution_outcomes.jsonl', [
            $this->outcome('consolidate', false),
            $this->outcome('reverted', true),
        ]);
        $this->writeLedger($area, 'roadmap.jsonl', [
            $this->roadmap(1, ['implemented']),
            $this->roadmap(2, ['proven']),
        ]);

        $audit = $this->service()->audit($area);

        $expected = MissionCanonicalHash::sha256([
            'area_id' => $area,
            'cycles' => 2,
            'consolidated_count' => 1,
            'reverted_count' => 1,
            'refuted_by_reality_count' => 1,
            'proven_capabilities_now' => 1,
            'proven_delta_vs_prior_version' => 1,
            'revert_rate' => 0.5,
        ]);

        self::assertSame($expected, $audit['audit_hash']);
        self::assertSame('atlas.foundry.compounding_audit.v1', $audit['schema_version']);
    }

    // ---- helpers -------------------------------------------------------------

    private function service(): FoundryCompoundingAuditService
    {
        $service = new FoundryCompoundingAuditService();
        $service->setStorageDirForTesting($this->tmpDir);

        return $service;
    }

    /**
     * @return array<string,mixed>
     */
    private function outcome(string $action, bool $refuted, bool $improved = false): array
    {
        return [
            'schema_version' => 'atlas.foundry.evolution_outcome.v1',
            'proposal_id' => 'p-'.$action,
            'action' => $action,
            'improved' => $improved,
            'refuted_by_reality' => $refuted,
        ];
    }

    /**
     * @param  list<string>  $states
     * @return array<string,mixed>
     */
    private function roadmap(int $version, array $states): array
    {
        $capabilities = [];
        foreach ($states as $i => $state) {
            $capabilities[] = ['finding_id' => 'f-'.$i, 'state' => $state, 'version' => 1];
        }

        return [
            'schema_version' => 'atlas.foundry.roadmap.v1',
            'version' => $version,
            'capabilities' => $capabilities,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $lines
     */
    private function writeLedger(string $area, string $file, array $lines): void
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower($area)) ?: 'unknown_area';
        $dir = $this->tmpDir.DIRECTORY_SEPARATOR.$slug;
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $payload = '';
        foreach ($lines as $line) {
            $payload .= json_encode($line, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
        }
        file_put_contents($dir.DIRECTORY_SEPARATOR.$file, $payload);
    }

    /**
     * @return array<string,string> filename => sha256 of contents
     */
    private function snapshotDir(string $dir): array
    {
        $snap = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $snap[$name] = hash_file('sha256', $dir.DIRECTORY_SEPARATOR.$name) ?: '';
        }
        ksort($snap);

        return $snap;
    }
}
