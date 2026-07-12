<?php

declare(strict_types=1);

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;

/** One bounded operator clarification; it cannot grant ProductIntent admission. */
final class ProductIntentClarificationContract
{
    /** @param list<string> $objections @return array<string,mixed> */
    public function request(array $objections, string $owner = 'operator'): array
    {
        $objections = array_values(array_unique(array_filter(array_map('strval', $objections))));
        if ($objections === []) {
            return ['schema' => 'atlas.product_intent.clarification.v1', 'status' => 'not_required', 'questions' => []];
        }
        $reason = $objections[0];
        $question = match (true) {
            str_contains($reason, 'metric') => 'Qual métrica observável e janela devem decidir sucesso ou falha?',
            str_contains($reason, 'world') => 'Qual snapshot de mundo atualizado deve governar esta decisão?',
            str_contains($reason, 'security'), str_contains($reason, 'privacy') => 'Quais limites de segurança/privacidade são obrigatórios?',
            default => 'Qual fato ou restrição resolve a ambiguidade detectada?',
        };
        $questionId = 'clarification-'.substr(MissionCanonicalHash::sha256([$owner, $reason, $question]), 0, 20);

        return [
            'schema' => 'atlas.product_intent.clarification.v1', 'status' => 'required',
            'owner' => $owner, 'max_questions' => 1,
            'questions' => [['id' => $questionId, 'text' => $question, 'reason' => $reason]],
            'request_hash' => MissionCanonicalHash::sha256([$owner, $objections, $question]),
        ];
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function resolve(array $request, string $answer): array
    {
        $answer = trim($answer);
        if ($answer === '' || ($request['status'] ?? '') !== 'required' || count((array) ($request['questions'] ?? [])) !== 1) {
            return ['schema' => 'atlas.product_intent.clarification.v1', 'status' => 'held', 'reason' => 'clarification_answer_invalid'];
        }

        return [
            'schema' => 'atlas.product_intent.clarification.v1', 'status' => 'resolved',
            'question_id' => (string) (($request['questions'][0]['id'] ?? '')),
            'answer_hash' => MissionCanonicalHash::sha256([$answer]),
            'version_hash' => MissionCanonicalHash::sha256([$request['request_hash'] ?? '', $answer]),
        ];
    }
}
