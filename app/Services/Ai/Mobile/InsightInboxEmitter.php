<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiInboxItem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class InsightInboxEmitter
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

        $title = $this->string($data['title'] ?? null, 'Insight do Atlas');
        $summary = $this->string($data['summary'] ?? null, Str::limit($title, 160, '...'));
        $body = $this->string($data['body'] ?? null, $summary);
        $category = $this->string($data['category'] ?? null, 'general');
        $dedupeKey = $this->nullableString($data['dedupe_key'] ?? null)
            ?: 'insight:'.$category.':'.md5($title.'|'.$summary);

        if ($existing = $this->existingActiveItem($dedupeKey)) {
            return $existing;
        }

        $bundle = $this->bundles->create([
            'purpose' => 'insight',
            'title' => $title,
            'summary' => $summary,
            'body_for_thread' => $this->string($data['body_for_thread'] ?? null, $this->threadBody($title, $summary, $body)),
            'source_refs' => $this->array($data['source_refs'] ?? []),
            'trace_refs' => $this->array($data['trace_refs'] ?? []),
            'job_refs' => $this->array($data['job_refs'] ?? []),
            'metric_refs' => $this->array($data['metric_refs'] ?? []),
            'file_refs' => $this->array($data['file_refs'] ?? []),
            'raw_payload' => $this->array($data['raw_payload'] ?? []),
        ]);

        return $this->inbox->create([
            'type' => 'insight',
            'category' => $category,
            'severity' => $this->severity($data['severity'] ?? null),
            'title' => $title,
            'summary' => $summary,
            'body' => $body,
            'source_type' => $this->nullableString($data['source_type'] ?? null),
            'source_id' => $this->nullableString($data['source_id'] ?? null),
            'initiator' => 'atlas',
            'context_bundle_id' => $bundle->id,
            'dedupe_key' => $dedupeKey,
            'payload' => [
                'category' => $category,
                'insight_kind' => $this->nullableString($data['insight_kind'] ?? null) ?: $category,
                'confidence' => isset($data['confidence']) ? (float) $data['confidence'] : null,
            ],
            'push_policy' => [
                'send' => $this->severity($data['severity'] ?? null) === 'info' ? 'auto' : 'immediate',
                'reason' => 'atlas_insight',
            ],
            'priority_score' => $this->severity($data['severity'] ?? null) === 'info' ? 55 : 75,
            'confidence_score' => isset($data['confidence']) ? (float) $data['confidence'] : null,
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

    private function threadBody(string $title, string $summary, string $body): string
    {
        return implode("\n\n", [
            'Atlas criou este insight para iniciar uma conversa contextual.',
            'Titulo: '.$title,
            'Resumo: '.$summary,
            'Detalhe: '.$body,
            'Objetivo da conversa: entender se o sinal importa, decidir acao e transformar isso em tarefa/proposta se fizer sentido.',
        ]);
    }

    private function severity(mixed $value): string
    {
        return in_array($value, ['debug', 'info', 'warning', 'critical'], true) ? $value : 'info';
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
