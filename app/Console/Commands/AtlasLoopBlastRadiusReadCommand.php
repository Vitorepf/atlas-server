<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBlastRadiusReader;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopBlastRadiusReader::read()} at the operator surface: for a target file path,
 * emits the blast radius (transitive consumers + coarse risk band) as deterministic facts — "these consume
 * you; keep them green". Read-only: the reader only queries the indexed code-graph world model; it never writes
 * and is flag-gated default-OFF (yields empty facts until the operator arms it for a freshly-indexed workspace).
 */
final class AtlasLoopBlastRadiusReadCommand extends Command
{
    protected $signature = 'atlas:loop:blast-radius-read {--target=} {--json}';

    protected $description = 'Read-only blast radius (transitive consumers) for a target path from the indexed code-graph.';

    public function handle(AtlasLoopBlastRadiusReader $reader): int
    {
        $target = trim((string) $this->option('target'));
        if ($target === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'target_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $result = $reader->read($target);

        $this->line((string) json_encode([
            'schema' => 'atlas.loop.blast_radius_read.v1',
            'target' => $target,
            'consumer_count' => (int) ($result['consumer_count'] ?? 0),
            'risk' => (string) ($result['risk'] ?? 'none'),
            'consumers' => array_values((array) ($result['consumers'] ?? [])),
            'truncated' => (bool) ($result['truncated'] ?? false),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
