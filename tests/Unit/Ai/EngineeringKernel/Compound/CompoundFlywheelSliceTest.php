<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel\Compound;

use App\Services\Ai\AtlasDecide\AtlasConductorRoutingMemory;
use App\Services\Ai\EngineeringKernel\Compound\OutcomeDigestReader;
use App\Services\Ai\EngineeringKernel\Compound\OutcomeFlywheel;
use App\Services\Ai\EngineeringKernel\Repair\FailureBrainCorpus;
use App\Services\Ai\EngineeringKernel\Repair\FailureTaxonomy;
use App\Services\Ai\EngineeringKernel\Repair\RepairDiagnosisStage;
use Tests\TestCase;

/**
 * OBRA #4 S4 — COMPOUND→Brain (a camada Optimization). Prova os ACs congelados:
 *   AC-4.1 admission.resolved.jsonl tem LEITOR (0 callers => ≥1, com teste de integração)
 *   AC-4.2 o run N+1 recupera o que o run N ensinou (routing recommend + repair corpus_prior)
 *   AC-4.3 entrada sem provenance é IGNORADA (imunidade — lição do echo)
 *   AC-4.4 clamp: um registro ruim nunca rebaixa o prior abaixo do default
 */
final class CompoundFlywheelSliceTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function tmp(string $suffix): string
    {
        $f = sys_get_temp_dir().'/atlas-compound-'.bin2hex(random_bytes(4)).$suffix;
        $this->files[] = $f;

        return $f;
    }

    private function ledgerFixture(): string
    {
        $path = $this->tmp('.jsonl');
        $lines = [
            // 2 entradas com provenance completa (schema+agent+commit)
            json_encode(['schema_version' => 'atlas.self_construction.resolved_receipt.v1', 'resolved_at' => '2026-07-03T15:43:26Z', 'task_packet_id' => 'firechain-live1-s1-extract', 'commit_sha' => 'abc123', 'agent_id' => 'fire-worker', 'objective_excerpt' => 'extrair costura']),
            json_encode(['schema_version' => 'atlas.self_construction.resolved_receipt.v1', 'resolved_at' => '2026-07-03T16:00:00Z', 'task_packet_id' => 'firechain-live1-s2-wire', 'commit_sha' => 'def456', 'agent_id' => 'fire-worker', 'objective_excerpt' => 'ligar costura']),
            // 1 SEM provenance (agent_id vazio) => ignorada
            json_encode(['schema_version' => 'atlas.self_construction.resolved_receipt.v1', 'task_packet_id' => 'ghost-task', 'commit_sha' => 'zzz', 'agent_id' => '']),
        ];
        file_put_contents($path, implode("\n", $lines)."\n");

        return $path;
    }

    public function test_ac41_ac43_reader_consumes_ledger_and_ignores_entries_without_provenance(): void
    {
        $reader = new OutcomeDigestReader($this->ledgerFixture());
        $digest = $reader->digest();

        $this->assertSame(2, $digest['admitted'], 'o ledger finalmente tem um LEITOR');
        $this->assertSame(1, $digest['ignored_no_provenance'], 'imunidade: sem origem, não existe para o flywheel');
        $this->assertSame(['fire-worker' => 2], $digest['per_agent']);

        $seeds = $reader->exemplarSeeds();
        $this->assertCount(2, $seeds, 'sementes de exemplar expostas para o indexador do Dev');
        $this->assertSame('abc123', $seeds[0]['commit_sha']);
    }

    public function test_ac42_routing_flywheel_run_n_teaches_run_n_plus_1(): void
    {
        $reader = new OutcomeDigestReader($this->ledgerFixture());
        $memory = new AtlasConductorRoutingMemory;
        $memory->setLogPathForTesting($this->tmp('.routing.jsonl'));

        // ANTES do feed: nenhuma recomendação (nada aprendido).
        $this->assertNull($memory->recommend('firechain-live1', 'task_worker'));

        $result = (new OutcomeFlywheel($reader, $this->tmp('.cursor')))->feedRoutingMemory($memory);
        $this->assertSame(2, $result['fed']);

        // DEPOIS: o run N+1 recupera o que o run N ensinou — recomenda quem ENTREGOU.
        $rec = $memory->recommend('firechain-live1', 'task_worker');
        $this->assertNotNull($rec, 'flywheel fechado: outcome virou rota aprendida');
        $this->assertSame('fire-worker', (string) ($rec['provider'] ?? ''));

        // Idempotência: re-alimentar não duplica evidência.
        $again = (new OutcomeFlywheel($reader, $this->files[count($this->files) - 1]))->feedRoutingMemory($memory);
        $this->assertSame(0, $again['fed'], 'cursor impede re-alimentação');
    }

    public function test_ac42b_repair_corpus_prior_reuses_proven_class_on_same_signature(): void
    {
        $corpusPath = $this->tmp('.corpus.jsonl');
        $corpus = new FailureBrainCorpus($corpusPath);
        $corpus->record([
            'failure_signature' => 'sig-learned',
            'origin' => 'repo_verified_delivery',
            'class' => FailureTaxonomy::ENV_FLAKE,
            'strategy' => FailureTaxonomy::STRATEGY_RERUN_NO_PROVIDER,
            'outcome' => 'repaired',
        ]);

        $d = (new RepairDiagnosisStage(null, $corpus))->diagnose([
            'failure_output' => 'saida sem nenhum padrao reconhecivel',
            'failure_signature' => 'sig-learned',
        ]);

        $this->assertSame(FailureTaxonomy::ENV_FLAKE, $d['class'], 'run N ensinou; run N+1 reusou');
        $this->assertSame('corpus_prior', $d['decided_by']);
    }

    public function test_ac44_clamp_bad_or_unsafe_records_never_lower_the_default(): void
    {
        $corpusPath = $this->tmp('.corpus.jsonl');
        $corpus = new FailureBrainCorpus($corpusPath);
        // Registro NÃO-reparado (halted) => nunca cria prior.
        $corpus->record([
            'failure_signature' => 'sig-halted',
            'class' => FailureTaxonomy::DEPENDENCY_BROKEN,
            'strategy' => FailureTaxonomy::STRATEGY_ABORT_BLOCKER,
            'outcome' => 'halted:dependency_broken',
        ]);
        // Registro ENVENENADO com classe insegura => nunca adotado mesmo com outcome repaired.
        $corpus->record([
            'failure_signature' => 'sig-poison',
            'class' => FailureTaxonomy::TEST_WRONG,
            'strategy' => FailureTaxonomy::STRATEGY_ESCALATE_NEVER_AUTOFIX,
            'outcome' => 'repaired',
        ]);

        $stage = new RepairDiagnosisStage(null, $corpus);

        $halted = $stage->diagnose(['failure_output' => 'sem padrao', 'failure_signature' => 'sig-halted']);
        $this->assertSame(FailureTaxonomy::UNKNOWN, $halted['class'], 'outcome não-reparado não vira prior');

        $poison = $stage->diagnose(['failure_output' => 'sem padrao', 'failure_signature' => 'sig-poison']);
        $this->assertSame(FailureTaxonomy::UNKNOWN, $poison['class'], 'um registro envenenado nunca destrava classe insegura');
        $this->assertSame(FailureTaxonomy::STRATEGY_REGENERATE_WITH_HINT, $poison['strategy'], 'prior ruim não rebaixa o default');
    }
}
