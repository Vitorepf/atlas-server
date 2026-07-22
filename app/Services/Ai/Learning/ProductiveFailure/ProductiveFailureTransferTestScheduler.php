<?php

namespace App\Services\Ai\Cognitive\ProductiveFailure;

use Illuminate\Support\Str;

class ProductiveFailureTransferTestScheduler
{
    /**
     * @return array<string,mixed>
     */
    public function propose(array $session, array $articulation, int $days = 7): array
    {
        $topic = (string) data_get($session, 'phase_1_problem.topic', 'unknown');
        $principle = (string) ($articulation['principle_extracted'] ?? $articulation['model_update'] ?? 'principio_nao_declarado');

        return [
            'schema_version' => 'atlas.cognitive.productive_failure.transfer_test_proposal.v1',
            'id' => 'pf-transfer-'.Str::ulid(),
            'status' => 'proposal_only',
            'scheduled_for' => now()->addDays(max(7, min(14, $days)))->toDateString(),
            'topic' => $topic,
            'domain' => $session['domain'] ?? 'learning',
            'prompt' => "Aplique o principio extraido de '{$topic}' em um caso diferente e prove transferencia: {$principle}",
            'review_required' => true,
            'auto_apply_to_curriculum' => false,
        ];
    }
}
