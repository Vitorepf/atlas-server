<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilAmbitionBudgetPolicy;
use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilDecisionLedger;
use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilRoadmapCandidateFilter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only CLI for the Self-Construction Strategy Council surface.
 *
 * Verbs:
 *   inspect  — services + non-execution guarantees.
 *   filter   — apply the roadmap candidate filter to --candidates JSON.
 *   rank     — emit a leverage-ordered listing of FILTERED candidates (deterministic, no scalar score).
 *   ambition — apply the ambition-budget policy to --facts JSON.
 *   history  — read ledger rows from --ledger.
 */
final class AtlasSelfConstructionStrategyCouncilCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:self-construction:strategy-council {action : inspect|filter|rank|ambition|history} {--candidates=} {--facts=} {--ledger=} {--json}';

    /** @var string */
    protected $description = 'Read-only Strategy Council surface: inspect / filter / rank / ambition / history.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'inspect' => $this->inspect(),
            'filter' => $this->filter(),
            'rank' => $this->rank(),
            'ambition' => $this->ambition(),
            'history' => $this->history(),
            default => ['status' => 'unknown_action', 'action' => $action],
        };
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return ($payload['status'] ?? 'ok') === 'ok' || ! isset($payload['status']) ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string,mixed> */
    private function inspect(): array
    {
        return [
            'status' => 'ok',
            'verbs' => ['inspect', 'filter', 'rank', 'ambition', 'history'],
            'services' => [
                AtlasStrategyCouncilRoadmapCandidateFilter::SCHEMA,
                AtlasStrategyCouncilAmbitionBudgetPolicy::SCHEMA,
                AtlasStrategyCouncilDecisionLedger::SCHEMA,
            ],
            'non_execution_guarantees' => [
                'writes_storage_when_no_ledger' => false,
                'invokes_shell_or_git' => false,
                'calls_external_providers' => false,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function filter(): array
    {
        $candidates = $this->readJson('candidates');
        if (! is_array($candidates)) {
            return ['status' => 'usage_error', 'reason' => '--candidates JSON file required'];
        }
        $list = is_array($candidates['candidates'] ?? null) ? $candidates['candidates'] : [];
        $scopes = is_array($candidates['admitted_owner_scopes'] ?? null) ? array_map('strval', $candidates['admitted_owner_scopes']) : [];
        $r = $this->app()->make(AtlasStrategyCouncilRoadmapCandidateFilter::class)->filter($list, $scopes);

        return ['status' => 'ok', 'filter' => $r];
    }

    /** @return array<string,mixed> */
    private function rank(): array
    {
        $candidates = $this->readJson('candidates');
        if (! is_array($candidates)) {
            return ['status' => 'usage_error', 'reason' => '--candidates JSON file required'];
        }
        $list = is_array($candidates['candidates'] ?? null) ? $candidates['candidates'] : [];
        $scopes = is_array($candidates['admitted_owner_scopes'] ?? null) ? array_map('strval', $candidates['admitted_owner_scopes']) : [];
        $filtered = $this->app()->make(AtlasStrategyCouncilRoadmapCandidateFilter::class)->filter($list, $scopes);

        // Deterministic leverage-ordering: leverage_rank ∈ {high,medium,low} mapped to band; tie-break by candidate_id.
        $bands = ['high' => 1, 'medium' => 2, 'low' => 3];
        $kept = $filtered['kept'];
        usort($kept, static function (array $a, array $b) use ($bands): int {
            $ba = $bands[(string) ($a['leverage_rank'] ?? '')] ?? 9;
            $bb = $bands[(string) ($b['leverage_rank'] ?? '')] ?? 9;

            return $ba <=> $bb ?: strcmp((string) ($a['candidate_id'] ?? ''), (string) ($b['candidate_id'] ?? ''));
        });

        return ['status' => 'ok', 'ranking' => $kept, 'dropped' => $filtered['dropped']];
    }

    /** @return array<string,mixed> */
    private function ambition(): array
    {
        $facts = $this->readJson('facts');
        if (! is_array($facts)) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON file required'];
        }
        $r = $this->app()->make(AtlasStrategyCouncilAmbitionBudgetPolicy::class)->decide($facts);

        return ['status' => 'ok', 'ambition' => $r];
    }

    /** @return array<string,mixed> */
    private function history(): array
    {
        $path = (string) ($this->option('ledger') ?? '');
        if ($path === '') {
            return ['status' => 'usage_error', 'reason' => '--ledger path required for history'];
        }
        try {
            return ['status' => 'ok', 'rows' => (new AtlasStrategyCouncilDecisionLedger($path))->all()];
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $optionName): ?array
    {
        $path = (string) ($this->option($optionName) ?? '');
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @return \Illuminate\Contracts\Container\Container */
    private function app()
    {
        return $this->getLaravel();
    }
}
