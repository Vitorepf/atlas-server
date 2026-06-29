<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\BehaviorDelta\AtlasLoopBehaviorDeltaSnapshotter;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopBehaviorDeltaSnapshotter::snapshot()} at the operator surface: captures a
 * scope's behavior surface (every symbol's public-API signature hash + its caller fqcns) and emits the
 * deterministic snapshot facts the later behavior-Δ computer diffs.
 *
 * Read-only + deterministic: zero writes, no git mutation, no provider/network — the same (repo-root, scope-root)
 * on an unchanged scope yields a byte-identical snapshot (captured_at is the scope's latest source mtime).
 */
final class AtlasLoopBehaviorDeltaSnapshotCommand extends Command
{
    protected $signature = 'atlas:loop:behavior-delta-snapshot {--repo-root=} {--scope-root=} {--json}';

    protected $description = 'Read-only behavior-surface snapshot of a scope (public-API signature + caller fqcns).';

    public function handle(): int
    {
        $repoRoot = trim((string) $this->option('repo-root'));
        if ($repoRoot === '') {
            $repoRoot = base_path();
        }
        $scopeRoot = trim((string) $this->option('scope-root'));
        if ($scopeRoot === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'behavior-delta-snapshot requires --scope-root=<repo-relative path>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $snapshot = app(AtlasLoopBehaviorDeltaSnapshotter::class)->snapshot($repoRoot, $scopeRoot);

        if ($this->option('json')) {
            $this->line((string) json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('api_surface_hash: '.$snapshot['api_surface_hash']);
            $this->line('captured_at: '.$snapshot['captured_at']);
            foreach ($snapshot['symbols'] as $s) {
                $this->line($s['fqcn'].'  '.$s['public_api_signature_hash']);
            }
        }

        return self::SUCCESS;
    }
}
