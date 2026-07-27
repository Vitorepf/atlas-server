<?php

namespace App\Services\Ai\Surface;

use App\Support\InternalLeakMarkers;
use Illuminate\Support\Str;

class AtlasFinalResponseSanitizer
{
    /**
     * A nota que substitui uma saída bloqueada. Constante, não literal solto,
     * porque quem CONSOME uma resposta (o read-model da revisão) precisa
     * reconhecê-la: no chat "reenvie" é resposta legítima, mas como VEREDITO de
     * commit é falha vestida de fato — e só quem sabe o texto exato pode
     * distinguir os dois sem depender de metadata que nem sempre chega.
     */
    public const BLOCKED_NOTICE = 'Não consegui preparar uma resposta segura para exibição. A saída interna foi bloqueada; reenvie o pedido para gerar uma resposta limpa.';

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    public function sanitize(string $text): array
    {
        $original = trim($text);
        if ($original === '') {
            return ['', ['changed' => false, 'reason' => null]];
        }

        $extracted = $this->extractProviderTranscriptResult($original);
        if ($extracted !== null) {
            return [$extracted, [
                'changed' => $extracted !== $original,
                'reason' => 'provider_transcript_result_extracted',
            ]];
        }

        if ($this->hasBoxedReasoningFrame($original)) {
            // Moldura FECHADA (┌─ Reasoning … └───┘) é raciocínio delimitado: dá
            // para arrancar e ficar com a resposta. Bloquear tudo aqui jogava
            // fora o veredito junto com o pensamento — e o modelo que pensa em
            // voz alta ANTES de responder perdia a resposta inteira. Foi o que
            // matou as 12 revisões de commit do Atlas Código (15/07) e as 9
            // respostas do Hermes nas duas semanas anteriores.
            //
            // É a mesma disciplina que `stripLeakedModelMarkup` já aplica
            // logo abaixo: strip do par fechado, bloqueio só do não fechado.
            $withoutFrame = $this->stripBoxedReasoningFrames($original);
            if ($withoutFrame !== null && trim($withoutFrame) !== '') {
                return [trim($withoutFrame), [
                    'changed' => true,
                    'reason' => 'reasoning_frame_stripped',
                ]];
            }

            // Moldura ABERTA (sem fecho) ou nada além dela: não dá para saber
            // onde o pensamento termina. Aí sim, bloqueia — fail-closed.
            return [self::BLOCKED_NOTICE, [
                'changed' => true,
                'reason' => 'reasoning_frame_blocked',
            ]];
        }

        if ($this->looksLikeQualityRepairPromptEcho($original)) {
            $clean = $this->stripQualityRepairPromptEcho($original);
            if ($clean !== '') {
                return [$clean, [
                    'changed' => $clean !== $original,
                    'reason' => 'quality_repair_prompt_echo_stripped',
                ]];
            }
        }

        if ($this->hasInternalLeakMarkers($original)) {
            return [self::BLOCKED_NOTICE, [
                'changed' => true,
                'reason' => 'internal_context_leak_blocked',
            ]];
        }

        // Modelos sem harness de tools (ex.: rota Hermes) vazam marcação interna
        // como TEXTO: <antThinking>…</antThinking> e pseudo-tool-calls
        // <toolcodeinterpreter(code="…")>…</tool…>, às vezes com o MESMO bloco
        // repetido várias vezes (retry/continuation costurado). Isso chegava cru
        // na UI (incidente 02/07 — chat inusável). Strip fechado + fallback para
        // marcação não fechada + dedupe de parágrafos consecutivos idênticos.
        $stripped = $this->stripLeakedModelMarkup($original);
        if ($stripped !== $original) {
            $clean = trim($stripped);
            if ($clean === '') {
                return ['O modelo devolveu apenas marcação interna (raciocínio/pseudo-ferramentas) sem resposta utilizável. Reenvie o pedido — o Atlas registrou a falha do provider para a próxima decisão de rota.', [
                    'changed' => true,
                    'reason' => 'model_markup_only_response',
                ]];
            }

            return [$clean, [
                'changed' => true,
                'reason' => 'leaked_model_markup_stripped',
            ]];
        }

        return [$original, ['changed' => false, 'reason' => null]];
    }

