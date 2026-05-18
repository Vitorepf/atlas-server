<?php

namespace App\Services\Ai\Strategy;

use App\Models\AiStrategyMemo;
use Illuminate\Support\Str;

class StrategyMemoService
{
    public const KIND_OPPORTUNITY = 'opportunity_review';

    public const KIND_VENTURE = 'venture_decision';

    public const KIND_EXPERIMENT = 'experiment_decision';

    public const KIND_GO_NO_GO = 'go_no_go';

    public const KIND_PIVOT = 'pivot_decision';

    public const ALLOWED_KINDS = [
        self::KIND_OPPORTUNITY,
        self::KIND_VENTURE,
        self::KIND_EXPERIMENT,
        self::KIND_GO_NO_GO,
        self::KIND_PIVOT,
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_DECIDED = 'decided';

    public const STATUS_DEFERRED = 'deferred';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * Persist a strategy memo. Memo turns experiment + evidence into a decision
     * with rationale and explicit next actions.
     *
     * @param  array<string,mixed>  $args
     */
    public function create(array $args): AiStrategyMemo
    {
        $title = (string) ($args['title'] ?? '');
        if ($title === '') {
            throw StrategyDomainException::missingField('strategy_memo', 'title');
        }

        $kind = (string) ($args['memo_kind'] ?? '');
        if (! in_array($kind, self::ALLOWED_KINDS, true)) {
            throw StrategyDomainException::invalidValue(
                'strategy_memo',
                'memo_kind',
                'must be one of ['.implode(',', self::ALLOWED_KINDS).']'
            );
        }

        $decision = (string) ($args['decision'] ?? '');
        if ($decision === '') {
            throw StrategyDomainException::missingField('strategy_memo', 'decision');
        }
        $rationale = (array) ($args['rationale'] ?? []);
        if ($rationale === []) {
            throw StrategyDomainException::missingField('strategy_memo', 'rationale');
        }
        $nextActions = (array) ($args['next_actions'] ?? []);
        if ($nextActions === []) {
            throw StrategyDomainException::missingField('strategy_memo', 'next_actions');
        }

        $hashInput = [
            'memo_kind' => $kind,
            'title' => $title,
            'decision' => $decision,
            'rationale' => $rationale,
            'assumptions' => (array) ($args['assumptions'] ?? []),
            'experiment_refs' => (array) ($args['experiment_refs'] ?? []),
            'evidence_refs' => (array) ($args['evidence_refs'] ?? []),
            'next_actions' => $nextActions,
            'opportunity_id' => $args['opportunity_id'] ?? null,
            'venture_blueprint_id' => $args['venture_blueprint_id'] ?? null,
            'strategy_run_id' => $args['strategy_run_id'] ?? null,
        ];

        return AiStrategyMemo::query()->create([
            'uuid' => (string) Str::uuid(),
            'strategy_run_id' => $args['strategy_run_id'] ?? null,
            'opportunity_id' => $args['opportunity_id'] ?? null,
            'venture_blueprint_id' => $args['venture_blueprint_id'] ?? null,
            'memo_kind' => $kind,
            'title' => $title,
            'decision' => $decision,
            'rationale' => $rationale,
            'assumptions' => (array) ($args['assumptions'] ?? []),
            'experiment_refs' => (array) ($args['experiment_refs'] ?? []),
            'evidence_refs' => (array) ($args['evidence_refs'] ?? []),
            'next_actions' => $nextActions,
            'status' => (string) ($args['status'] ?? self::STATUS_DRAFT),
            'memo_hash' => StrategyCanonicalHash::sha256($hashInput),
        ]);
    }
}
