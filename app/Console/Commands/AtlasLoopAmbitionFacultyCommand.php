<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAmbitionLeapProposer;
use App\Services\Ai\AutonomousEvolution\AtlasLoopBacklogAutoFeederService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopFrontierGapModel;
use App\Services\Ai\AutonomousEvolution\AtlasLoopLeapDecompositionSeeder;
use App\Services\Ai\AutonomousEvolution\AtlasLoopLeapReceiptLedger;
use App\Services\Ai\AutonomousEvolution\AtlasLoopLeapRiskAuditor;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Console\Command;
use Throwable;

class AtlasLoopAmbitionFacultyCommand extends Command
{
    protected $signature = 'atlas:loop:ambition-faculty
        {--dry-run : Plan only; never write receipts or backlog packets}
        {--write : Persist surviving leap receipts and forward seeded packets}
        {--json : Canonical JSON output}';

    protected $description = 'Plan and optionally persist ambition-leap task packets from grounded Loop frontier gaps.';

    public function handle(): int
    {
        $dryRun = ! (bool) $this->option('write') || (bool) $this->option('dry-run');

        if (! AtlasLoopMasterSwitch::enabled()) {
            $this->emit([
                'schema_version' => 'atlas.loop.ambition_faculty.v1',
                'status' => 'master_disabled',
                'dry_run' => true,
                'gaps' => [],
                'leaps' => [],
                'verdicts' => [],
                'seeded_packets' => [],
                'writes' => [
                    'ledger_records' => 0,
                    'backlog_forwards' => 0,
                    'backlog_forwarded' => false,
                    'backlog_forward_reason' => 'master_disabled',
                ],
            ]);

            return self::SUCCESS;
        }

        $gaps = $this->frontierGapModel()->compute($this->runtimeFacts());
        $leaps = $this->ambitionLeapProposer()->propose($gaps);
        $verdicts = $this->leapRiskAuditor()->audit($leaps, $this->liveEvidenceRefs($leaps));
        $verdictsByLeapId = $this->indexByLeapId($verdicts);

        $seededPackets = [];
        $survivors = [];
        foreach ($leaps as $leap) {
            $leapId = trim((string) ($leap['leap_id'] ?? ''));
            $verdict = $verdictsByLeapId[$leapId] ?? [
                'record_type' => 'RiskVerdict',
                'leap_id' => $leapId !== '' ? $leapId : 'unknown',
                'status' => 'reject',
                'reasons' => ['missing_risk_verdict'],
            ];
            if (($verdict['status'] ?? null) !== 'pass') {
                continue;
            }

            $seed = $this->leapDecompositionSeeder()->seed($leap, $verdict);
            $packets = array_values(array_filter((array) ($seed['task_packets'] ?? []), 'is_array'));
            if ($packets === []) {
                continue;
            }

            array_push($seededPackets, ...$packets);
            $survivors[] = [
                'leap' => $leap,
                'verdict' => $verdict,
                'packets' => $packets,
            ];
        }

        $writes = [
            'ledger_records' => 0,
            'backlog_forwards' => 0,
            'backlog_forwarded' => false,
            'backlog_forward_reason' => $dryRun ? 'dry_run' : null,
        ];

        if (! $dryRun) {
            $writes['ledger_records'] = $this->recordReceipts($survivors);
            $forward = $this->forwardSeededPackets($seededPackets);
            $writes['backlog_forwards'] = $forward['forwarded'] ? 1 : 0;
            $writes['backlog_forwarded'] = $forward['forwarded'];
            $writes['backlog_forward_reason'] = $forward['reason'];
        }

        $this->emit([
            'schema_version' => 'atlas.loop.ambition_faculty.v1',
            'status' => 'ok',
            'dry_run' => $dryRun,
            'gaps' => $gaps,
            'leaps' => $leaps,
            'verdicts' => $verdicts,
            'seeded_packets' => $seededPackets,
            'writes' => $writes,
        ]);

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeFacts(): array
    {
        $facts = config('atlas.loop.ambition_faculty.runtime_facts', []);

        return is_array($facts) ? $facts : [];
    }

    /**
     * @param  list<array<string,mixed>>  $leaps
     * @return list<string>
     */
    private function liveEvidenceRefs(array $leaps): array
    {
        $refs = [];
        foreach ($leaps as $leap) {
            foreach ((array) ($leap['grounded_evidence_refs'] ?? []) as $ref) {
                $ref = trim((string) $ref);
                if ($ref !== '') {
                    $refs[] = $ref;
                }
            }
        }

        $refs = array_values(array_unique($refs));
        sort($refs, SORT_STRING);

        return $refs;
    }

    /**
     * @param  list<array<string,mixed>>  $verdicts
     * @return array<string,array<string,mixed>>
     */
    private function indexByLeapId(array $verdicts): array
    {
        $indexed = [];
        foreach ($verdicts as $verdict) {
            $leapId = trim((string) ($verdict['leap_id'] ?? ''));
            if ($leapId !== '') {
                $indexed[$leapId] = $verdict;
            }
        }

        return $indexed;
    }

    /**
     * @param  list<array{leap:array<string,mixed>,verdict:array<string,mixed>,packets:list<array<string,mixed>>}>  $survivors
     */
    private function recordReceipts(array $survivors): int
    {
        $records = 0;
        $ledger = $this->leapReceiptLedger();
        foreach ($survivors as $survivor) {
            $result = $ledger->record([
                'leap_id' => $survivor['leap']['leap_id'] ?? null,
                'gap_id' => $survivor['leap']['gap_id'] ?? null,
                'ambition_leap' => $survivor['leap'],
                'risk_verdict' => $survivor['verdict'],
                'task_packets' => $survivor['packets'],
            ]);
            if (($result['recorded'] ?? false) === true) {
                $records++;
            }
        }

        return $records;
    }

    /**
     * @param  list<array<string,mixed>>  $seededPackets
     * @return array{forwarded:bool,reason:?string}
     */
    private function forwardSeededPackets(array $seededPackets): array
    {
        if ($seededPackets === []) {
            return ['forwarded' => false, 'reason' => 'no_seeded_packets'];
        }

        $feeder = $this->backlogAutoFeeder();
        if (! method_exists($feeder, 'acceptSeededPackets')) {
            return ['forwarded' => false, 'reason' => 'missing_accept_seeded_packets'];
        }

        $feeder->acceptSeededPackets($seededPackets);

        return ['forwarded' => true, 'reason' => null];
    }

    /** @param array<string,mixed> $payload */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line((string) ($payload['status'] ?? 'ok'));
    }

    private function frontierGapModel()
    {
        return app(AtlasLoopFrontierGapModel::class);
    }

    private function ambitionLeapProposer()
    {
        return app(AtlasLoopAmbitionLeapProposer::class);
    }

    private function leapRiskAuditor()
    {
        return app(AtlasLoopLeapRiskAuditor::class);
    }

    private function leapDecompositionSeeder()
    {
        return app(AtlasLoopLeapDecompositionSeeder::class);
    }

    private function leapReceiptLedger()
    {
        return app(AtlasLoopLeapReceiptLedger::class);
    }

    private function backlogAutoFeeder()
    {
        try {
            return app(AtlasLoopBacklogAutoFeederService::class);
        } catch (Throwable) {
            return new class
            {
            };
        }
    }
}
