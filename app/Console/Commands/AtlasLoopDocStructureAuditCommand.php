<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasDocStructureAnalyzer;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasDocStructureAnalyzer::analyzeFile()} at the operator surface: audits one markdown
 * doc and emits its deterministic structure facts (schema_version, is_module, violations) as JSON. Read-only.
 */
final class AtlasLoopDocStructureAuditCommand extends Command
{
    protected $signature = 'atlas:loop:doc-structure-audit {--file=} {--json}';

    protected $description = 'Read-only markdown doc-structure audit (module-doc structure facts + violations).';

    public function handle(AtlasDocStructureAnalyzer $analyzer): int
    {
        $file = trim((string) $this->option('file'));
        if ($file === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'doc-structure-audit requires --file=<path>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->line((string) json_encode($analyzer->analyzeFile($file), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
