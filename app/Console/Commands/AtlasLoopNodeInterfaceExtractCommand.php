<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopNodeInterfaceExtractor;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopNodeInterfaceExtractor::extract()} at the operator surface: parses a PHP
 * source file via the AST (decorrelated from the LLM that wrote it) and emits the node's exported interface —
 * declared types, their public methods, extends/implements, imports and container service-refs — as facts.
 *
 * Read-only + deterministic: it reads one source string, runs no provider/DB/mutation. A parse failure or a
 * missing file yields an empty (parsed=false) surface, never an error.
 */
final class AtlasLoopNodeInterfaceExtractCommand extends Command
{
    protected $signature = 'atlas:loop:node-interface-extract {--file=} {--json}';

    protected $description = 'Read-only AST extract of a PHP file public interface (types, methods, imports, service-refs).';

    public function handle(): int
    {
        $file = trim((string) $this->option('file'));
        if ($file === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'node-interface-extract requires --file=<path>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $abs = str_starts_with($file, '/') ? $file : base_path($file);
        $source = is_file($abs) ? (string) file_get_contents($abs) : '';

        $surface = app(AtlasLoopNodeInterfaceExtractor::class)->extract($source);

        $facts = ['schema' => 'atlas.loop.node_interface.v1', 'file' => $file] + $surface;

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('namespace: '.($surface['namespace'] ?? '(none)'));
            $this->line('parsed: '.($surface['parsed'] ? 'yes' : 'no'));
            foreach ($surface['types'] as $t) {
                $this->line($t['kind'].' '.$t['fqn'].'  methods=['.implode(',', $t['public_methods']).']');
            }
        }

        return self::SUCCESS;
    }
}
