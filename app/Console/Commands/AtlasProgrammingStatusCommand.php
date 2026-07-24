<?php

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Programming\Governance\ProgrammingGovernanceService;
use Illuminate\Console\Command;
use Throwable;
use App\Support\YesNo;

/**
 * Read-only timeline view of a programming work item: snapshot + gate runs +
 * reviews + evidence references.
 */
class AtlasProgrammingStatusCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:programming:status
        {work_item : Work item code or UUID}
        {--json : Print machine-readable JSON}';

    protected $description = 'Print the full timeline of a programming work item.';

    public function handle(ProgrammingGovernanceService $governance): int
    {
        try {
            $workItem = $governance->find((string) $this->argument('work_item'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $snapshot = $governance->snapshot($workItem);
        $payload = array_merge($snapshot, [
            'gate_runs' => $this->mapGateRuns($workItem),
            'reviews' => $this->mapReviews($workItem),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encodeOrEmptyObject($payload));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Programming Status</>', $payload['code']);
        $this->components->twoColumnDetail('Intent', $payload['intent_text']);
        $this->components->twoColumnDetail('Type / Mode / Risk', sprintf('%s / %s / %s', $payload['intent_type'], $payload['scope_mode'], $payload['risk_level']));
        $this->components->twoColumnDetail('Status / Stage', sprintf('%s / %s', $payload['status'], $payload['current_stage']));
        $this->components->twoColumnDetail('Closed at', $payload['closed_at'] ?? '-');

        $this->newLine();
        $this->table(
            ['gate', 'status', 'blocking', 'reason', 'created'],
            array_map(static fn (array $r): array => [
                $r['gate_name'], $r['status'], YesNo::format($r['blocking']),
                (string) ($r['reason'] ?? ''), $r['created_at'],
            ], $payload['gate_runs']),
        );

        if ($payload['reviews'] !== []) {
            $this->newLine();
            $this->table(
                ['result', 'summary', 'decided_by', 'created'],
                array_map(static fn (array $r): array => [
                    $r['result'], (string) ($r['summary'] ?? ''),
                    (string) ($r['decided_by'] ?? ''), $r['created_at'],
                ], $payload['reviews']),
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function mapGateRuns(AtlasProgrammingWorkItem $workItem): array
    {
        return $workItem->gateRuns()
            ->orderBy('created_at')
            ->get()
            ->map(static fn ($run): array => [
                'id' => $run->id,
                'gate_name' => $run->gate_name,
                'status' => $run->status,
                'blocking' => (bool) $run->blocking,
                'reason' => $run->reason,
                'waiver_reason' => $run->waiver_reason,
                'payload' => $run->payload_json,
                'created_at' => $run->created_at?->toJSON(),
            ])
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function mapReviews(AtlasProgrammingWorkItem $workItem): array
    {
        return $workItem->reviews()
            ->orderBy('created_at')
            ->get()
            ->map(static fn ($review): array => [
                'id' => $review->id,
                'result' => $review->result,
                'summary' => $review->summary,
                'risk_notes' => $review->risk_notes,
                'decided_by' => $review->decided_by,
                'created_at' => $review->created_at?->toJSON(),
            ])
            ->all();
    }

}
