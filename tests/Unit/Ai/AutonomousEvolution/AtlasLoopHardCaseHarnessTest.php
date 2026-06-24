<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Models\AtlasLoopDecompositionOutcome;
use App\Services\Ai\AutonomousEvolution\AtlasLoopAttemptLedger;
use App\Services\Ai\AutonomousEvolution\AtlasLoopBenchmarkHarness;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHardCaseHarness;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasLoopHardCaseHarnessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('atlas_loop_decomposition_outcomes')) {
            (require base_path('database/migrations/2026_06_16_000100_create_atlas_loop_decomposition_outcomes_table.php'))->up();
        }

        AtlasLoopDecompositionOutcome::query()->delete();
    }

    public function test_flag_off_returns_empty_deck_and_benchmark_consumes_zero_hard_cases(): void
    {
        config()->set('atlas.loop.hard_case_harness_enabled', false);
        $ledger = $this->ledgerWithHardFamily('refactor_extract_class');

        $this->assertSame([], (new AtlasLoopHardCaseHarness)->curate([$this->packet('refactor_extract_class')], $ledger));
        $this->assertSame([], (new AtlasLoopBenchmarkHarness)->hardCaseDeck([$this->packet('refactor_extract_class')], $ledger));
    }

    public function test_curate_returns_deterministic_ordered_hard_cases_from_attempt_ledger_fixture(): void
    {
        config()->set('atlas.loop.hard_case_harness_enabled', true);
        $ledger = $this->ledgerWithHardFamily('refactor_extract_class');
        $packets = [
            $this->packet('easy_family'),
            $this->packet('refactor_extract_class'),
        ];

        $first = (new AtlasLoopHardCaseHarness)->curate($packets, $ledger);
        $second = (new AtlasLoopHardCaseHarness)->curate($packets, $ledger);

        $this->assertSame($first, $second);
        $this->assertCount(1, $first);
        $this->assertSame('refactor_extract_class', $first[0]['family']);
        $this->assertSame(0.75, $first[0]['failure_rate']);
        $this->assertSame(4, $first[0]['attempts']);
    }

    public function test_curated_hard_case_preserves_allowed_files_and_acceptance_verbatim(): void
    {
        config()->set('atlas.loop.hard_case_harness_enabled', true);
        $packet = $this->packet('refactor_extract_class', [
            'allowed_files' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopHardCaseHarness.php',
                'tests/Unit/Ai/AutonomousEvolution/AtlasLoopHardCaseHarnessTest.php',
            ],
            'acceptance' => [
                'commands' => ['vendor/bin/phpunit tests/Unit/Ai/AutonomousEvolution/AtlasLoopHardCaseHarnessTest.php'],
                'metric_kind' => 'gate',
                'nested' => ['preserve' => ['this', 'exact', 'order']],
            ],
        ]);

        $case = (new AtlasLoopHardCaseHarness)
            ->curate([$packet], $this->ledgerWithHardFamily('refactor_extract_class'))[0];

        $this->assertSame($packet['allowed_files'], $case['allowed_files']);
        $this->assertSame($this->jsonBytes($packet['acceptance']), $this->jsonBytes($case['acceptance']));
        $this->assertSame($this->jsonBytes($packet['acceptance']), $this->jsonBytes($case['task_packet']['acceptance']));
    }

    public function test_decomposition_outcome_recorder_can_supply_hard_family_stats(): void
    {
        config()->set('atlas.loop.hard_case_harness_enabled', true);
        config()->set('atlas.loop.decomposition_corpus_enabled', true);
        $this->seedOutcomeFamily('db_hard_family', certified: 1, total: 5);

        $cases = (new AtlasLoopHardCaseHarness)->curate([$this->packet('db_hard_family')]);

        $this->assertCount(1, $cases);
        $this->assertSame('decomposition_outcome_recorder', $cases[0]['source']);
        $this->assertSame(0.8, $cases[0]['failure_rate']);
    }

    private function ledgerWithHardFamily(string $family): AtlasLoopAttemptLedger
    {
        $ledger = new AtlasLoopAttemptLedger;
        $ledger->record('a', 'codex', false, 'failed_gate', 'family:'.$family);
        $ledger->record('b', 'codex', false, 'failed_gate', 'family:'.$family);
        $ledger->record('c', 'claude', false, 'failed_gate', 'family:'.$family);
        $ledger->record('d', 'claude', true, 'passed', 'family:'.$family);
        $ledger->record('easy', 'codex', true, 'passed', 'family:easy_family');
        $ledger->record('easy2', 'codex', true, 'passed', 'family:easy_family');
        $ledger->record('easy3', 'codex', false, 'one_failure', 'family:easy_family');

        return $ledger;
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function packet(string $family, array $overrides = []): array
    {
        return $overrides + [
            'task_packet_id' => 'packet-'.$family,
            'objective_kind' => $family,
            'objective' => 'Replay hard task family '.$family,
            'allowed_files' => ['app/Example.php', 'tests/ExampleTest.php'],
            'acceptance' => [
                'commands' => ['vendor/bin/phpunit tests/ExampleTest.php'],
                'metric_kind' => 'gate',
            ],
            'acceptance_criteria' => ['preserve this too'],
        ];
    }

    private function seedOutcomeFamily(string $family, int $certified, int $total): void
    {
        for ($i = 0; $i < $total; $i++) {
            $isCertified = $i < $certified;
            AtlasLoopDecompositionOutcome::query()->create([
                'fingerprint_hash' => 'shape-'.$family,
                'objective_kind' => $family,
                'node_count' => 1,
                'certified' => $isCertified,
                'thrashed' => ! $isCertified,
                'terminal_reason' => $isCertified ? 'certified' : 'not_certified',
                'rounds' => 1,
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $value
     */
    private function jsonBytes(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
