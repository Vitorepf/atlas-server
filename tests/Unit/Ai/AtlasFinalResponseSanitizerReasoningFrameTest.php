<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\Surface\AtlasFinalResponseSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * O guarda protege TODAS as superfícies. Aqui está o contrato dos dois lados:
 * o raciocínio nunca chega ao operador, e a resposta nunca é jogada fora junto.
 *
 * Incidente que motivou (15/07/2026): o Hermes escreve o pensamento numa
 * moldura ANTES do veredito. O guarda bloqueava o texto inteiro — as 12
 * revisões de commit do Atlas Código viraram "saída interna bloqueada", e 9
 * respostas do Hermes tinham sumido assim nas duas semanas anteriores.
 * Tratar a resposta como cúmplice do pensamento é jogar fora a entrega.
 */
final class AtlasFinalResponseSanitizerReasoningFrameTest extends TestCase
{
    private AtlasFinalResponseSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new AtlasFinalResponseSanitizer();
    }

    public function test_a_closed_frame_loses_the_thinking_and_keeps_the_verdict(): void
    {
        $raw = <<<'TXT'
        ┌─ Reasoning ──────────────────────────────────┐
         O usuário quer uma revisão curta. Vou olhar o diff
         e procurar problema real.
        └──────────────────────────────────────────────┘
        Sem problema. O corte do diff é dito em AtlasCodeReviewService.php.
        TXT;

        [$clean, $meta] = $this->sanitizer->sanitize($raw);

        self::assertSame('Sem problema. O corte do diff é dito em AtlasCodeReviewService.php.', $clean);
        self::assertSame('reasoning_frame_stripped', $meta['reason']);
        // O pensamento não vaza — é isso que o guarda existe para impedir.
        self::assertStringNotContainsString('Vou olhar o diff', $clean);
        self::assertStringNotContainsString('Reasoning', $clean);
    }

    public function test_an_open_frame_is_still_blocked_because_nobody_knows_where_thinking_ends(): void
    {
        // Sem fecho, não há como delimitar o pensamento. Chutar aqui é pior que
        // bloquear: fail-closed continua sendo a resposta certa.
        $raw = <<<'TXT'
        ┌─ Reasoning ──────────────────────────────────┐
         Preciso verificar os arquivos. Vou usar git log.
         Ainda pensando…
        TXT;

        [$clean, $meta] = $this->sanitizer->sanitize($raw);

        self::assertStringStartsWith('Não consegui preparar uma resposta segura', $clean);
        self::assertSame('reasoning_frame_blocked', $meta['reason']);
    }

    public function test_a_frame_with_nothing_after_it_is_blocked_not_answered_with_emptiness(): void
    {
        // O modelo só pensou e nunca respondeu: devolver vazio seria pior que
        // dizer que não deu.
        $raw = <<<'TXT'
        ┌─ Reasoning ──────────────────────────────────┐
         Pensei muito e não concluí nada.
        └──────────────────────────────────────────────┘
        TXT;

        [$clean, $meta] = $this->sanitizer->sanitize($raw);

        self::assertStringStartsWith('Não consegui preparar uma resposta segura', $clean);
        self::assertSame('reasoning_frame_blocked', $meta['reason']);
    }

    public function test_two_frames_around_the_answer_both_come_off(): void
    {
        // O incidente real trazia o MESMO bloco repetido (retry costurado).
        $raw = <<<'TXT'
        ┌─ Reasoning ─────────┐
         primeira volta
        └─────────────────────┘
        ┌─ Reasoning ─────────┐
         segunda volta
        └─────────────────────┘
        O veredito: sem problema.
        TXT;

        [$clean] = $this->sanitizer->sanitize($raw);

        self::assertSame('O veredito: sem problema.', $clean);
    }

    public function test_a_clean_answer_passes_through_untouched(): void
    {
        $raw = 'Sem problema. O diff só troca o piso de relevância.';

        [$clean, $meta] = $this->sanitizer->sanitize($raw);

        self::assertSame($raw, $clean);
        self::assertFalse($meta['changed']);
    }

    public function test_a_box_drawn_around_content_that_is_not_reasoning_is_not_touched(): void
    {
        // Nem toda caixa é pensamento: tabela desenhada em ASCII é resposta.
        $raw = "┌─ Resultado ─────────┐\n│ 12 commits          │\n└─────────────────────┘";

        [$clean, $meta] = $this->sanitizer->sanitize($raw);

        self::assertSame($raw, $clean);
        self::assertFalse($meta['changed']);
    }
}
