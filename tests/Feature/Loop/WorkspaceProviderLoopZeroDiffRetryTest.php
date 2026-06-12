<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\WorkspaceProviderLoopExecutionDriver;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * L2-1 (Lista 2 — regressão ACP): "sucesso" do provider com ZERO mudanças no workspace é
 * sucesso FALSO para uma invocação mutadora (a assinatura exata da regressão acp: 12
 * cenários, diff 0, sucesso reportado). O driver re-tenta UMA vez e carimba
 * zero_diff_retry para a anomalia ser auditável. Congelado aqui.
 */
final class WorkspaceProviderLoopZeroDiffRetryTest extends TestCase
{
    private const TABLES = [
        'atlas_engineering_doc_links',
        'atlas_engineering_code_symbols',
        'atlas_engineering_code_modules',
        'atlas_engineering_code_file_snapshots',
    ];

    private const PROVIDER = 'codex_cli';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        (require database_path('migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table.php'))->up();
        (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();
        config()->set('atlas.loop.default_provider', self::PROVIDER);
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    /**
     * @param  list<array<string,mixed>>  $responses  devolvidas em sequência por invoke()
     */
    private function bindSequencedRouter(array $responses): object
    {
        $box = new class
        {
            public int $invocations = 0;
        };
        $fake = new class($box, $responses) extends AtlasForgeProviderInvocationDriverRouter
        {
            /** @param list<array<string,mixed>> $responses */
            public function __construct(private readonly object $box, private readonly array $responses)
            {
            }

            public function isConfigured(?string $provider): bool
            {
                return true;
            }

            public function invoke(?string $provider, ?string $model, array $prompt, array $context = []): array
            {
                $i = min($this->box->invocations, count($this->responses) - 1);
                $this->box->invocations++;

                return $this->responses[$i];
            }
        };
        $this->app->instance(AtlasForgeProviderInvocationDriverRouter::class, $fake);

        return $box;
    }

    public function test_zero_diff_success_retries_once_and_recovers_the_scenario(): void
    {
        config(['atlas.loop.zero_diff_retry' => true]);
        $box = $this->bindSequencedRouter([
            ['provider_called' => true, 'changed_files' => [], 'exit_code' => 0],
            ['provider_called' => true, 'changed_files' => ['src/Subject.php'], 'exit_code' => 0],
        ]);

        $result = app(WorkspaceProviderLoopExecutionDriver::class)
            ->attempt('loop', sys_get_temp_dir(), 'fix it', [], []);

        $this->assertSame(2, $box->invocations, 'sucesso falso (diff 0) deve re-tentar exatamente uma vez');
        $this->assertSame(['src/Subject.php'], $result['changed_files'], 'a re-tentativa recupera o cenário');
        $this->assertTrue($result['zero_diff_retry'], 'a anomalia fica carimbada para auditoria');
    }

    public function test_normal_success_with_edits_never_retries(): void
    {
        config(['atlas.loop.zero_diff_retry' => true]);
        $box = $this->bindSequencedRouter([
            ['provider_called' => true, 'changed_files' => ['src/Subject.php'], 'exit_code' => 0],
        ]);

        $result = app(WorkspaceProviderLoopExecutionDriver::class)
            ->attempt('loop', sys_get_temp_dir(), 'fix it', [], []);

        $this->assertSame(1, $box->invocations);
        $this->assertFalse($result['zero_diff_retry']);
    }

    public function test_retry_can_be_disabled_by_config(): void
    {
        config(['atlas.loop.zero_diff_retry' => false]);
        $box = $this->bindSequencedRouter([
            ['provider_called' => true, 'changed_files' => [], 'exit_code' => 0],
        ]);

        $result = app(WorkspaceProviderLoopExecutionDriver::class)
            ->attempt('loop', sys_get_temp_dir(), 'fix it', [], []);

        $this->assertSame(1, $box->invocations);
        $this->assertFalse($result['zero_diff_retry']);
        $this->assertSame([], $result['changed_files']);
    }
}
