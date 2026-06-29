<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasDocClaimAnalyzer;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasDocClaimAnalyzer::analyzeFile()} at the operator surface: audits one doc and emits
 * the deterministic doc-claim-versus-reality facts (phantom classes / commands the doc claims but the codebase
 * does not register) so the operator can SEE doc drift. The real registered-command list is fed in so phantom
 * detection reflects commands registered via $name/provider, not just a static grep. Read-only.
 */
final class AtlasLoopDocClaimAuditCommand extends Command
{
    protected $signature = 'atlas:loop:doc-claim-audit {--file=} {--json}';

    protected $description = 'Read-only doc-claim audit: phantom classes/commands a doc claims that the codebase does not provide.';

    public function handle(AtlasDocClaimAnalyzer $analyzer): int
    {
        $file = trim((string) $this->option('file'));
        if ($file === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'doc-claim-audit requires --file=<path>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $application = $this->getApplication();
        if ($application !== null) {
            $analyzer->useRegisteredCommands(array_keys($application->all()));
        }

        $this->line((string) json_encode($analyzer->analyzeFile($file), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
