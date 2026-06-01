<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasMultiAgentResearchRolesService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Multi-Agent Research Roles CLI.
 *
 *   php artisan atlas:aaeos:multi-agent-research-roles [--run=/tmp/run.json] [--json]
 *
 * Pure, deterministic gate over the documented research-role contract: the
 * Artifact Rule (every active role persists >= 1 artifact), the four
 * Anti-Duplication invariants, and the critical-report Promotion quorum. Reads
 * an optional run descriptor (critical, active_roles, artifacts_by_role, trace)
 * from JSON and emits the verdict (promote | hold) with the allowed status
 * ceiling. With no --run it evaluates a safe, compliant demo run. NEVER mutates
 * anything.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/multi-agent-research-roles.md
 */
final class AtlasMultiAgentResearchRolesCommand extends Command
{
    protected $signature = 'atlas:aaeos:multi-agent-research-roles
        {--run= : path to a JSON file with the research run descriptor}
        {--json : machine-readable JSON output}';

    protected $description = 'Atlas AAEOS · assess a multi-agent research run against the role, artifact, anti-duplication and promotion contract (promote|hold).';

    public function handle(AtlasMultiAgentResearchRolesService $service): int
    {
        try {
            $run = $service->demoRun();

            $runOpt = $this->option('run');
            if (is_string($runOpt) && trim($runOpt) !== '') {
                $path = trim($runOpt);
                if (! is_file($path)) {
                    return $this->failEnvelope("run file not found: {$path}");
                }
                $raw = (string) file_get_contents($path);
                $decoded = json_decode($raw, true);
                if (! is_array($decoded)) {
                    return $this->failEnvelope("run file is not a JSON object: {$path}");
                }
                $run = $decoded;
            }

            $result = $service->assess($run);

            if ((bool) $this->option('json') || ! is_string($runOpt)) {
                $this->line((string) json_encode(
                    ['ok' => true, 'result' => $result],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                ));

                return self::SUCCESS;
            }

            $this->info('Atlas Multi-Agent Research Roles');
            $this->line('  decision: '.((string) $result['decision']));
            $this->line('  allowed_status: '.((string) $result['allowed_status']));
            if ($result['blockers'] !== []) {
                $this->line('  blockers: '.implode(', ', $result['blockers']));
            }
            $promotion = $result['promotion'];
            if (($promotion['missing_quorum_roles'] ?? []) !== []) {
                $this->line('  missing_quorum: '.implode(', ', $promotion['missing_quorum_roles']));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failEnvelope($e->getMessage());
        }
    }

    private function failEnvelope(string $message): int
    {
        $this->line((string) json_encode(
            ['ok' => false, 'error' => $message],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));

        return self::FAILURE;
    }
}
