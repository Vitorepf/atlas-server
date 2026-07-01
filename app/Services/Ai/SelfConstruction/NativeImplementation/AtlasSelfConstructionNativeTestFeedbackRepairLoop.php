<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

/**
 * Pure repair-loop. Consumes structured test-failure FACTS plus the current patch plan, and emits
 * BOUNDED repair proposals from a small template library. NEVER calls a provider, NEVER edits files.
 *
 * Supported failure kinds:
 *   - missing_class                ⇒ template: stub_class_skeleton
 *   - namespace_mismatch           ⇒ template: fix_namespace_declaration
 *   - assertion_mismatch           ⇒ template: align_assertion_or_implementation
 *   - import_error                 ⇒ template: add_missing_use_statement
 *
 * Anything else returns `needs_external_capability` — the loop NEVER guesses.
 *
 * Output: {schema_version, proposals:list<{failure_kind, target_path, template_id, hint}>,
 *           unknown_failures:list<{failure_kind, original_payload}>}
 */
final class AtlasSelfConstructionNativeTestFeedbackRepairLoop
{
    public const SCHEMA = 'atlas.native_implementation.test_repair_loop.v1';

    public const TEMPLATE_STUB_CLASS = 'stub_class_skeleton';

    public const TEMPLATE_FIX_NAMESPACE = 'fix_namespace_declaration';

    public const TEMPLATE_ALIGN_ASSERTION = 'align_assertion_or_implementation';

    public const TEMPLATE_ADD_USE = 'add_missing_use_statement';

    public const TEMPLATE_FIX_SYNTAX = 'fix_syntax_error';

    public const TEMPLATE_STUB_METHOD = 'stub_missing_method';

    public const TEMPLATE_FIX_TYPE_MISMATCH = 'align_declared_type';

    public const RESPONSE_NEEDS_EXTERNAL = 'needs_external_capability';

    public const RESPONSE_GIVE_BACK_REQUIRED = 'give_back_required';

    private const KIND_TEMPLATE_MAP = [
        'missing_class' => self::TEMPLATE_STUB_CLASS,
        'namespace_mismatch' => self::TEMPLATE_FIX_NAMESPACE,
        'assertion_mismatch' => self::TEMPLATE_ALIGN_ASSERTION,
        'import_error' => self::TEMPLATE_ADD_USE,
        'syntax_error' => self::TEMPLATE_FIX_SYNTAX,
        'missing_method' => self::TEMPLATE_STUB_METHOD,
        'type_mismatch' => self::TEMPLATE_FIX_TYPE_MISMATCH,
    ];

    /**
     * Failure kinds or facts that require broad redesign or an operator decision -- the loop
     * never proposes a bounded template edit for these, it always gives the packet back.
     */
    private const GIVE_BACK_KINDS = ['out_of_scope_behavior'];

    private const GIVE_BACK_FLAGS = ['contradictory_acceptance', 'forbidden_scope', 'requires_broad_redesign'];

    /**
     * @param  list<array<string,mixed>>  $failures
     * @param  array<string,mixed>  $patchPlan
     * @return array<string,mixed>
     */
    public function repair(array $failures, array $patchPlan): array
    {
        $allowed = array_values((array) ($patchPlan['allowed_files'] ?? []));
        $maxRetryCount = isset($patchPlan['max_retry_count']) ? (int) $patchPlan['max_retry_count'] : PHP_INT_MAX;
        $proposals = [];
        $unknown = [];
        $giveBackRequired = false;

        foreach ($failures as $f) {
            if (! is_array($f)) {
                continue;
            }
            $kind = (string) ($f['failure_kind'] ?? '');
            $targetPath = (string) ($f['target_path'] ?? '');
            $retryCount = isset($f['retry_count']) ? (int) $f['retry_count'] : 0;

            $activeGiveBackFlags = array_values(array_filter(
                self::GIVE_BACK_FLAGS,
                static fn (string $flag): bool => (bool) ($f[$flag] ?? false),
            ));
            if (in_array($kind, self::GIVE_BACK_KINDS, true) || $activeGiveBackFlags !== []) {
                $giveBackRequired = true;
                $unknown[] = [
                    'failure_kind' => $kind ?: 'unknown',
                    'original_payload' => $f,
                    'reason' => $activeGiveBackFlags !== [] ? implode(',', $activeGiveBackFlags) : 'requires_broad_redesign',
                    'terminal_repair_blocked' => true,
                    'retryable' => false,
                ];

                continue;
            }

            if ($retryCount >= $maxRetryCount) {
                $unknown[] = [
                    'failure_kind'          => $kind ?: 'unknown',
                    'original_payload'      => $f,
                    'reason'                => 'retry_budget_exhausted',
                    'terminal_repair_blocked' => true,
                    'retryable'             => false,
                ];

                continue;
            }

            if (! isset(self::KIND_TEMPLATE_MAP[$kind])) {
                $unknown[] = [
                    'failure_kind'          => $kind ?: 'unknown',
                    'original_payload'      => $f,
                    'terminal_repair_blocked' => true,
                    'retryable'             => false,
                ];

                continue;
            }
            if ($targetPath === '' || ! in_array($targetPath, $allowed, true)) {
                $unknown[] = [
                    'failure_kind'          => $kind,
                    'original_payload'      => $f,
                    'reason'                => 'target_path_not_in_allowed_files',
                    'terminal_repair_blocked' => true,
                    'retryable'             => false,
                ];

                continue;
            }

            $proposals[] = [
                'failure_kind' => $kind,
                'target_path'  => $targetPath,
                'template_id'  => self::KIND_TEMPLATE_MAP[$kind],
                'hint'         => $this->hintFor($kind, $f),
                'next_attempt' => [
                    'retry_count'      => $retryCount + 1,
                    'original_payload' => $f,
                    'retryable'        => true,
                ],
            ];
        }

        $response = match (true) {
            $giveBackRequired => self::RESPONSE_GIVE_BACK_REQUIRED,
            $unknown !== [] && $proposals === [] => self::RESPONSE_NEEDS_EXTERNAL,
            default => 'partial_or_complete',
        };

        return [
            'schema_version' => self::SCHEMA,
            'proposals' => $proposals,
            'unknown_failures' => $unknown,
            'response' => $response,
        ];
    }

    /**
     * @param  array<string,mixed>  $fact
     */
    private function hintFor(string $kind, array $fact): string
    {
        return match ($kind) {
            'missing_class' => 'create class '.(string) ($fact['class_name'] ?? 'Unknown'),
            'namespace_mismatch' => 'expected namespace '.(string) ($fact['expected_namespace'] ?? '?'),
            'assertion_mismatch' => 'expected '.(string) ($fact['expected'] ?? '?').' got '.(string) ($fact['actual'] ?? '?'),
            'import_error' => 'add use '.(string) ($fact['missing_symbol'] ?? '?').';',
            'syntax_error' => 'fix syntax near '.(string) ($fact['location'] ?? '?'),
            'missing_method' => 'stub method '.(string) ($fact['method_name'] ?? '?').' on '.(string) ($fact['class_name'] ?? '?'),
            'type_mismatch' => 'expected type '.(string) ($fact['expected_type'] ?? '?').' got '.(string) ($fact['actual_type'] ?? '?'),
            default => '',
        };
    }
}
