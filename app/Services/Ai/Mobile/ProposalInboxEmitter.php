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
    ) {}

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

        $metadata = $this->array($data['metadata'] ?? []);
        $reviewSignal = $this->array($metadata['review_signal'] ?? []);
        $body = $this->body($data, $problem, $solution, $worthIt);
        $payload = $this->proposalPayload($data, $problem, $solution, $worthIt);
        $availableActions = $this->availableActions($data);
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
                ...$payload,
            ],
            'expires_at' => now()->addDays(7),
        ]);

        return $this->inbox->create([
            'type' => 'proposal',
            'category' => $this->string($data['category'] ?? null, 'auto_improvement'),
            'severity' => $this->severityFromReviewSignal($reviewSignal),
            'title' => $title,
            'summary' => Str::limit($problem, 220, '...'),
            'body' => $body,
            'source_type' => $this->nullableString($data['source_type'] ?? null),
            'source_id' => $this->nullableString($data['source_id'] ?? null),
            'initiator' => 'atlas',
            'context_bundle_id' => $bundle->id,
            'dedupe_key' => $dedupeKey,
            'available_actions' => $availableActions,
            'payload' => [
                'branch' => $this->nullableString($data['branch'] ?? null),
                ...$payload,
            ],
            'push_policy' => ['send' => 'auto', 'reason' => 'auto_improvement_proposal'],
            'confidence_score' => isset($data['confidence']) ? (float) $data['confidence'] : null,
            'priority_score' => $this->priorityFromReviewSignal($reviewSignal),
            'expires_at' => now()->addDays(7),
        ]);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function proposalPayload(array $data, string $problem, string $solution, string $worthIt): array
    {
        $metadata = $this->array($data['metadata'] ?? []);
        $payload = $this->array($data['payload'] ?? []);

        return array_replace_recursive($payload, [
            'problem' => $problem,
            'solution' => $solution,
            'worth_it' => $worthIt,
            'alternatives' => $this->array($data['alternatives'] ?? []),
            'policy' => $this->policy($data),
            'proposal_contract' => [
                'schema_version' => $metadata['schema_version'] ?? null,
                'review_signal' => $this->array($metadata['review_signal'] ?? []),
                'source_refs' => $this->array($data['source_refs'] ?? []),
                'trace_refs' => $this->array($data['trace_refs'] ?? []),
                'job_refs' => $this->array($data['job_refs'] ?? []),
                'file_refs' => $this->array($data['file_refs'] ?? []),
                'diff_refs' => $this->array($data['diff_refs'] ?? []),
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<int,array<string,mixed>>
     */
    private function availableActions(array $data): array
    {
        $default = [
            ['id' => 'review_patch', 'label' => 'Revisar proposta', 'style' => 'primary'],
            ['id' => 'discuss', 'label' => 'Discutir com Atlas', 'style' => 'default'],
            ['id' => 'discard', 'label' => 'Descartar', 'style' => 'destructive', 'requires_confirm' => true],
        ];

        $provided = collect($this->array($data['available_actions'] ?? []))
            ->filter(fn (mixed $action): bool => is_array($action) && $this->nullableString($action['id'] ?? null) !== null)
            ->map(fn (array $action): array => $action)
            ->values()
            ->all();

        return collect([...$provided, ...$default])
            ->unique(fn (array $action): string => (string) $action['id'])
            ->values()
            ->all();
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
     * @param  array<string,mixed>  $reviewSignal
     */
    private function severityFromReviewSignal(array $reviewSignal): string
    {
        return match ($this->nullableString($reviewSignal['severity'] ?? null)) {
            'critical', 'high' => 'critical',
            'medium', 'low' => 'warning',
            'debug' => 'debug',
            default => 'info',
        };
    }

    /**
     * @param  array<string,mixed>  $reviewSignal
     */
    private function priorityFromReviewSignal(array $reviewSignal): int
    {
        return match ($this->nullableString($reviewSignal['severity'] ?? null)) {
            'critical' => 95,
            'high' => 85,
            'medium' => 75,
            'low' => 65,
            'debug' => 35,
            default => 55,
        };
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
