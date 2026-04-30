<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiInboxItem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProposalInboxEmitter
{
    public function __construct(
        private readonly ContextBundleService $bundles,
        private readonly AtlasInboxService $inbox,
    ) {
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function emit(array $data): ?AiInboxItem
    {
        if (! Schema::hasTable('ai_context_bundles') || ! Schema::hasTable('ai_inbox_items')) {
            return null;
        }

        $title = $this->string($data['title'] ?? null, 'Proposta do Atlas');
        $problem = $this->string($data['problem'] ?? null, 'Problema ainda nao detalhado.');
        $solution = $this->string($data['solution'] ?? null, 'Solucao ainda nao detalhada.');
        $worthIt = $this->string($data['worth_it'] ?? null, 'Precisa de revisao do operador.');
        $dedupeKey = $this->nullableString($data['dedupe_key'] ?? null)
            ?: 'proposal:'.md5($title.'|'.$problem.'|'.$solution);

        if ($existing = $this->existingActiveItem($dedupeKey)) {
            return $existing;
        }

        $body = $this->body($data, $problem, $solution, $worthIt);
        $bundle = $this->bundles->create([
            'purpose' => 'proposal',
            'title' => $title,
            'summary' => Str::limit($problem, 240, '...'),
            'body_for_thread' => $this->threadBody($title, $body),
            'source_refs' => $this->array($data['source_refs'] ?? []),
            'trace_refs' => $this->array($data['trace_refs'] ?? []),
            'job_refs' => $this->array($data['job_refs'] ?? []),
            'file_refs' => $this->array($data['file_refs'] ?? []),
            'diff_refs' => $this->array($data['diff_refs'] ?? []),
            'raw_payload' => [
                'branch' => $this->nullableString($data['branch'] ?? null),
                'problem' => $problem,
                'solution' => $solution,
                'worth_it' => $worthIt,
                'alternatives' => $this->array($data['alternatives'] ?? []),
                'policy' => $this->policy($data),
            ],
            'expires_at' => now()->addDays(7),
        ]);

        return $this->inbox->create([
            'type' => 'proposal',
            'category' => $this->string($data['category'] ?? null, 'auto_improvement'),
            'severity' => 'info',
            'title' => $title,
            'summary' => Str::limit($problem, 220, '...'),
            'body' => $body,
            'source_type' => $this->nullableString($data['source_type'] ?? null),
            'source_id' => $this->nullableString($data['source_id'] ?? null),
            'initiator' => 'atlas',
            'context_bundle_id' => $bundle->id,
            'dedupe_key' => $dedupeKey,
            'available_actions' => [
                ['id' => 'review_patch', 'label' => 'Revisar proposta', 'style' => 'primary'],
                ['id' => 'discuss', 'label' => 'Discutir com Atlas', 'style' => 'default'],
                ['id' => 'discard', 'label' => 'Descartar', 'style' => 'destructive', 'requires_confirm' => true],
            ],
            'payload' => [
                'branch' => $this->nullableString($data['branch'] ?? null),
                'problem' => $problem,
                'solution' => $solution,
                'worth_it' => $worthIt,
                'alternatives' => $this->array($data['alternatives'] ?? []),
                'policy' => $this->policy($data),
            ],
            'push_policy' => ['send' => 'auto', 'reason' => 'auto_improvement_proposal'],
            'confidence_score' => isset($data['confidence']) ? (float) $data['confidence'] : null,
            'priority_score' => 65,
            'expires_at' => now()->addDays(7),
        ]);
    }

    private function existingActiveItem(string $dedupeKey): ?AiInboxItem
    {
        return AiInboxItem::query()
            ->where('dedupe_key', $dedupeKey)
            ->whereNotIn('status', ['resolved', 'dismissed', 'expired'])
            ->latest('created_at')
            ->first();
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function body(array $data, string $problem, string $solution, string $worthIt): string
    {
        $alternatives = $this->array($data['alternatives'] ?? []);
        $alternativeText = $alternatives === []
            ? 'Nenhuma alternativa registrada.'
            : implode('; ', array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : json_encode($item), $alternatives));

        return implode("\n\n", [
            'O que encontrei: '.$this->string($data['finding'] ?? null, $problem),
            'Qual o problema: '.$problem,
            'Solucao proposta: '.$solution,
            'Era a melhor solucao: '.$this->string($data['best_solution_rationale'] ?? null, $alternativeText),
            'Vale a pena: '.$worthIt,
            'Policy: sem commit/merge automatico; operador precisa revisar antes de aplicar.',
        ]);
    }

    private function threadBody(string $title, string $body): string
    {
        return implode("\n\n", [
            'Atlas criou esta proposta de melhoria para revisao.',
            'Titulo: '.$title,
            $body,
            'Objetivo da conversa: validar se o problema e real, se a solucao vale o custo e qual proximo passo seguro.',
        ]);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function policy(array $data): array
    {
        $provided = $this->array($data['policy'] ?? []);

        return array_replace([
            'auto_commit' => false,
            'auto_merge' => false,
            'requires_operator_review' => true,
            'requires_tests_passed' => true,
        ], $provided);
    }

    /**
     * @return array<int|string,mixed>
     */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function string(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
