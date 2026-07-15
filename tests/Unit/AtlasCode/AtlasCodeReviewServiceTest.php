<?php

declare(strict_types=1);

namespace Tests\Unit\AtlasCode;

use App\Models\AiTrace;
use App\Services\AtlasCode\AtlasCodeReviewService;
use PHPUnit\Framework\TestCase;

/**
 * A 2ª natureza da pílula: revisar VÁRIOS commits ao mesmo tempo, um agente por
 * commit, com o estado vivo na linha do grafo.
 */
final class AtlasCodeReviewServiceTest extends TestCase
{
    private AtlasCodeReviewService $review;

    protected function setUp(): void
    {
        $this->review = new AtlasCodeReviewService();
    }

    public function test_the_same_commit_never_burns_two_agents(): void
    {
        // A chave é o contrato de idempotência: o gateway devolve o mesmo trace
        // para o mesmo client_id. Pedir a revisão de novo não gasta motor.
        $first = $this->review->clientId('atlas-native', 'D0A65D0AA1');
        $second = $this->review->clientId('atlas-native', 'd0a65d0aa1');

        self::assertSame($first, $second, 'caixa do hash não pode criar um segundo agente');
        // Repos diferentes com o mesmo hash são revisões diferentes.
        self::assertNotSame($first, $this->review->clientId('atlas-server', 'd0a65d0aa1'));
    }

    public function test_the_anchor_is_a_uuid_because_the_column_is_a_uuid(): void
    {
        // `ai_jobs.client_id` é UUID: a chave legível não cabe lá (o Postgres
        // recusa com `invalid input syntax for type uuid` — foi o erro real).
        // UUIDv5 preserva a idempotência sem migração: mesma semente, mesmo id.
        $id = $this->review->clientId('atlas-native', 'd0a65d0aa1');

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
            'o client_id precisa ser um UUIDv5 válido'
        );
        // E a semente continua legível — UUID não conta história em auditoria.
        self::assertSame('atlas-code:review:atlas-native:d0a65d0aa1', $this->review->reviewKey('atlas-native', 'D0A65D0AA1'));
    }

    public function test_a_commit_nobody_asked_to_review_is_idle_not_pending(): void
    {
        // "Pendente" seria uma promessa: alguém está para revisar. Ninguém está.
        self::assertSame('idle', $this->review->stateOf(null));
    }

    public function test_the_engine_status_speaks_the_screens_language(): void
    {
        foreach ([
            'queued' => 'queued',
            'processing' => 'running',
            'running' => 'running',
            'succeeded' => 'done',
            'completed' => 'done',
            'failed' => 'failed',
            'error' => 'failed',
            // Status que o motor ganhar depois desta tela cai no mais honesto
            // dos estados: enfileirado. Nunca em "pronto".
            'algum_status_novo' => 'queued',
        ] as $engine => $screen) {
            $trace = new AiTrace();
            $trace->status = $engine;
            self::assertSame($screen, $this->review->stateOf($trace), "status {$engine}");
        }
    }

    public function test_the_batch_is_bounded_and_refuses_what_is_not_a_hash(): void
    {
        $hashes = array_map(static fn (int $i): string => str_pad((string) $i, 40, 'a'), range(1, 20));
        $hashes[] = 'não-é-hash';
        $hashes[] = '--upload-pack=rm -rf /';
        $hashes[] = str_repeat('a', 40);
        $hashes[] = str_repeat('A', 40); // mesmo hash, outra caixa

        $bounded = $this->review->boundedHashes($hashes);

        self::assertCount(AtlasCodeReviewService::MAX_BATCH, $bounded);
        self::assertNotContains('não-é-hash', $bounded);
        self::assertNotContains('--upload-pack=rm -rf /', $bounded);
        // Acima do teto é varredura, não revisão — e o app diz quantos ficaram.
        self::assertSame(12, AtlasCodeReviewService::MAX_BATCH);
    }

    public function test_duplicate_hashes_never_become_duplicate_agents(): void
    {
        $hash = str_repeat('a', 40);

        self::assertSame([$hash], $this->review->boundedHashes([$hash, strtoupper($hash), $hash]));
    }

    public function test_the_reviewer_reads_facts_and_must_cite_a_file(): void
    {
        $prompt = $this->review->reviewPrompt([
            'commit_message' => 'feat(code): a folha do commit',
            'commit_body' => 'O corpo do commit vinha quebrado em 72 colunas.',
            'files' => [
                ['path' => 'App/Atlas/AtlasCodeView.swift', 'status' => 'modified', 'additions' => 295, 'deletions' => 55],
                ['path' => 'App/Assets/icon.png', 'status' => 'added', 'additions' => null, 'deletions' => null],
            ],
        ]);

        self::assertStringContainsString('feat(code): a folha do commit', $prompt);
        self::assertStringContainsString('O corpo do commit vinha quebrado', $prompt);
        self::assertStringContainsString('App/Atlas/AtlasCodeView.swift (modified, +295 -55)', $prompt);
        // Binário não vira "+0 -0" nem no prompt: o agente não pode ler um zero
        // que ninguém mediu.
        self::assertStringContainsString('App/Assets/icon.png (added, binário)', $prompt);
        // Revisor que não pode apontar onde, não revisou: opinou.
        self::assertStringContainsString('Sem citar arquivo, não afirme', $prompt);
        self::assertStringContainsString('Não elogie', $prompt);
    }

    public function test_a_commit_without_a_body_does_not_invent_a_description_line(): void
    {
        $prompt = $this->review->reviewPrompt([
            'commit_message' => 'fix: typo',
            'files' => [['path' => 'README.md', 'status' => 'modified', 'additions' => 1, 'deletions' => 1]],
        ]);

        self::assertStringNotContainsString('Descrição:', $prompt);
    }
}