    public function forOperator(string $text, int $limit = 12000): string
    {
        [$clean] = $this->sanitize($text);

        return Str::limit($clean, $limit, "\n...[resposta anterior truncada pelo Atlas]");
    }

    private function extractProviderTranscriptResult(string $text): ?string
    {
        $events = $this->jsonObjectsFromText($text);
        if ($events === []) {
            return null;
        }

        $result = '';
        $assistantText = '';

        foreach ($events as $event) {
            $eventResult = $this->stringValue($event['result'] ?? null);
            if (($event['type'] ?? null) === 'result' && $eventResult !== '') {
                $result = $eventResult;

                continue;
            }

            $textFromPayload = $this->assistantTextFromPayload($event);
            if ($textFromPayload !== '') {
                $assistantText .= $textFromPayload;
            }
        }

        $candidate = trim($result !== '' ? $result : $assistantText);
        if ($candidate === '') {
            return null;
        }

        return $this->hasInternalLeakMarkers($candidate) ? null : $candidate;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function jsonObjectsFromText(string $text): array
    {
        $events = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, '{')) {
                continue;
            }

            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        if ($events !== []) {
            return $events;
        }

        $decoded = json_decode(trim($text), true);
        if (is_array($decoded)) {
            return [$decoded];
        }

        return [];
    }

    private function assistantTextFromPayload(array $payload): string
    {
        $message = is_array($payload['message'] ?? null) ? $payload['message'] : $payload;
        $content = $message['content'] ?? null;
        if (! is_array($content)) {
            return $this->stringValue($message['text'] ?? null);
        }

        $text = '';
        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) !== 'text') {
                continue;
            }

            $text .= $this->stringValue($block['text'] ?? null);
        }

        return $text;
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function looksLikeQualityRepairPromptEcho(string $text): bool
    {
        $lower = Str::lower($text);

        return str_contains($lower, 'reescreva a resposta abaixo como saída final do atlas')
            && str_contains($lower, 'pedido original:')
            && str_contains($lower, 'resposta anterior:')
            && str_contains($lower, 'falhas detectadas:');
    }

    private function hasBoxedReasoningFrame(string $text): bool
    {
        return preg_match('/^[┌╭]\s*[─-]*\s*(reasoning|chain of thought)\b/imu', $text) === 1;
    }

    /**
     * Arranca as molduras de raciocínio FECHADAS e devolve o que sobrou.
     *
     * `null` = há moldura aberta (sem fecho). Sem o fecho não há como saber
     * onde o pensamento termina e a resposta começa — e chutar aí é pior que
     * bloquear.
     *
     * O modelo desenha:
     *
     *     ┌─ Reasoning ─────────┐
     *      … pensamento …
     *     └─────────────────────┘
     *     o veredito de verdade
     *
     * Bloquear o conjunto inteiro (comportamento anterior) tratava a resposta
     * como cúmplice do pensamento. O leak é a moldura; o veredito é a entrega.
     */
    private function stripBoxedReasoningFrames(string $text): ?string
    {
        // Par fechado: da abertura até a primeira linha de fecho (└…┘ / ╰…╯).
        $stripped = (string) preg_replace(
            '/^[┌╭][^\n]*(?:reasoning|chain of thought)[^\n]*\n.*?^[└╰][^\n]*$/imsu',
            '',
            $text
        );

        // Sobrou abertura sem fecho: não dá para delimitar o pensamento.
        if ($this->hasBoxedReasoningFrame($stripped)) {
            return null;
        }

        // Linhas soltas de moldura (o desenho da caixa sem conteúdo) não são
        // resposta: saem também, senão "sobra" texto que é só borda.
        $stripped = (string) preg_replace('/^[┌└├╭╰│][^\n]*$/mu', '', $stripped);

        return $stripped;
    }

    private function stripQualityRepairPromptEcho(string $text): string
    {
        $parts = preg_split('/\n\s*Regras:\s*\n/i', $text, 2);
        if (! is_array($parts) || count($parts) < 2) {
            return '';
        }

        $afterRules = trim((string) $parts[1]);
        $lines = preg_split('/\R/', $afterRules) ?: [];
        while ($lines !== [] && str_starts_with(trim((string) $lines[0]), '-')) {
            array_shift($lines);
        }

        $clean = trim(implode("\n", $lines));

        return $this->hasInternalLeakMarkers($clean) ? '' : $clean;
    }

    private function stripLeakedModelMarkup(string $text): string
    {
        // Só age quando há sentinel de marcação vazada — parágrafos repetidos
        // sem markup são conteúdo legítimo do modelo, não um leak (não inventar
        // mudança).
        if (preg_match('/<antThinking>|<tool[a-z_]*\s*\(code=|<\/tool/i', $text) !== 1) {
            return $text;
        }

        // 1) Pares fechados de raciocínio interno vazado como texto.
        $text = (string) preg_replace('/<antThinking>.*?<\/antThinking>/su', '', $text);

        // 2) Pseudo-tool-calls fechados: <tool…(…)>corpo</tool…>.
        $text = (string) preg_replace('/<tool[^<]*?>.*?<\/tool[^>]*>/su', '', $text);

        // 3) Marcação NÃO fechada: do sentinel ao fim do texto (o modelo foi
        //    cortado no meio da "chamada"). Só quando o sentinel é inequívoco.
        $text = (string) preg_replace('/<antThinking>(?!.*<\/antThinking>).*$/su', '', $text);
        $text = (string) preg_replace('/<tool[a-z_]*\s*\(code=.*$/su', '', $text);

        // 4) Dedupe de parágrafos consecutivos idênticos (retry costurado
        //    repetindo o mesmo bloco em torno da marcação). Só igualdade exata
        //    pós-trim — nunca remove conteúdo genuinamente distinto.
        $paragraphs = preg_split('/\n{2,}/', $text) ?: [];
        $deduped = [];
        $previous = null;
        foreach ($paragraphs as $paragraph) {
            $key = trim($paragraph);
            if ($key !== '' && $key === $previous) {
                continue;
            }
            $deduped[] = $paragraph;
            $previous = $key === '' ? $previous : $key;
        }
        $text = implode("\n\n", $deduped);

        // 5) Dedupe de FRASES consecutivas idênticas, por parágrafo — o retry
        //    costurado da rota sem-harness repete a mesma sentença inline (o
        //    incidente tinha a mesma frase dezenas de vezes). Só igualdade
        //    exata entre sentenças vizinhas pós-trim; texto genuíno não repete
        //    sentença literal colada em si mesma. Por-parágrafo preserva
        //    quebras de linha/headings intocados.
        $paragraphs = explode("\n\n", $text);
        foreach ($paragraphs as $i => $paragraph) {
            $sentences = preg_split('/(?<=[.!?])[ \t]+/u', $paragraph) ?: [];
            $kept = [];
            $previousSentence = null;
            foreach ($sentences as $sentence) {
                $key = trim($sentence);
                if ($key !== '' && $key === $previousSentence) {
                    continue;
                }
                $kept[] = $sentence;
                $previousSentence = $key === '' ? $previousSentence : $key;
            }
            $paragraphs[$i] = implode(' ', $kept);
        }

        return implode("\n\n", $paragraphs);
    }

    public function hasInternalLeakMarkers(string $text): bool
    {
        $lower = Str::lower($text);

        foreach (InternalLeakMarkers::substrings() as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return (bool) (
            preg_match(InternalLeakMarkers::correlationIdPattern(), $text)
            && preg_match(InternalLeakMarkers::envelopeEvidencePattern(), $text)
        );
    }
}
