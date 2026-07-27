<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathCatalog;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityGapTaskChainCompiler;
use App\Services\Ai\Signal\AtlasSignalInvariantGapAdapter;
use Tests\TestCase;

/**
 * A fusão de patamar: o que a máquina mediu sobre si vira task ordenada.
 *
 * O compilador de cadeias já existia e ordenava por dependência; o que faltava
 * era alguém entregar as lacunas a ele. 286 tabelas vazias são um backlog que
 * nenhum humano vai transformar em 286 prompts.
 */
final class AtlasSignalInvariantGapAdapterTest extends TestCase
{
    private function compile(array $findings): array
    {
        $adapted = app(AtlasSignalInvariantGapAdapter::class)->toGaps($findings);

        return app(AtlasExternalBrainCapabilityGapTaskChainCompiler::class)->compile($adapted) + ['skipped' => $adapted['skipped']];
    }

    public function test_the_eighth_path_is_registered_and_points_at_the_real_compiler(): void
    {
        $catalog = app(AtlasBrainPathCatalog::class);

        self::assertCount(8, $catalog->all());
        self::assertSame('capability', $catalog->find('capability-gap')['objective_kind'] ?? null);
        self::assertSame(
            AtlasExternalBrainCapabilityGapTaskChainCompiler::class,
            $catalog->executorOrganFor('capability-gap'),
        );
    }

    public function test_n_findings_become_n_chains_with_coherent_dependencies(): void
    {
        $findings = [
            ['class' => 'table_without_owner', 'subject' => 'tabela_a'],
            ['class' => 'table_without_owner', 'subject' => 'tabela_b'],
            ['class' => 'producer_without_clock', 'subject' => 'atlas:algum-produtor'],
        ];

        $out = $this->compile($findings);

        self::assertCount(3, $out['gap_chains'], 'uma cadeia por achado, nunca fundidas');

        // Cada dependency_id tem de apontar para um nó que existe NESTA cadeia —
        // o compilador marca contradictory_dependency quando não aponta.
        $ids = array_column($out['chain'], 'task_id');
        foreach ($out['chain'] as $node) {
            foreach ($node['dependency_ids'] as $dep) {
                self::assertContains($dep, $ids, "dependência pendurada em {$node['task_id']}");
            }
            self::assertNotSame('contradictory_dependency', $node['not_ready_reason']);
        }

        // A ordem do compilador: provar o contexto antes de ligar o runtime.
        $wire = $this->nodeById($out['chain'], 'table_without_owner:tabela_a:no_runtime_integration');
        self::assertSame(['table_without_owner:tabela_a:missing_context'], $wire['dependency_ids']);
    }

    public function test_two_findings_of_the_same_class_never_collapse_into_one_node(): void
    {
        // O colapso do compilador é para unblocker COMPARTILHADO de verdade.
        // Duas tabelas vazias são dois trabalhos: fundi-las apagaria uma delas
        // do backlog sem que ninguém percebesse.
        $out = $this->compile([
            ['class' => 'table_without_owner', 'subject' => 'tabela_a'],
            ['class' => 'table_without_owner', 'subject' => 'tabela_b'],
        ]);

        self::assertCount(4, $out['chain'], '2 achados × 2 bloqueadores');
        self::assertSame(array_unique(array_column($out['chain'], 'task_id')), array_column($out['chain'], 'task_id'));
    }

    public function test_a_declared_shared_unblocker_collapses_the_work_without_eating_the_chain(): void
    {
        // Dois achados que declaram o MESMO destravador viram um nó por etapa —
        // mas as etapas continuam sendo etapas. Sem o sufixo de tipo, os dois
        // bloqueadores da mesma lacuna receberiam o mesmo id e a cadeia inteira
        // desabaria num nó só.
        $out = $this->compile([
            ['class' => 'table_without_owner', 'subject' => 'tabela_a', 'unblocker_id' => 'dono-do-modulo-x'],
            ['class' => 'table_without_owner', 'subject' => 'tabela_b', 'unblocker_id' => 'dono-do-modulo-x'],
        ]);

        self::assertCount(2, $out['chain'], 'um nó por etapa, compartilhado pelas duas lacunas');
        self::assertCount(2, $this->nodeById($out['chain'], 'dono-do-modulo-x:missing_context')['gap_ids']);
        self::assertSame(
            ['dono-do-modulo-x:missing_context'],
            $this->nodeById($out['chain'], 'dono-do-modulo-x:no_runtime_integration')['dependency_ids'],
        );
    }

    public function test_an_unknown_finding_class_is_skipped_not_guessed(): void
    {
        // Cadeia inventada é backlog fabricado — pior que backlog faltando.
        $out = $this->compile([
            ['class' => 'classe_que_nao_existe', 'subject' => 'x'],
            ['class' => 'table_without_owner', 'subject' => ''],
            ['class' => 'table_without_owner', 'subject' => 'tabela_a'],
        ]);

        self::assertCount(1, $out['gap_chains']);
        self::assertCount(2, $out['skipped']);
        self::assertSame(['unsupported_class', 'missing_subject'], array_column($out['skipped'], 'reason'));
    }

    public function test_a_finding_without_a_concrete_target_is_never_muscle_ready(): void
    {
        // Uma tabela vazia não diz quem deveria enchê-la. Deixar isso passar como
        // pronto entregaria ao músculo uma task sem alvo.
        $out = $this->compile([['class' => 'table_without_owner', 'subject' => 'tabela_a']]);

        foreach ($out['chain'] as $node) {
            self::assertTrue($node['muscle_ready_spec_contract']['not_muscle_ready']);
        }
        self::assertSame(0.0, $out['chain_value_score']);

        $comAlvo = $this->compile([[
            'class' => 'table_without_owner',
            'subject' => 'tabela_a',
            'allowed_files_hint' => ['app/Services/Ai/Signal/AtlasTableCensusService.php'],
        ]]);
        foreach ($comAlvo['chain'] as $node) {
            self::assertFalse($node['muscle_ready_spec_contract']['not_muscle_ready']);
        }
    }

    private function nodeById(array $chain, string $taskId): array
    {
        foreach ($chain as $node) {
            if ($node['task_id'] === $taskId) {
                return $node;
            }
        }

        self::fail("nó ausente: {$taskId}");
    }
}
