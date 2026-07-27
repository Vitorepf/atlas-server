<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AtlasAaeosTestRunReceipt;
use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use App\Services\Ai\SelfConstruction\AtlasTaskCommitVerificationGate;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A marca `synthetic` era convenção sem guarda: no ciclo anterior a linha
 * fabricada foi etiquetada À MÃO, e nada impedia o próximo harness de deixar
 * outra sem etiqueta. Uma linha fabricada e não declarada no cartório de
 * evidência é a própria falha que este plano existe para matar.
 *
 * A origem é estrutural, nunca de conteúdo (a lição de 0f52d5b32): um SHA
 * fabricado se disfarça, um runner injetado não. Só o gate sabe que os comandos
 * não rodaram, então é ele que carimba, e o fato viaja no attestation.
 *
 * A escolha entre recusar e marcar: marcar na cunhagem, recusar no cartório. Um
 * harness precisa exercitar o caminho inteiro de produção — recusar a escrita
 * tornaria este próprio teste impossível — mas o que ele escreve nunca conta
 * como prova.
 */
final class AtlasSyntheticEvidenceGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('atlas_aaeos_test_run_receipts')) {
            (require database_path('migrations/2026_06_02_090000_create_atlas_aaeos_test_run_receipts_table.php'))->up();
        }
    }

    private function mint(?array $verification): ?string
    {
        return (new ReflectionMethod(AtlasTaskServingService::class, 'serverTestRunReceiptRef'))
            ->invoke(app(AtlasTaskServingService::class), 'guard-packet', $verification);
    }

    private function receiptFor(string $ref): AtlasAaeosTestRunReceipt
    {
        return AtlasAaeosTestRunReceipt::query()->findOrFail(substr($ref, strlen('test_run_receipt:')));
    }

    /** O gate com runner injetado — exatamente a forma de todo harness de prova. */
    private function harnessGate(): AtlasTaskCommitVerificationGate
    {
        return new AtlasTaskCommitVerificationGate(null, static fn (array $cmd, string $cwd, float $t): array => [
            'ran' => true,
            'ok' => true,
            'out' => str_contains(implode(' ', $cmd), 'artisan test') ? "OK (7 tests, 21 assertions)\n" : '',
        ]);
    }

    public function test_a_harness_origin_write_declares_itself_without_being_asked(): void
    {
        $verification = $this->harnessGate()->verify(
            ['tests/Feature/Ai/AtlasSyntheticEvidenceGuardTest.php'],
            'guard-packet',
        );

        self::assertTrue(
            $verification['test_attestation']['runner_injected'] ?? false,
            'o gate é o único que sabe que os comandos não rodaram — tem de dizer'
        );

        $receipt = $this->receiptFor((string) $this->mint($verification));

        self::assertTrue((bool) data_get($receipt->metadata, 'synthetic'), 'declarada sozinha, sem etiqueta à mão');
        self::assertNotEmpty(data_get($receipt->metadata, 'synthetic_reason'));
        self::assertFalse(
            (bool) data_get($receipt->metadata, 'attribution_reviewed'),
            'ninguém atribuiu nada: a saída foi ditada'
        );
    }

    public function test_a_synthetic_receipt_never_verifies_an_outcome(): void
    {
        $ref = (string) $this->mint($this->harnessGate()->verify(
            ['tests/Feature/Ai/AtlasSyntheticEvidenceGuardTest.php'],
            'guard-packet',
        ));

        $resolved = (new ReflectionMethod(AtlasAemorRuntimeService::class, 'resolveVerifiedMetricsFromEvidence'))
            ->invoke(app(AtlasAemorRuntimeService::class), [$ref]);

        self::assertFalse($resolved['verified'], 'linha fabricada não vira prova, por mais bem formada que esteja');
        self::assertSame([], $resolved['resolved_refs']);
    }

    public function test_a_real_producer_write_still_counts_as_evidence(): void
    {
        // O outro lado da guarda: o produtor de verdade não pode ser pego junto.
        // Mesma forma de attestation, sem a marca de origem-harness.
        $ref = (string) $this->mint(['test_attestation' => [
            'status' => 'valid',
            'runner' => 'artisan_test',
            'suite' => ['tests/Feature/FooTest.php'],
            'n_tests' => 7,
            'n_assertions' => 21,
            'exit_code' => 0,
            'tree_hash' => 'sha256:deadbeef',
            'runner_injected' => false,
        ]]);

        $receipt = $this->receiptFor($ref);
        self::assertArrayNotHasKey('synthetic', (array) $receipt->metadata);
        self::assertTrue((bool) data_get($receipt->metadata, 'attribution_reviewed'));

        $resolved = (new ReflectionMethod(AtlasAemorRuntimeService::class, 'resolveVerifiedMetricsFromEvidence'))
            ->invoke(app(AtlasAemorRuntimeService::class), [$ref]);

        self::assertTrue($resolved['verified']);
        self::assertSame([$ref], $resolved['resolved_refs']);
    }
}
