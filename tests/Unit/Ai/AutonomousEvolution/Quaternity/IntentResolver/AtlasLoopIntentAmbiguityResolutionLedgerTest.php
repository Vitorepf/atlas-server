<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Quaternity\IntentResolver;

use App\Services\Ai\AutonomousEvolution\Quaternity\IntentResolver\AtlasLoopIntentAmbiguityResolutionLedger;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentResolver\IntentAmbiguityResolutionRejectedException;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AtlasLoopIntentAmbiguityResolutionLedgerTest extends TestCase
{
    private string $ledgerPath = '';

    private array $openQuestions = [];

    private array $candidatesByQuestion = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-intent-resolution-'.bin2hex(random_bytes(6)).'.jsonl';
        Config::set('atlas.loop.intent_resolver.ledger_path', $this->ledgerPath);
        $this->openQuestions = ['qh-1' => true, 'qh-2' => true];
        $this->candidatesByQuestion = [
            'qh-1' => ['alpha', 'beta'],
            'qh-2' => ['gamma', 'delta'],
        ];
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function ledger(): AtlasLoopIntentAmbiguityResolutionLedger
    {
        $path = (string) config('atlas.loop.intent_resolver.ledger_path');

        return new AtlasLoopIntentAmbiguityResolutionLedger(
            $path,
            fn (string $qh): bool => isset($this->openQuestions[$qh]),
            fn (string $qh): array => $this->candidatesByQuestion[$qh] ?? [],
        );
    }

    public function test_append_only_chain_verifies_clean_chain_true(): void
    {
        $ledger = $this->ledger();

        $ledger->append('intent-1', 'finding-1', 'qh-1', 'alpha', '2026-06-25T00:00:00Z', 'sig1', '');
        $tail = $ledger->tailHash();
        $ledger->append('intent-1', 'finding-2', 'qh-2', 'gamma', '2026-06-25T00:00:01Z', 'sig2', $tail);

        self::assertTrue($ledger->chainVerify());
    }

    public function test_chain_verify_returns_false_when_a_prior_row_is_tampered(): void
    {
        $ledger = $this->ledger();
        $ledger->append('intent-1', 'finding-1', 'qh-1', 'alpha', '2026-06-25T00:00:00Z', 'sig', '');
        $tail = $ledger->tailHash();
        $ledger->append('intent-1', 'finding-2', 'qh-2', 'gamma', '2026-06-25T00:00:01Z', 'sig2', $tail);

        // Tamper with the first row: rewrite the file with chosen_candidate_string changed.
        $lines = file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $first = json_decode($lines[0], true);
        $first['chosen_candidate_string'] = 'beta'; // not what was committed
        $lines[0] = json_encode($first);
        file_put_contents($this->ledgerPath, implode("\n", $lines)."\n");

        self::assertFalse($ledger->chainVerify());
    }

    public function test_append_rejects_unknown_question_hash(): void
    {
        $ledger = $this->ledger();
        $this->expectException(IntentAmbiguityResolutionRejectedException::class);
        $this->expectExceptionMessage('question_hash_not_in_open_set');
        $ledger->append('intent-1', 'finding-1', 'qh-unknown', 'alpha', '2026-06-25T00:00:00Z', 'sig', '');
    }

    public function test_append_rejects_candidate_not_in_proposed_set(): void
    {
        $ledger = $this->ledger();
        $this->expectException(IntentAmbiguityResolutionRejectedException::class);
        $this->expectExceptionMessage('candidate_not_in_proposed_set');
        $ledger->append('intent-1', 'finding-1', 'qh-1', 'not-in-list', '2026-06-25T00:00:00Z', 'sig', '');
    }

    public function test_append_rejects_prior_row_hash_mismatch(): void
    {
        $ledger = $this->ledger();
        $ledger->append('intent-1', 'finding-1', 'qh-1', 'alpha', '2026-06-25T00:00:00Z', 'sig', '');

        $this->expectException(IntentAmbiguityResolutionRejectedException::class);
        $this->expectExceptionMessage('prior_row_hash_mismatch');
        $ledger->append('intent-1', 'finding-2', 'qh-2', 'gamma', '2026-06-25T00:00:01Z', 'sig2', 'wrong-prior');
    }

    public function test_snapshot_for_intent_returns_rows_in_insertion_order_byte_identically(): void
    {
        $ledger = $this->ledger();
        $ledger->append('intent-1', 'f1', 'qh-1', 'alpha', '2026-06-25T00:00:00Z', 'sig', '');
        $ledger->append('intent-1', 'f2', 'qh-2', 'gamma', '2026-06-25T00:00:01Z', 'sig', $ledger->tailHash());
        $ledger->append('intent-2', 'f3', 'qh-1', 'beta', '2026-06-25T00:00:02Z', 'sig', $ledger->tailHash());

        $a = $ledger->snapshotForIntent('intent-1');
        $b = $ledger->snapshotForIntent('intent-1');
        self::assertSame(json_encode($a), json_encode($b));
        self::assertCount(2, $a);
        self::assertSame('f1', $a[0]['ambiguity_finding_id']);
        self::assertSame('f2', $a[1]['ambiguity_finding_id']);
    }

    public function test_dont_know_resolution_is_recorded_as_null_candidate(): void
    {
        $ledger = $this->ledger();
        $row = $ledger->append('intent-1', 'f1', 'qh-1', null, '2026-06-25T00:00:00Z', 'sig', '');

        self::assertNull($row['chosen_candidate_string']);
    }

    public function test_ledger_path_comes_from_config_not_hardcoded(): void
    {
        $customPath = sys_get_temp_dir().'/atlas-intent-resolution-custom-'.bin2hex(random_bytes(4)).'.jsonl';
        Config::set('atlas.loop.intent_resolver.ledger_path', $customPath);
        $ledger = $this->ledger();
        $ledger->append('intent-1', 'f1', 'qh-1', 'alpha', '2026-06-25T00:00:00Z', 'sig', '');

        self::assertFileExists($customPath);
        @unlink($customPath);
    }
}
