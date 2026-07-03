<?php

declare(strict_types=1);

namespace App\Services\Ai\Gateway;

/**
 * Structural weak-response probe for the CHAT/gateway path — the chat-side
 * sibling of the Dev pipeline's DevWeakOutputDetector (W1). The specialist
 * flow contract rides into the live prompt (AiPromptBuilder::
 * specialistFlowInstructions) but nothing ever inspected the RESPONSE; a
 * weak/violating answer sailed through as a binary provider success and the
 * ADML learned-routing feedback stayed blind to quality.
 *
 * Honest by construction: response_shape entries are SEMANTIC targets (the
 * prompt explicitly forbids writing them as literal identifiers), so this
 * probe never demands shape keys in the text — it only flags mechanical,
 * unambiguous defects:
 *   - empty/whitespace-only response;
 *   - literal snake_case contract identifiers leaking into the text (the
 *     prompt says NUNCA — only multi-word ids are checked, single words
 *     collide with prose);
 *   - placeholder markers (case-sensitive TODO/FIXME — PT-BR prose contains
 *     'todo' as a word, the pétreo lesson from the Dev W1 probe);
 *   - a suspiciously short response for a non-conversation specialist flow.
 *
 * Pure function, no I/O. Advisory: callers flag, never block.
 */
final class ChatWeakResponseProbe
{
    private const MIN_STRUCTURED_RESPONSE_CHARS = 40;

    /**
     * @param  array<string,mixed>  $specialistExecution  payload.specialist_flow_execution (may be [])
     * @return array{weak: bool, reasons: list<string>}
     */
    public function inspect(?string $responseText, array $specialistExecution): array
    {
        $text = (string) $responseText;
        $trimmed = trim($text);
        $reasons = [];

        if ($trimmed === '') {
            return ['weak' => true, 'reasons' => ['empty_response']];
        }

        // Case-sensitive with unicode word boundaries: 'método'/'todo' in
        // PT-BR prose must never trip the marker (Dev W1 pétreo lesson).
        if (preg_match('/(*UCP)\bTODO\b|\bFIXME\b|\blorem ipsum\b/u', $trimmed) === 1) {
            $reasons[] = 'placeholder_marker_in_response';
        }

        // Marcação interna vazada como texto (incidente 02/07, rota sem
        // harness de tools): <antThinking> / pseudo-tool-calls. O sanitizer
        // limpa o que o operador vê; ESTE flag roda no output CRU e derruba o
        // quality_score da rota no ledger ADML — a rota que finge executar
        // código degrada em vez de parecer permanentemente verde.
        if (preg_match('/<antThinking>|<tool[a-z_]*\s*\(code=|<\/tool[a-z_]*>/i', $trimmed) === 1) {
            $reasons[] = 'leaked_model_markup';
        }

        $flowId = trim((string) ($specialistExecution['flow_id'] ?? ''));
        $hasContract = $specialistExecution !== [] && $flowId !== '' && $flowId !== 'atlas_conversation';

        if ($hasContract) {
            if (mb_strlen($trimmed) < self::MIN_STRUCTURED_RESPONSE_CHARS) {
                $reasons[] = 'suspiciously_short_for_specialist_flow';
            }

            foreach ($this->literalForbiddenIdentifiers($specialistExecution) as $identifier) {
                if (preg_match('/\b'.preg_quote($identifier, '/').'\b/i', $trimmed) === 1) {
                    $reasons[] = 'literal_contract_identifier:'.$identifier;
                }
            }
        }

        return ['weak' => $reasons !== [], 'reasons' => $reasons];
    }

    /**
     * Multi-word snake_case ids from the contract's response_shape: writing
     * them literally in the body is exactly what the rendered prompt forbids.
     * Single-word ids are skipped — they collide with legitimate prose.
     *
     * @param  array<string,mixed>  $specialistExecution
     * @return list<string>
     */
    private function literalForbiddenIdentifiers(array $specialistExecution): array
    {
        $ids = [];
        foreach ((array) ($specialistExecution['response_shape'] ?? []) as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            $id = trim($entry);
            if ($id !== '' && str_contains($id, '_') && preg_match('/^[a-z0-9_]+$/', $id) === 1) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
