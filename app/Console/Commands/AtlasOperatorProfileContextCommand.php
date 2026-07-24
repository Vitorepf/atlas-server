<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Models\OperatorProfileItem;
use App\Services\Ai\OperatorIntelligence\OperatorContextComposer;
use App\Services\Ai\OperatorIntelligence\OperatorProfileFeedbackService;
use Illuminate\Console\Command;
use Throwable;

class AtlasOperatorProfileContextCommand extends Command
{
    use ReadsNonEmptyStringOption;

    protected $signature = 'atlas:operator-profile
        {action=context : context}
        {--operator= : Operator id. Defaults to config default.}
        {--flow= : Optional flow id.}
        {--provider-external : Compose provider-safe external context.}
        {--trace-id= : Optional trace id.}
        {--session-id= : Optional session id.}
        {--limit= : Max profile items to include.}
        {--item= : Operator profile item id for correction actions.}
        {--verdict= : Correction verdict: confirm|reject.}
        {--no-record-usage : Do not record context_injected feedback.}
        {--json : Emit JSON.}';

    protected $description = 'Compose provider-safe Operator Intelligence context from active profile items.';

    public function handle(OperatorContextComposer $composer, OperatorProfileFeedbackService $feedback): int
    {
        try {
            $operatorId = $this->operatorId();
            $payload = match (strtolower(trim((string) $this->argument('action')))) {
                'context' => $composer->compose([
                    'operator_id' => $operatorId,
                    'flow' => $this->stringOption('flow'),
                    'provider_external' => (bool) $this->option('provider-external'),
                    'trace_id' => $this->stringOption('trace-id'),
                    'session_id' => $this->stringOption('session-id'),
                    'limit' => $this->option('limit') !== null ? (int) $this->option('limit') : null,
                    'record_usage' => ! (bool) $this->option('no-record-usage'),
                ]),
                'audit' => $this->audit($operatorId),
                'correct' => $this->correct($operatorId, $feedback),
                default => ['ok' => false, 'error' => 'unsupported_action', 'supported' => ['context', 'audit', 'correct']],
            };
        } catch (Throwable $e) {
            $payload = ['ok' => false, 'error' => 'exception', 'message' => $e->getMessage(), 'type' => $e::class];
        }

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        return ($payload['ok'] ?? true) === false ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function audit(string $operatorId): array
    {
        $items = OperatorProfileItem::query()
            ->with('policyRules')
            ->where('operator_id', $operatorId)
            ->orderBy('profile_key')
            ->get();

        $providerSafePrivacy = (array) config('atlas_operator_intelligence.provider_safe_privacy_classes', ['normal']);

        return [
            'ok' => true,
            'schema_version' => 'atlas.operator_profile.audit.v1',
            'operator_id' => $operatorId,
            'local_only' => true,
            'total_items' => $items->count(),
            'denominator' => [
                'operator_profile_items_total' => $items->count(),
                'scope' => 'all_statuses_for_operator',
                'provider_external_payload' => false,
            ],
            'items' => $items->map(function (OperatorProfileItem $item) use ($providerSafePrivacy): array {
                $providerBlocked = ! in_array($item->privacy_class, $providerSafePrivacy, true);

                return [
                    'id' => $item->id,
                    'taxonomy_item_id' => $item->taxonomy_item_id,
                    'profile_key' => $item->profile_key,
                    'summary' => $item->summary,
                    'value' => $item->value,
                    'scope_type' => $item->scope_type,
                    'scope_id' => $item->scope_id,
                    'validity_kind' => $item->validity_kind,
                    'confidence' => $item->confidence,
                    'privacy_class' => $item->privacy_class,
                    'provider_blocked' => $providerBlocked,
                    'automation_level' => $item->automation_level,
                    'status' => $item->status,
                    'source_candidate_id' => $item->source_candidate_id,
                    'source_memory_entry_id' => $item->source_memory_entry_id,
                    'last_applied_at' => $item->last_applied_at?->toIso8601String(),
                    'policy_rules' => $item->policyRules->map(static fn ($rule): array => [
                        'rule_key' => $rule->rule_key,
                        'effect' => $rule->effect,
                        'priority' => $rule->priority,
                        'applies_to_flow' => $rule->applies_to_flow,
                        'provider_safe' => (bool) data_get($rule->rule, 'provider_safe', false),
                    ])->values()->all(),
                    'correction_handle' => 'php artisan atlas:operator-profile correct --item='.$item->id.' --verdict=confirm|reject --json',
                ];
            })->values()->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function correct(string $operatorId, OperatorProfileFeedbackService $feedback): array
    {
        $itemId = $this->stringOption('item');
        $verdict = strtolower((string) $this->stringOption('verdict'));
        if ($itemId === null || ! in_array($verdict, ['confirm', 'reject'], true)) {
            return [
                'ok' => false,
                'error' => 'invalid_correction',
                'required' => ['--item', '--verdict=confirm|reject'],
            ];
        }

        /** @var OperatorProfileItem|null $item */
        $item = OperatorProfileItem::query()
            ->where('operator_id', $operatorId)
            ->whereKey($itemId)
            ->first();

        if ($item === null) {
            return ['ok' => false, 'error' => 'item_not_found', 'item' => $itemId];
        }

        $previousStatus = (string) $item->status;
        if ($verdict === 'reject') {
            $item->forceFill(['status' => OperatorProfileItem::STATUS_PAUSED])->save();
        } elseif ($verdict === 'confirm') {
            $item->forceFill(['status' => OperatorProfileItem::STATUS_ACTIVE])->save();
        }
        $item->refresh();

        $reverseHandle = 'php artisan atlas:operator-profile correct --operator='.$operatorId.' --item='.$item->id.' --verdict=confirm --json';
        $event = $feedback->record($item, 'operator_correction', $verdict === 'confirm' ? 1.0 : 0.0, [
            'verdict' => $verdict,
            'previous_status' => $previousStatus,
            'new_status' => $item->status,
            'reverse_handle' => $reverseHandle,
            'trace_id' => $this->stringOption('trace-id'),
            'session_id' => $this->stringOption('session-id'),
        ]);

        return [
            'ok' => true,
            'schema_version' => 'atlas.operator_profile.correction.v1',
            'operator_id' => $operatorId,
            'item' => [
                'id' => $item->id,
                'profile_key' => $item->profile_key,
                'previous_status' => $previousStatus,
                'status' => $item->status,
            ],
            'feedback' => [
                'id' => $event->id,
                'action' => 'operator_correction',
                'verdict' => $verdict,
                'reverse_handle' => $reverseHandle,
            ],
        ];
    }

    private function operatorId(): string
    {
        return $this->stringOption('operator') ?? (string) config('atlas_operator_intelligence.default_operator_id', 'default');
    }

}
