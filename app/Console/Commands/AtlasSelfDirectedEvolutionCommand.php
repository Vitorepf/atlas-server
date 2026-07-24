<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionCurationInboxService;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionGapReadModelService;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedSpecProposalAdapter;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasSelfDirectedEvolutionCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:self-directed-evolution
        {action=gap-read-model : gap-read-model|curation-inbox|spec-draft}
        {--hours=24 : AAEL control-plane window in hours}
        {--limit= : Cap the number of candidates}
        {--candidate= : candidate_hash or candidate_id (for spec-draft)}
        {--json : Emit JSON}';

    protected $description = 'Atlas Self-Directed Evolution · read-only gap read model, operator curation inbox and proposal-only spec drafts (no writes, no provider, no auto-approval).';

    public function handle(
        SelfDirectedEvolutionGapReadModelService $readModel,
        SelfDirectedEvolutionCurationInboxService $inbox,
        SelfDirectedSpecProposalAdapter $specAdapter,
    ): int {
        $action = (string) $this->argument('action');

        return match ($action) {
            'gap-read-model' => $this->runGapReadModel($readModel),
            'curation-inbox' => $this->runCurationInbox($inbox),
            'spec-draft' => $this->runSpecDraft($readModel, $specAdapter),
            default => $this->blockedResult('unknown_action', $action),
        };
    }

    private function runGapReadModel(SelfDirectedEvolutionGapReadModelService $readModel): int
    {
        $payload = $readModel->project($this->readModelInput());
        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('Self-Directed Evolution', 'gap read model (read-only)');
            $this->components->twoColumnDetail('Status', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Candidates', (string) ($p['candidate_count'] ?? 0));
            foreach ($p['candidates'] ?? [] as $candidate) {
                $this->line(sprintf(
                    '  [%s] %s · risk=%s · priority=%s',
                    (string) ($candidate['source_owner'] ?? '?'),
                    (string) ($candidate['title'] ?? ''),
                    (string) ($candidate['risk_level'] ?? '?'),
                    (string) ($candidate['priority_score'] ?? '?'),
                ));
            }
            foreach ($p['blockers'] ?? [] as $blocker) {
                $this->warn(sprintf(
                    '  blocker: %s · %s',
                    (string) ($blocker['source'] ?? '?'),
                    (string) ($blocker['reason'] ?? '?'),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runCurationInbox(SelfDirectedEvolutionCurationInboxService $inbox): int
    {
        $payload = $inbox->project($this->readModelInput());
        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('Self-Directed Evolution', 'operator curation inbox (read-only)');
            $this->components->twoColumnDetail('Status', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Pending items', (string) ($p['item_count'] ?? 0));
            foreach ($p['items'] ?? [] as $item) {
                $this->line(sprintf(
                    '  [%s] %s · risk=%s · %s · %s',
                    (string) ($item['source_owner'] ?? '?'),
                    (string) ($item['title'] ?? ''),
                    (string) ($item['risk_level'] ?? '?'),
                    (string) ($item['status'] ?? '?'),
                    (string) ($item['candidate_hash'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runSpecDraft(
        SelfDirectedEvolutionGapReadModelService $readModel,
        SelfDirectedSpecProposalAdapter $specAdapter,
    ): int {
        $needle = trim((string) $this->option('candidate'));
        if ($needle === '') {
            return $this->blockedResult('candidate_required', 'spec-draft');
        }

        $report = $readModel->project($this->readModelInput());
        $candidate = $this->findCandidate($report['candidates'] ?? [], $needle);
        if ($candidate === null) {
            return $this->blockedResult('candidate_not_found', $needle);
        }

        $draft = $specAdapter->draft($candidate);
        $this->emit($draft, function (array $d): void {
            $this->components->twoColumnDetail('Spec proposal draft', (string) ($d['proposed_doc_kind'] ?? '?'));
            $this->components->twoColumnDetail('Title', (string) ($d['title'] ?? ''));
            $this->components->twoColumnDetail('Proposed path (NOT written)', (string) ($d['proposed_doc_path'] ?? ''));
            $this->components->twoColumnDetail('Canonical write allowed', YesNo::format($d['canonical_doc_write_allowed']));
            $this->components->twoColumnDetail('Operator approval required', YesNo::format($d['operator_approval_required']));
        });

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function readModelInput(): array
    {
        $input = ['hours' => (int) $this->option('hours')];
        if (($limit = $this->option('limit')) !== null && $limit !== '') {
            $input['limit'] = (int) $limit;
        }

        return $input;
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array<string,mixed>|null
     */
    private function findCandidate(array $candidates, string $needle): ?array
    {
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $hash = (string) ($candidate['candidate_hash'] ?? '');
            $id = (string) ($candidate['candidate_id'] ?? '');
            if ($needle === $id || $needle === $hash || $hash === 'sha256:'.$needle || str_contains($hash, $needle)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, ?callable $human = null): void
    {
        if ((bool) $this->option('json') || $human === null) {
            $this->line($this->encode($payload));

            return;
        }
        $human($payload);
    }

    private function blockedResult(string $reason, string $detail): int
    {
        $payload = [
            'schema_version' => 'atlas.self_directed_evolution.command_error.v1',
            'status' => 'blocked',
            'reason' => $reason,
            'detail' => $detail,
        ];
        $this->line($this->encode($payload));

        return self::FAILURE;
    }
}
