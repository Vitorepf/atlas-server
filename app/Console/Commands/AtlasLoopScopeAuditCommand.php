<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Autopoiesis\ScopeOrigination\AtlasLoopScopeOriginationAuditor;
use App\Services\Ai\AutonomousEvolution\Autopoiesis\ScopeOrigination\ScopeProposal;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopScopeOriginationAuditor::audit()} at the operator surface: builds a
 * {@see ScopeProposal} from --proposal JSON and emits the scope-origination audit verdict (rejected on a scope-
 * guard violation; pending when auto-approve is off / sources missing / coherence below floor; auto_approved
 * when the grounded floor is met).
 *
 * Read-only: it audits only — it never approves or origins a scope.
 */
final class AtlasLoopScopeAuditCommand extends Command
{
    protected $signature = 'atlas:loop:scope-audit {--proposal=} {--json}';

    protected $description = 'Read-only scope-origination audit verdict for a scope proposal.';

    public function handle(): int
    {
        $raw = trim((string) $this->option('proposal'));
        if ($raw === '') {
            return $this->refuse('scope-audit requires --proposal=<json object or path>');
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $this->refuse('--proposal must be a JSON object');
        }

        $payload = [
            'expected_leverage_signal' => (string) ($decoded['expected_leverage_signal'] ?? ''),
            'fact_refs' => is_array($decoded['fact_refs'] ?? null) ? array_values($decoded['fact_refs']) : [],
            'objective_text' => (string) ($decoded['objective_text'] ?? ''),
            'target_paths' => is_array($decoded['target_paths'] ?? null) ? array_values($decoded['target_paths']) : [],
        ];

        $verdict = app(AtlasLoopScopeOriginationAuditor::class)->audit(new ScopeProposal($payload));

        $facts = ['schema' => 'atlas.loop.scope_audit.v1'] + $verdict->toArray();

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('verdict: '.$facts['verdict'].'  actor: '.$facts['actor'].'  reason: '.($facts['reason'] ?? '-'));
        }

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->line((string) json_encode([
            'outcome' => 'refused',
            'reason' => 'usage_error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
