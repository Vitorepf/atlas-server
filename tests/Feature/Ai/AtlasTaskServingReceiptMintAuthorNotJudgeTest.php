<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AtlasTaskCommitVerificationGate;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A rede que faltou em acb889c4a.
 *
 * O outcome do AEMOR só vira prova quando cita um `test_run_receipt:<uuid>`
 * resolvível. A tentação óbvia — e errada — é cunhar esse recibo do que o
 * músculo reportou sobre si mesmo (`payload.evidence.tests_run`): faria os 187
 * judgments passarem na hora, com o autor assinando o próprio laudo.
 *
 * Quem cunha é só o gate server-side, que rodou a suíte ele mesmo, e só quando
 * o attestation está válido, verde e não-vazio.
 */
final class AtlasTaskServingReceiptMintAuthorNotJudgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tabela única, montada inline: a suíte evita RefreshDatabase porque
        // várias migrations são SQL cru só-Postgres.
        if (! Schema::hasTable('atlas_aaeos_test_run_receipts')) {
            (require database_path('migrations/2026_06_02_090000_create_atlas_aaeos_test_run_receipts_table.php'))->up();
        }
    }

    private function mint(?array $verification): ?string
    {
        $method = new ReflectionMethod(AtlasTaskServingService::class, 'serverTestRunReceiptRef');

        return $method->invoke(app(AtlasTaskServingService::class), 'test-packet', $verification);
    }

    public function test_the_muscle_reporting_on_itself_never_mints_a_receipt(): void
    {
        // Exatamente a forma que a fusão do músculo produz em
        // AtlasTaskServingService (contadores + comandos, chaves fixas).
        $selfReported = [
            'passed' => true,
            'proof_strength' => 'task_tests_proven',
            'execution_evidence' => [
                'commands' => ['php artisan test'],
                'claimed_status' => 'passed',
                'tests_run' => 51,
                'assertions_executed' => 120,
                'selected_tests' => ['tests/Feature/FooTest.php'],
                'counts_parseable' => true,
            ],
        ];

        self::assertNull($this->mint($selfReported), 'contador auto-declarado não é recibo');
        self::assertNull($this->mint(null));
        self::assertNull($this->mint([]));
    }

    public function test_a_forged_attestation_still_has_to_be_green_and_non_vacuous(): void
    {
        $with = fn (array $attestation): ?string => $this->mint(['test_attestation' => $attestation]);
        $valid = [
            'status' => 'valid',
            'runner' => 'artisan_test',
            'suite' => ['tests/Feature/FooTest.php'],
            'n_tests' => 7,
            'n_assertions' => 21,
            'exit_code' => 0,
            'tree_hash' => 'sha256:deadbeef',
        ];

        self::assertNull($with(['status' => 'vacuous'] + $valid), 'vacuous nunca cunha');
        self::assertNull($with(['status' => 'invalid'] + $valid));
        self::assertNull($with(['exit_code' => 1] + $valid), 'suíte vermelha nunca cunha');
        self::assertNull($with(['n_tests' => 0] + $valid), 'zero teste executado nunca cunha');
    }

    public function test_only_the_server_side_gate_mints_and_the_ref_is_resolvable(): void
    {
        // Gate REAL, runner injetado: nenhum subprocesso, nenhuma suíte de verdade.
        $gate = new AtlasTaskCommitVerificationGate(null, static fn (array $cmd, string $cwd, float $t): array => [
            'ran' => true,
            'ok' => true,
            'out' => str_contains(implode(' ', $cmd), 'artisan test') ? "OK (7 tests, 21 assertions)\n" : '',
        ]);
        // caminho relativo ao repo: é assim que o allowed_files da task chega
        $verification = $gate->verify(['tests/Feature/Ai/AtlasTaskServingReceiptMintAuthorNotJudgeTest.php'], 'test-packet');

        self::assertSame('valid', $verification['test_attestation']['status'] ?? null);

        $ref = $this->mint($verification);
        self::assertIsString($ref);
        self::assertStringStartsWith('test_run_receipt:', $ref);
        // A forma que o resolvedor do AEMOR exige (Str::isUuid) — um contador
        // como `tests_run:7` jamais casaria.
        self::assertTrue(Str::isUuid(substr($ref, strlen('test_run_receipt:'))));
    }
}
