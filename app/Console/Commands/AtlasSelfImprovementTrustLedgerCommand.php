<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Models\AtlasProject;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class AtlasSelfImprovementTrustLedgerCommand extends Command
{
    use ReadsNonEmptyStringOption;

    protected $signature = 'atlas:self-improvement:trust-ledger
        {--obra= : UUID Obra (optional; without it the snapshot is global empty)}
        {--record : Record an entry instead of just reading the ledger}
        {--outcome= : When recording: proposal_approved | proposal_rejected | proposal_revised | autopromotion_accepted | autopromotion_reverted | overreach_flagged | over_conservative_flagged}
        {--proposal-id=}
        {--reviewer=}
        {--reason=}
        {--area=}
        {--json}
        {--strict : Exit non-zero on missing obra/record requirements when --record is set}';

    protected $description = 'Atlas Self-Improvement Human Trust Ledger (read + record). Read-model.';

    public function handle(AtlasSelfImprovementHumanTrustLedgerService $service): int
    {
        $obraId = $this->stringOption('obra');
        $project = $this->resolveProject($obraId);

        if ((bool) $this->option('record')) {
            return $this->handleRecord($service, $project);
        }

        $snapshot = $service->snapshot($project);
        $this->emit($snapshot);

        return self::SUCCESS;
    }

    private function handleRecord(
        AtlasSelfImprovementHumanTrustLedgerService $service,
        ?AtlasProject $project,
    ): int {
        $strict = (bool) $this->option('strict');

        if ($project === null) {
            $this->emit([
                'schema_version' => AtlasSelfImprovementHumanTrustLedgerService::ENTRY_SCHEMA_VERSION,
                'status' => 'blocked',
                'blocker' => 'obra_required',
            ]);

            return $strict ? self::FAILURE : self::SUCCESS;
        }

        $outcome = $this->stringOption('outcome');
        if ($outcome === null
            || ! in_array($outcome, AtlasSelfImprovementHumanTrustLedgerService::KNOWN_OUTCOMES, true)
        ) {
            $this->emit([
                'schema_version' => AtlasSelfImprovementHumanTrustLedgerService::ENTRY_SCHEMA_VERSION,
                'status' => 'blocked',
                'blocker' => 'unknown_outcome',
                'outcome_provided' => $outcome,
                'known_outcomes' => AtlasSelfImprovementHumanTrustLedgerService::KNOWN_OUTCOMES,
            ]);

            return $strict ? self::FAILURE : self::SUCCESS;
        }

        try {
            $entry = $service->record($project, [
                'outcome' => $outcome,
                'proposal_id' => $this->stringOption('proposal-id'),
                'reviewer' => $this->stringOption('reviewer'),
                'reason' => $this->stringOption('reason'),
                'area' => $this->stringOption('area'),
            ]);
        } catch (InvalidArgumentException $e) {
            $this->emit([
                'schema_version' => AtlasSelfImprovementHumanTrustLedgerService::ENTRY_SCHEMA_VERSION,
                'status' => 'blocked',
                'blocker' => $e->getMessage(),
            ]);

            return $strict ? self::FAILURE : self::SUCCESS;
        }

        $this->emit([
            'schema_version' => AtlasSelfImprovementHumanTrustLedgerService::ENTRY_SCHEMA_VERSION,
            'status' => 'recorded',
            'entry' => $entry,
            'snapshot' => $service->snapshot($project->refresh()),
        ]);

        return self::SUCCESS;
    }

    private function resolveProject(?string $obraId): ?AtlasProject
    {
        if ($obraId === null || ! Str::isUuid($obraId)) {
            return null;
        }
        try {
            return AtlasProject::query()->whereKey($obraId)->first();
        } catch (Throwable) {
            return null;
        }
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
        $this->components->twoColumnDetail('status', (string) ($payload['status'] ?? ($payload['summary']['trust_band'] ?? '—')));
    }
}
