<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSystemGraphContextBuilderService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the kernel Context Builder step.
 *
 * Compiles the curated, traceable context pack (sources, summary, limits) that
 * the AI receives before policy + decision. Demonstrates the invariant that an
 * untraceable source and an unauthorized source are both excluded.
 *
 * @see docs/engineering-knowledge-base/system-graph/context-builder.md
 */
final class AtlasSystemGraphContextBuilderCommand extends Command
{
    protected $signature = 'atlas:aaeos:system-graph-context-builder {--json : Machine-readable JSON output}';

    protected $description = 'Compile the Context Builder pack (traceable sources, summary, limits) before policy and decision.';

    public function handle(AtlasSystemGraphContextBuilderService $service): int
    {
        try {
            // Safe default: a small mix where one source is authorized + traceable,
            // one is unauthorized, and one is untraceable — proving both gates.
            $result = $service->compile([
                'domain' => 'programming',
                'obra' => 'refactor-context-builder',
                'intent' => 'implement',
                'authorized_origins' => ['repo_docs', 'memory_core'],
                'candidates' => [
                    [
                        'id' => 'doc-canonical',
                        'origin' => 'repo_docs',
                        'title' => 'Context Builder canonical doc',
                        'reference' => 'docs/engineering-knowledge-base/system-graph/context-builder.md',
                        'relevance' => 0.95,
                    ],
                    [
                        'id' => 'leak-source',
                        'origin' => 'random_blog',
                        'title' => 'Unauthorized external note',
                        'reference' => 'https://example.test/post',
                        'relevance' => 0.80,
                    ],
                    [
                        'id' => 'ghost-source',
                        'origin' => 'memory_core',
                        'title' => 'Memory hit with no reference',
                        'reference' => '',
                        'relevance' => 0.70,
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
