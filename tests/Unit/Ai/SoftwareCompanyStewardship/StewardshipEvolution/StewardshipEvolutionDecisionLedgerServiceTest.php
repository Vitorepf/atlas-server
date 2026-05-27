<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionDecisionLedgerService;
use Tests\TestCase;

class StewardshipEvolutionDecisionLedgerServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_seod_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->tmp.'/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function ledger(): StewardshipEvolutionDecisionLedgerService
    {
        $ledger = app(StewardshipEvolutionDecisionLedgerService::class);
        $ledger->setStorageRootForTesting($this->tmp);

        return $ledger;
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'area_id' => 'agentic_engineering_os',
            'portfolio_id' => 'atlas_software_company',
            'target_type' => 'autonomous_executive',
            'target_id' => 'exec_1234567890abcdef',
            'target_hash' => 'sha256:'.hash('sha256', 'executive-target'),
            'operator_actor' => 'vitor',
            'decision' => 'accept',
            'risk' => 'medium',
            'rationale' => 'approved for next governed slice',
        ], $overrides);
    }

    public function test_records_lists_and_replays_decision_receipts(): void
    {
        $ledger = $this->ledger();
        $record = $ledger->record($this->input());

        $this->assertSame('atlas.software_company_stewardship.evolution_operator_decision_receipt.v1', $record['schema_version']);
        $this->assertSame(StewardshipEvolutionDecisionLedgerService::LEDGER_SCHEMA, $record['ledger_schema_version']);
        $this->assertStringStartsWith('seod_', $record['decision_id']);
        $this->assertFalse($record['executed']);
        $this->assertFalse($record['claim_policy']['mutates_target_repo']);
        $this->assertFileExists($ledger->ledgerFilePath('agentic_engineering_os'));

        $list = $ledger->listDecisions('agentic_engineering_os');
        $this->assertSame(StewardshipEvolutionDecisionLedgerService::LEDGER_SCHEMA, $list['schema_version']);
        $this->assertSame(1, $list['decision_count']);
        $this->assertSame($record['decision_id'], $list['decisions'][0]['decision_id']);

        $replayed = $ledger->replay($record['decision_id']);
        $this->assertSame($record, $replayed);
    }

    public function test_record_is_idempotent_by_decision_id(): void
    {
        $ledger = $this->ledger();
        $a = $ledger->record($this->input());
        $b = $ledger->record($this->input());

        $this->assertSame($a['decision_id'], $b['decision_id']);
        $this->assertSame($a['decision_hash'], $b['decision_hash']);

        $lines = file($ledger->ledgerFilePath('agentic_engineering_os'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(1, $lines);
    }

    public function test_lists_all_areas_when_area_is_null(): void
    {
        $ledger = $this->ledger();
        $ledger->record($this->input(['area_id' => 'agentic_engineering_os']));
        $ledger->record($this->input([
            'area_id' => 'atlas_forge',
            'target_id' => 'exec_forge',
            'target_hash' => 'sha256:'.hash('sha256', 'forge'),
        ]));

        $list = $ledger->listDecisions(null);

        $this->assertSame(2, $list['decision_count']);
        $this->assertSame(0, $list['corrupted_line_count']);
    }

    public function test_corrupted_lines_are_counted_without_breaking_replay(): void
    {
        $ledger = $this->ledger();
        $record = $ledger->record($this->input());
        file_put_contents(
            $ledger->ledgerFilePath('agentic_engineering_os'),
            "not-json\n".json_encode(['missing' => 'decision_id']).PHP_EOL,
            FILE_APPEND,
        );

        $list = $ledger->listDecisions('agentic_engineering_os');

        $this->assertSame(1, $list['decision_count']);
        $this->assertSame(2, $list['corrupted_line_count']);
        $this->assertNotNull($ledger->replay($record['decision_id']));
    }

    public function test_extra_secret_like_input_is_not_persisted(): void
    {
        $ledger = $this->ledger();
        $record = $ledger->record($this->input([
            'api_secret' => 'super-secret-token',
            'auth_token' => 'token-never-persist',
            'rationale' => 'safe rationale',
        ]));

        $raw = (string) file_get_contents($ledger->ledgerFilePath('agentic_engineering_os'));

        $this->assertStringNotContainsString('super-secret-token', $raw);
        $this->assertStringNotContainsString('token-never-persist', $raw);
        $this->assertArrayNotHasKey('api_secret', $record);
        $this->assertArrayNotHasKey('auth_token', $record);
        $this->assertFalse($record['claim_policy']['secrets_in_payload']);
        $this->assertFalse($record['claim_policy']['touches_secrets']);
    }

    public function test_replay_unknown_decision_returns_null(): void
    {
        $this->assertNull($this->ledger()->replay('seod_missing'));
    }
}
