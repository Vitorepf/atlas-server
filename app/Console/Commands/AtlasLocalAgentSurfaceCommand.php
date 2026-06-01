<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasLocalAgentSurfaceService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Local Agent Surface — background-job claim gate CLI.
 *
 *   php artisan atlas:aaeos:local-agent-surface
 *     [--job-class=stewardship_loop]
 *     [--agent-online] [--caffeinate-reconciled] [--awake]
 *     [--receipt] [--policy] [--evidence]
 *     [--watchdogs=heartbeat,push]
 *     [--elevate]                 // hard-stop: locality elevation
 *     [--suppress-readiness]      // hard-stop: hide readiness failure
 *     [--json]
 *
 * Read-only, deterministic. Decides whether the local Mac Agent may claim a
 * background job, enforcing the doc "Fronteira" boundary. It NEVER executes the
 * job or mutates power state. With no flags the defaults model an ungoverned,
 * not-ready agent => decision=block (safe default).
 *
 * @see docs/engineering-knowledge-base/atlas-local-agent-surface.md
 */
class AtlasLocalAgentSurfaceCommand extends Command
{
    protected $signature = 'atlas:aaeos:local-agent-surface
        {--job-class= : free label for the background job}
        {--agent-online : local heartbeat is fresh and agent awake}
        {--caffeinate-reconciled : caffeinate retention available, no orphans}
        {--awake : Mac held awake for the session window}
        {--receipt : Kernel decision receipt is signed}
        {--policy : Kernel policy permits this job}
        {--evidence : evidence sink is wired}
        {--watchdogs= : comma-separated watchdog kinds (e.g. heartbeat,push)}
        {--elevate : ask to elevate permission because local (hard stop)}
        {--suppress-readiness : ask to hide readiness failure (hard stop)}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas local agent surface · background-job claim gate (allow|block) enforcing the Fronteira boundary.';

    public function handle(AtlasLocalAgentSurfaceService $service): int
    {
        try {
            $jobClass = $this->option('job-class');

            $request = [
                'job_class' => is_string($jobClass) && trim($jobClass) !== '' ? trim($jobClass) : 'unspecified',
                'readiness' => [
                    'agent_online' => (bool) $this->option('agent-online'),
                    'caffeinate_reconciled' => (bool) $this->option('caffeinate-reconciled'),
                    'awake_for_session' => (bool) $this->option('awake'),
                ],
                'watchdogs' => $this->list('watchdogs'),
                'receipt_signed' => (bool) $this->option('receipt'),
                'policy_allowed' => (bool) $this->option('policy'),
                'evidence_present' => (bool) $this->option('evidence'),
                'elevate_request' => (bool) $this->option('elevate'),
                'suppress_readiness_failure' => (bool) $this->option('suppress-readiness'),
            ];

            $result = $service->decideClaim($request);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['decision'] === AtlasLocalAgentSurfaceService::DECISION_ALLOW
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'local_agent_surface_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    /**
     * @return list<string>
     */
    private function list(string $option): array
    {
        $raw = $this->option($option);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn ($v) => $v !== '',
        ));
    }
}
