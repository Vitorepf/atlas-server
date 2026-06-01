<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasRepairEscalationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Repair Escalation kernel decider CLI.
 *
 *   php artisan atlas:aaeos:repair-escalation
 *     [--failure-domain=gate.failed]
 *     [--severity=high]
 *     [--evidence=ledger:abc,harness:run123]   // comma-separated evidence refs
 *     [--attempt=1]
 *     [--max-attempts=3]
 *     [--no-repair]                             // policy.allow_repair=false
 *     [--repeated]                              // signature_repeated=true
 *     [--json]
 *
 * Read-only, deterministic. Emits the repair/escalate/block decision + receipt.
 *
 * @see docs/engineering-knowledge-base/system-graph/repair-escalation.md
 */
class AtlasRepairEscalationCommand extends Command
{
    protected $signature = 'atlas:aaeos:repair-escalation
        {--failure-domain= : closed FailureDomain value (gate.failed|runtime.failed|evidence.missing|security.finding|unknown|...)}
        {--severity= : info|low|warning|high|critical}
        {--evidence= : comma-separated evidence refs proving the failure}
        {--attempt= : 1-based repair attempt about to run}
        {--max-attempts= : repair-loop cap before escalation}
        {--no-repair : set policy.allow_repair=false}
        {--repeated : mark the failure signature as repeated}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas kernel · repair-escalation decider for one observed failure (repair|escalate|block).';

    public function handle(AtlasRepairEscalationService $service): int
    {
        try {
            $evidenceOpt = $this->option('evidence');
            $evidence = is_string($evidenceOpt) && trim($evidenceOpt) !== ''
                ? array_values(array_filter(array_map('trim', explode(',', $evidenceOpt)), static fn ($v) => $v !== ''))
                : [];

            $policy = ['allow_repair' => ! (bool) $this->option('no-repair')];
            $maxAttempts = $this->option('max-attempts');
            if (is_string($maxAttempts) && ctype_digit($maxAttempts)) {
                $policy['max_attempts'] = (int) $maxAttempts;
            }

            $attempt = $this->option('attempt');

            $decision = $service->decide([
                'failure_domain' => $this->option('failure-domain') ?? 'gate.failed',
                'severity' => $this->option('severity') ?? 'high',
                'evidence' => $evidence,
                'attempt' => is_string($attempt) && ctype_digit($attempt) ? (int) $attempt : 1,
                'policy' => $policy,
                'signature_repeated' => (bool) $this->option('repeated'),
            ]);

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'repair_escalation_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
