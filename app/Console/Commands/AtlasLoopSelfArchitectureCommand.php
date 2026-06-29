<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Introspection\AtlasLoopSelfArchitectureScanner;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopSelfArchitectureScanner} at the operator surface for the first time: a
 * read-only scan of the bounded app/Services/Ai/AutonomousEvolution tree that emits the deterministic
 * facts-only structure (schema atlas.loop.self_architecture_facts.v1: file_count, class_count, namespace_tree,
 * primitives_by_category, methods_by_class, lines_by_class) as JSON. No queue/DB/provider/process — facts only,
 * never a score (anti-Goodhart).
 */
final class AtlasLoopSelfArchitectureCommand extends Command
{
    protected $signature = 'atlas:loop:self-architecture {--json}';

    protected $description = 'Read-only self-architecture scan of the AutonomousEvolution tree (facts only, never a score).';

    public function handle(AtlasLoopSelfArchitectureScanner $scanner): int
    {
        $facts = $scanner->scan();
        $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
