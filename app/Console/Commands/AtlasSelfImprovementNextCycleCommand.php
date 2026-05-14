<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementNextCycleRecommendationService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementResultLedgerService;
use Illuminate\Console\Command;

/**
 * Atlas Self-Improvement Next Cycle Recommendation CLI (Level 7).
 *
 *   php artisan atlas:self-improvement:next-cycle --proposal=<id> --json --strict
 *   php artisan atlas:self-improvement:next-cycle --latest --json --strict
 *
 * Emits the canonical recommendation payload. NEVER creates a proposal
 * automatically — `proposed_next_proposal_payload` is a draft the operator
 * must explicitly submit via the backlog endpoint or CLI.
 */
final class AtlasSelfImprovementNextCycleCommand extends Command
{
    protected $signature = 'atlas:self-improvement:next-cycle
        {--proposal= : Proposal id (prop_<ULID>)}
        {--latest : Use the most recent result entry overall (overrides --proposal when both passed)}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero when no result entry is available (recommendation=null)}';

    protected $description = 'Atlas Self-Improvement Next Cycle Recommendation v1 (Level 7).';

    public function handle(
        AtlasSelfImprovementNextCycleRecommendationService $nextCycle,
        AtlasSelfImprovementProposalBacklogService $proposalBacklog,
        AtlasSelfImprovementResultLedgerService $resultLedger,
    ): int {
        $strict = (bool) $this->option('strict');
        $latest = (bool) $this->option('latest');
        $proposalId = $this->stringOption('proposal');

        $entry = null;
        $proposal = null;

        if ($latest) {
            $snap = $resultLedger->snapshot([]);
            $entry = $snap['entries'][0] ?? null;
            if (is_array($entry) && isset($entry['proposal_id'])) {
                $proposal = $proposalBacklog->getProposal((string) $entry['proposal_id']);
            }
        } elseif ($proposalId !== null) {
            $entries = $resultLedger->listForProposal($proposalId);
            $entry = $entries[0] ?? null;
            $proposal = $proposalBacklog->getProposal($proposalId);
        }

        $payload = $nextCycle->recommend($entry, $proposal);
        $this->emit($payload);

        if (! $strict) {
            return self::SUCCESS;
        }

        return ($payload['recommendation'] ?? null) === null ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return;
        }
        $this->components->twoColumnDetail('schema_version', (string) ($payload['schema_version'] ?? '—'));
        $this->components->twoColumnDetail('recommendation', (string) ($payload['recommendation'] ?? '—'));
        $this->components->twoColumnDetail('rationale', (string) ($payload['rationale'] ?? '—'));
        $this->components->twoColumnDetail('confidence', (string) ($payload['confidence'] ?? '—'));
        $this->components->twoColumnDetail('human_approval_required', ($payload['human_approval_required'] ?? false) ? 'true' : 'false');
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
