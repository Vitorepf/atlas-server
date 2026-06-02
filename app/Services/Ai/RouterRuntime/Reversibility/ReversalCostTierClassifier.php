<?php

declare(strict_types=1);

namespace App\Services\Ai\RouterRuntime\Reversibility;

final class ReversalCostTierClassifier
{
    private const SCHEMA_VERSION = 'atlas.router.reversal_cost_tier.v1';

    /**
     * @var array<string, int>
     */
    private const TIER_RANK = [
        'cheap' => 0,
        'moderate' => 1,
        'expensive' => 2,
        'unrecoverable' => 3,
    ];

    private const FAIL_CLOSED_TIER = 'unrecoverable';

    /**
     * @param  array<string, mixed>  $decision
     * @return array{schema_version: string, cost_tier: string, cost_rank: int, fail_closed: bool, reasons: list<array{code: string, detail: string}>, provider_invoked: false, classification_hash: string}
     */
    public function classify(array $decision): array
    {
        $hasMergedSignal = array_key_exists('merged_to_main', $decision);
        $hasExternalSignal = array_key_exists('external_call', $decision);
        $hasChangedSignal = array_key_exists('changed_files', $decision);

        if (! $hasMergedSignal && ! $hasExternalSignal && ! $hasChangedSignal) {
            return $this->failClosed('empty_input', 'no blast signals present; failing closed');
        }

        $mergedToMain = ($decision['merged_to_main'] ?? false) === true;
        $externalCall = ($decision['external_call'] ?? false) === true;
        $changedFiles = $this->changedFileCount($decision['changed_files'] ?? null);

        if ($externalCall && $mergedToMain) {
            return $this->classified('unrecoverable', 'merged_external', 'external call merged to main is unrecoverable', $changedFiles, $mergedToMain, $externalCall);
        }

        if ($mergedToMain) {
            return $this->classified('expensive', 'merged_to_main', 'change merged to main', $changedFiles, $mergedToMain, $externalCall);
        }

        if ($externalCall || $changedFiles >= 20) {
            return $this->classified('expensive', 'wide_blast', 'external call or 20+ changed files', $changedFiles, $mergedToMain, $externalCall);
        }

        if ($changedFiles >= 6) {
            return $this->classified('moderate', 'mid_blast', 'between 6 and 19 changed files', $changedFiles, $mergedToMain, $externalCall);
        }

        if ($changedFiles >= 1) {
            return $this->classified('cheap', 'narrow_blast', 'between 1 and 5 changed files, no merge or external call', $changedFiles, $mergedToMain, $externalCall);
        }

        return $this->failClosed('unreadable_signals', 'no usable blast magnitude; failing closed');
    }

    public function rankOf(string $tier): int
    {
        return self::TIER_RANK[$tier] ?? self::TIER_RANK[self::FAIL_CLOSED_TIER];
    }

    private function changedFileCount(mixed $value): int
    {
        if (is_array($value)) {
            return count($value);
        }

        if (is_int($value)) {
            return $value < 0 ? 0 : $value;
        }

        return 0;
    }

    /**
     * @return array{schema_version: string, cost_tier: string, cost_rank: int, fail_closed: bool, reasons: list<array{code: string, detail: string}>, provider_invoked: false, classification_hash: string}
     */
    private function classified(string $tier, string $code, string $detail, int $changedFiles, bool $mergedToMain, bool $externalCall): array
    {
        $reasons = [['code' => $code, 'detail' => $detail]];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'cost_tier' => $tier,
            'cost_rank' => $this->rankOf($tier),
            'fail_closed' => false,
            'reasons' => $reasons,
            'provider_invoked' => false,
            'classification_hash' => $this->classificationHash($tier, $reasons, $changedFiles, $mergedToMain, $externalCall, false),
        ];
    }

    /**
     * @return array{schema_version: string, cost_tier: string, cost_rank: int, fail_closed: bool, reasons: list<array{code: string, detail: string}>, provider_invoked: false, classification_hash: string}
     */
    private function failClosed(string $code, string $detail): array
    {
        $reasons = [['code' => $code, 'detail' => $detail]];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'cost_tier' => self::FAIL_CLOSED_TIER,
            'cost_rank' => $this->rankOf(self::FAIL_CLOSED_TIER),
            'fail_closed' => true,
            'reasons' => $reasons,
            'provider_invoked' => false,
            'classification_hash' => $this->classificationHash(self::FAIL_CLOSED_TIER, $reasons, 0, false, false, true),
        ];
    }

    /**
     * @param  list<array{code: string, detail: string}>  $reasons
     */
    private function classificationHash(string $tier, array $reasons, int $changedFiles, bool $mergedToMain, bool $externalCall, bool $failClosed): string
    {
        $codes = implode(',', array_map(static fn (array $reason): string => $reason['code'], $reasons));

        $canonical = implode('|', [
            self::SCHEMA_VERSION,
            $tier,
            (string) $this->rankOf($tier),
            $failClosed ? '1' : '0',
            (string) $changedFiles,
            $mergedToMain ? '1' : '0',
            $externalCall ? '1' : '0',
            $codes,
        ]);

        return 'sha256:'.hash('sha256', $canonical);
    }
}
