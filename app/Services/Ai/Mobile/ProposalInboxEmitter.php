<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiInboxItem;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

class ProposalInboxEmitter
{
    use MobileArrayHelper;

    public function __construct(
        private readonly ContextBundleService $bundles,
        private readonly AtlasInboxService $inbox,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public function emit(array $data): ?AiInboxItem
    {
        if (DatabaseTableAvailability::missing(['ai_context_bundles', 'ai_inbox_items']) !== []) {
            return null;
        }

        $title = $this->proposalTitle($data['title'] ?? null);
        $problem = $this->proposalText($data['problem'] ?? null, 'Problema ainda nao detalhado.');
        $solution = $this->proposalText($data['solution'] ?? null, 'Solucao ainda nao detalhada.');
        $worthIt = $this->proposalText($data['worth_it'] ?? null, 'Precisa de revisao do operador.');
        $dedupeKey = $this->dedupeKey($data['dedupe_key'] ?? null, $title, $problem, $solution);

        if ($existing = $this->existingActiveItem($dedupeKey)) {
            return $existing;
        }

        $metadata = $this->array($data['metadata'] ?? []);
        $reviewSignal = $this->array($metadata['review_signal'] ?? []);
        $body = $this->body($data, $problem, $solution, $worthIt);
        $payload = $this->proposalPayload($data, $problem, $solution, $worthIt);
        $availableActions = $this->availableActions($data);
        $branch = $this->branch($data['branch'] ?? null);
        $bundle = $this->bundles->create([
            'purpose' => 'proposal',
            'title' => $title,
            'summary' => Str::limit($problem, 240, '...'),
            'body_for_thread' => $this->threadBody($title, $body),
            'source_refs' => $this->refs($data['source_refs'] ?? []),
            'trace_refs' => $this->refs($data['trace_refs'] ?? []),
            'job_refs' => $this->refs($data['job_refs'] ?? []),
            'file_refs' => $this->refs($data['file_refs'] ?? []),
            'diff_refs' => $this->refs($data['diff_refs'] ?? []),
            'raw_payload' => [
                'branch' => $branch,
                ...$payload,
            ],
            'expires_at' => now()->addDays(7),
        ]);

        return $this->inbox->create([
            'type' => 'proposal',
            'category' => $this->category($data['category'] ?? null),
            'severity' => $this->severityFromReviewSignal($reviewSignal),
            'title' => $title,
            'summary' => Str::limit($problem, 220, '...'),
            'body' => $body,
            'source_type' => $this->sourceType($data['source_type'] ?? null),
            'source_id' => $this->sourceId($data['source_id'] ?? null),
            'initiator' => 'atlas',
            'context_bundle_id' => $bundle->id,
            'dedupe_key' => $dedupeKey,
            'available_actions' => $availableActions,
            'payload' => [
                'branch' => $branch,
                ...$payload,
            ],
            'push_policy' => ['send' => 'auto', 'reason' => 'auto_improvement_proposal'],
            'confidence_score' => $this->confidence($data['confidence'] ?? null),
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
        $payload = $this->externalPayload($payload);
        unset($payload['policy']);

        $proposal = array_replace_recursive($payload, [
            'problem' => $problem,
            'solution' => $solution,
            'worth_it' => $worthIt,
            'alternatives' => $this->alternatives($data['alternatives'] ?? []),
            'policy' => $this->policy($data),
            'proposal_contract' => [
                'schema_version' => $metadata['schema_version'] ?? null,
                'review_signal' => $this->array($metadata['review_signal'] ?? []),
                'source_refs' => $this->array($data['source_refs'] ?? []),
                'trace_refs' => $this->refs($data['trace_refs'] ?? []),
                'job_refs' => $this->refs($data['job_refs'] ?? []),
                'file_refs' => $this->refs($data['file_refs'] ?? []),
                'diff_refs' => $this->refs($data['diff_refs'] ?? []),
            ],
        ]);

        return $this->sanitizeProposalPayload($proposal);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sanitizeProposalPayload(array $payload): array
    {
        $payload['proposal_contract']['schema_version'] = $this->nullableString(
            data_get($payload, 'proposal_contract.schema_version'),
        );
        $payload['proposal_contract']['review_signal'] = $this->reviewSignal(
            $this->array(data_get($payload, 'proposal_contract.review_signal')),
        );
        $payload['proposal_contract']['source_refs'] = $this->refs(
            data_get($payload, 'proposal_contract.source_refs', []),
        );
        $payload['proposal_contract']['trace_refs'] = $this->refs(
            data_get($payload, 'proposal_contract.trace_refs', []),
        );
        $payload['proposal_contract']['job_refs'] = $this->refs(
            data_get($payload, 'proposal_contract.job_refs', []),
        );
        $payload['proposal_contract']['file_refs'] = $this->refs(
            data_get($payload, 'proposal_contract.file_refs', []),
        );
        $payload['proposal_contract']['diff_refs'] = $this->refs(
            data_get($payload, 'proposal_contract.diff_refs', []),
        );

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $reviewSignal
     * @return array<string,mixed>
     */
    private function reviewSignal(array $reviewSignal): array
    {
        $safe = [];

        $status = $this->reviewToken($reviewSignal['status'] ?? null);
        if ($status !== null) {
            $safe['status'] = $status;
        }

        $severity = $this->reviewSeverity($reviewSignal['severity'] ?? null);
        if ($severity !== null) {
            $safe['severity'] = $severity;
        }

        if (array_key_exists('reason', $reviewSignal)) {
            $reason = $this->proposalText($reviewSignal['reason'] ?? null, '');
            if ($reason !== '') {
                $safe['reason'] = $reason;
            }
        }

        $safe['review_required'] = true;

        $reasons = array_key_exists('reasons', $reviewSignal)
            ? $this->reviewReasons($this->array($reviewSignal['reasons']))
            : null;

        $recommendedAction = $this->nullableString($reviewSignal['recommended_action'] ?? null);

        if (! $this->safeActionId($recommendedAction)) {
            $recommendedAction = 'review_patch';
        }

        $safe['recommended_action'] = $recommendedAction;

        if ($reasons !== null) {
            $safe['reasons'] = $reasons;
        }

        if (array_key_exists('sources', $reviewSignal)) {
            $safe['sources'] = $this->reviewSources($this->array($reviewSignal['sources']));
        }

        return $safe;
    }

    /**
     * @param  array<int|string,mixed>  $reasons
     * @return array<int,string>
     */
    private function reviewReasons(array $reasons): array
    {
        return collect($reasons)
            ->filter(fn (mixed $reason): bool => is_scalar($reason))
            ->map(fn (mixed $reason): string => Str::limit($this->singleLineString($reason, ''), 160, ''))
            ->filter(fn (string $reason): bool => $reason !== '')
            ->take(12)
            ->values()
            ->all();
    }

    private function reviewToken(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null || strlen($value) > 80) {
            return null;
        }

        return preg_match('/^[a-z][a-z0-9_]*$/', $value) === 1 ? $value : null;
    }

    private function reviewSeverity(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        return in_array($value, ['critical', 'high', 'medium', 'low', 'debug'], true) ? $value : null;
    }

    /**
     * @param  array<int|string,mixed>  $sources
     * @return array<int,array<string,string>>
     */
    private function reviewSources(array $sources): array
    {
        return collect($sources)
            ->filter(fn (mixed $source): bool => is_array($source))
            ->map(fn (array $source): array => $this->reviewSource($source))
            ->filter(fn (array $source): bool => $source !== [])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $source
     * @return array<string,string>
     */
    private function reviewSource(array $source): array
    {
        $sanitized = [];

        foreach (['type', 'id', 'path', 'event_id', 'envelope_id', 'trace_id'] as $key) {
            $value = $key === 'type'
                ? $this->reviewToken($source[$key] ?? null)
                : $this->safeRefText($source[$key] ?? null);

            if ($value !== null) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private function safeRefText(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        if ($value === ''
            || strlen($value) > 190
            || str_contains($value, '..')
            || str_contains($value, '//')) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9:._\/-]+$/', $value) === 1 ? $value : null;
    }

    /**
     * @return array<int|string,mixed>
     */
    private function refs(mixed $refs): array
    {
        return collect($this->array($refs))
            ->map(fn (mixed $ref): mixed => $this->refValue($ref))
            ->all();
    }

    private function refValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $safe = [];

        foreach ($value as $key => $nested) {
            if (is_string($key) && $this->dangerousRefKey($key)) {
                continue;
            }

            $safe[$key] = $this->refValue($nested);
        }

        return $safe;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function externalPayload(array $payload): array
    {
        /** @var array<string,mixed> $safe */
        $safe = $this->refValue($payload);

        return $safe;
    }

    private function dangerousRefKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $key));

        return preg_match('/(^|_)(command|payload|auto_?apply|auto_?commit|auto_?merge|apply_?policy|apply_?patch|runtime_?promotion|provider_?direct|memory_?write)/', $normalized) === 1;
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
            ->filter(fn (mixed $action): bool => is_array($action)
                && $this->actionId($action['id'] ?? null) !== null)
            ->map(fn (array $action): array => $this->sanitizeAction($action))
            ->values()
            ->all();

        return collect([...$provided, ...$default])
            ->unique(fn (array $action): string => (string) $action['id'])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $action
     * @return array<string,mixed>
     */
    private function sanitizeAction(array $action): array
    {
        $style = $this->actionStyle($action['style'] ?? null);
        $sanitized = [
            'id' => $this->actionId($action['id'] ?? null) ?? 'review_patch',
            'label' => $this->actionLabel($action['label'] ?? null),
            'style' => $style,
        ];

        if ($style === 'destructive') {
            $sanitized['requires_confirm'] = true;
        } elseif (isset($action['requires_confirm'])) {
            $sanitized['requires_confirm'] = (bool) $action['requires_confirm'];
        }

        return $sanitized;
    }

    private function actionId(mixed $id): ?string
    {
        $id = $this->nullableString($id);

        return $this->safeActionId($id) ? $id : null;
    }

    private function actionStyle(mixed $style): string
    {
        $style = $this->nullableString($style);

        return in_array($style, ['primary', 'default', 'destructive'], true) ? $style : 'default';
    }

    private function actionLabel(mixed $label): string
    {
        $label = $this->nullableString($label);

        if ($label === null) {
            return 'Revisar proposta';
        }

        $label = trim((string) preg_replace('/\s+/', ' ', $label));

        return $label === '' ? 'Revisar proposta' : Str::limit($label, 80, '');
    }

    private function category(mixed $value): string
    {
        $category = $this->nullableString($value);

        if ($category !== null
            && strlen($category) <= 80
            && preg_match('/^[a-z][a-z0-9_]*$/', $category) === 1) {
            return $category;
        }

        return 'auto_improvement';
    }

    private function sourceType(mixed $value): ?string
    {
        $sourceType = $this->nullableString($value);

        if ($sourceType !== null
            && strlen($sourceType) <= 80
            && preg_match('/^[a-z][a-z0-9_]*$/', $sourceType) === 1) {
            return $sourceType;
        }

        return null;
    }

    private function sourceId(mixed $value): ?string
    {
        $sourceId = $this->nullableString($value);

        if ($sourceId !== null
            && strlen($sourceId) <= 190
            && preg_match('/^[A-Za-z0-9:._\/-]+$/', $sourceId) === 1
            && ! str_contains($sourceId, '..')
            && ! str_contains($sourceId, '//')) {
            return $sourceId;
        }

        return null;
    }

    private function safeActionId(?string $id): bool
    {
        if ($id === null) {
            return false;
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,100}$/i', $id) !== 1) {
            return false;
        }

        $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $id));

        return preg_match('/(^|_)(auto_?apply|auto_?commit|auto_?merge|apply_?policy|apply_?patch|commit|merge|deploy|runtime_?promotion|provider_?direct|memory_?write)/', $normalized) !== 1;
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
        $alternatives = $this->alternatives($data['alternatives'] ?? []);
        $alternativeText = $alternatives === []
            ? 'Nenhuma alternativa registrada.'
            : implode('; ', $alternatives);

        return implode("\n\n", [
            'O que encontrei: '.$this->proposalText($data['finding'] ?? null, $problem),
            'Qual o problema: '.$problem,
            'Solucao proposta: '.$solution,
            'Era a melhor solucao: '.$this->proposalText($data['best_solution_rationale'] ?? null, $alternativeText),
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
        $provided = $this->safePolicyOverrides($this->array($data['policy'] ?? []));

        $policy = array_replace([
            'auto_commit' => false,
            'auto_merge' => false,
            'requires_operator_review' => true,
            'requires_tests_passed' => true,
        ], $provided);

        return array_replace($policy, [
            'auto_commit' => false,
            'auto_merge' => false,
            'requires_operator_review' => true,
        ]);
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function safePolicyOverrides(array $policy): array
    {
        $safe = [];

        if (array_key_exists('requires_tests_passed', $policy) && is_bool($policy['requires_tests_passed'])) {
            $safe['requires_tests_passed'] = $policy['requires_tests_passed'];
        }

        $reviewWindow = $this->policyReviewWindow($policy['review_window'] ?? null);
        if ($reviewWindow !== null) {
            $safe['review_window'] = $reviewWindow;
        }

        return $safe;
    }

    private function policyReviewWindow(mixed $value): ?string
    {
        $reviewWindow = $this->nullableString($value);

        if ($reviewWindow === null) {
            return null;
        }

        $reviewWindow = Str::limit(trim((string) preg_replace('/\s+/', ' ', $reviewWindow)), 80, '');

        if ($reviewWindow === '' || str_contains($reviewWindow, '..')) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9 _.:\/-]+$/', $reviewWindow) === 1 ? $reviewWindow : null;
    }

    /**
     * @return array<int,string>
     */
    private function alternatives(mixed $alternatives): array
    {
        return collect($this->array($alternatives))
            ->filter(fn (mixed $alternative): bool => is_scalar($alternative))
            ->map(function (mixed $alternative): string {
                $alternative = trim((string) preg_replace('/\s+/', ' ', (string) $alternative));

                return Str::limit($alternative, 160, '');
            })
            ->filter(fn (string $alternative): bool => $alternative !== '')
            ->take(8)
            ->values()
            ->all();
    }

    private function proposalTitle(mixed $value): string
    {
        return Str::limit($this->singleLineString($value, 'Proposta do Atlas'), 120, '');
    }

    private function proposalText(mixed $value, string $default): string
    {
        return Str::limit($this->singleLineString($value, $default), 1000, '');
    }

    private function singleLineString(mixed $value, string $default): string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return $default;
        }

        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        return $value === '' ? $default : $value;
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
    private function string(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function confidence(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        $confidence = (float) $value;

        if ($confidence < 0.0 || $confidence > 1.0) {
            return null;
        }

        return $confidence;
    }

    private function branch(mixed $value): ?string
    {
        $branch = $this->nullableString($value);

        if ($branch === null || strlen($branch) > 120) {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9._\/-]+$/', $branch) !== 1) {
            return null;
        }

        if (str_contains($branch, '..') || str_starts_with($branch, '/') || str_ends_with($branch, '/')) {
            return null;
        }

        return $branch;
    }

    private function dedupeKey(mixed $value, string $title, string $problem, string $solution): string
    {
        $dedupeKey = $this->nullableString($value);

        if ($dedupeKey !== null
            && strlen($dedupeKey) <= 190
            && preg_match('/^[A-Za-z0-9:._\/-]+$/', $dedupeKey) === 1
            && ! str_contains($dedupeKey, '..')
            && ! str_contains($dedupeKey, '//')) {
            return $dedupeKey;
        }

        return 'proposal:'.md5($title.'|'.$problem.'|'.$solution);
    }
}
