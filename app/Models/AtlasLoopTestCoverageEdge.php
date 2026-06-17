<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ACDE QA5 — one OBSERVED source_path -> test_path coverage edge (the cross-module coverage corpus).
 *
 * Recorded from a REAL coverage source (a clover/coverage run mapping a covered source file to the suite
 * that exercised it), never from the model declaring its own coverage. AtlasLoopBroaderRegressionGate
 * UNIONS {@see testPathsFor()} onto its static-map selection when armed, so a cross-module suite the
 * subtree map cannot see is still run. The union is purely ADDITIVE — a wrong edge costs an extra green
 * suite, never hides a regression — so the ledger is safe to grow from a noisy source.
 */
class AtlasLoopTestCoverageEdge extends Model
{
    use HasUuids;

    protected $table = 'atlas_loop_test_coverage_edges';

    protected $fillable = [
        'source_path',
        'test_path',
        'observed_count',
        'last_observed_at',
    ];

    protected function casts(): array
    {
        return [
            'observed_count' => 'integer',
            'last_observed_at' => 'datetime',
        ];
    }

    /**
     * Upsert one observed coverage edge: first sighting inserts (observed_count=1), a repeat sighting
     * increments the count and refreshes last_observed_at. Idempotent on the (source_path, test_path) pair.
     */
    public static function recordEdge(string $sourcePath, string $testPath): void
    {
        $sourcePath = trim($sourcePath);
        $testPath = trim($testPath);
        if ($sourcePath === '' || $testPath === '') {
            return;
        }

        $edge = static::query()->firstOrNew([
            'source_path' => $sourcePath,
            'test_path' => $testPath,
        ]);
        $edge->observed_count = ($edge->observed_count ?? 0) + 1;
        $edge->last_observed_at = now();
        $edge->save();
    }

    /**
     * The distinct test paths the ledger has observed covering ANY of the given source paths — the
     * cross-module suites the gate unions onto its static selection.
     *
     * @param  list<string>  $sourcePaths
     * @return list<string>
     */
    public static function testPathsFor(array $sourcePaths): array
    {
        $sourcePaths = array_values(array_filter(array_map('trim', $sourcePaths), static fn (string $p): bool => $p !== ''));
        if ($sourcePaths === []) {
            return [];
        }

        return static::query()
            ->whereIn('source_path', $sourcePaths)
            ->orderBy('test_path')
            ->pluck('test_path')
            ->unique()
            ->values()
            ->all();
    }
}
