<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Repair;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\PromptSections;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\QualityChecks;
use App\Services\Ai\Programming\AtlasDev\Schemas\FailureCapsule;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

/**
 * Composes a repair-attempt {@see ProviderPromptProjection} from the
 * original projection plus the {@see FailureCapsule} that caused the
 * retry.
 *
 * Hard invariants enforced here (independent of the provider/gate layer):
 *   - `allowed_files` from the original sections are preserved verbatim;
 *   - `forbidden_files` from the original sections are preserved verbatim;
 *   - provider/model lock lives on the {@see LightTaskContract}; this
 *     composer never mutates the contract — it only consumes the lock to
 *     refuse mismatched runs;
 *   - the repair prompt carries explicit stop conditions
 *     (`same_signature_twice`, `diff_growth`, `max_attempts_reached`)
 *     so the model knows when to halt;
 *   - the rendered prompt body appends a `# Repair Capsule` block with the
 *     failure signature, gate, normalized error excerpt and the previous
 *     diff hash, so the model is forced to read the real error.
 */
final class RepairPromptComposer
{
    private const REPAIR_STOP_CONDITIONS = [
        'stop_if_same_failure_signature_repeats',
        'stop_if_diff_grows_beyond_previous_attempt',
        'stop_if_max_repair_attempts_reached',
        'stop_if_scope_expansion_required',
    ];

    private const REPAIR_OPERATING_RULES = [
        'reuse_existing_provider_and_model_from_task_contract',
        'never_expand_allowed_files_during_repair',
        'preserve_unrelated_user_changes',
        'produce_smallest_diff_that_makes_the_failing_gate_pass',
        'report_blockers_explicitly_instead_of_widening_scope',
    ];

    /**
     * @param  positive-int  $maxAttempts  Echoed into the prompt body so the
     *                                     provider knows the budget cap.
     */
    public function compose(
        ProviderPromptProjection $original,
        FailureCapsule $capsule,
        LightTaskContract $contract,
        int $attemptIndex,
        int $maxAttempts,
    ): ProviderPromptProjection {
        if ($attemptIndex < 1) {
            throw new InvalidArgumentException("RepairPromptComposer: attemptIndex must be >= 1, got {$attemptIndex}.");
        }
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException("RepairPromptComposer: maxAttempts must be >= 1, got {$maxAttempts}.");
        }
        if ($original->runId !== $capsule->runId) {
            throw new InvalidArgumentException('RepairPromptComposer: prompt and capsule run_id mismatch.');
        }
        if ($contract->runId !== $original->runId) {
            throw new InvalidArgumentException('RepairPromptComposer: contract and prompt run_id mismatch.');
        }
        if ($contract->taskContractHash !== $capsule->taskContractHash) {
            throw new InvalidArgumentException(
                'RepairPromptComposer: contract and capsule task_contract_hash mismatch (provider lock would be ambiguous).'
            );
        }

        $sections = $this->composeSections($original->sections, $capsule, $attemptIndex, $maxAttempts);

        $renderedText = $this->renderPrompt($original->renderedPromptText, $capsule, $contract, $attemptIndex, $maxAttempts);
        $renderedHash = hash('sha256', $renderedText);

        $upstream = $original->upstreamHashes;
        $upstream['previous_failure_capsule_hash'] = $capsule->capsuleHash;
        $upstream['repair_attempt_index'] = (string) $attemptIndex;

        // The repair projection is provider-safe whenever the original was;
        // we never add raw text the original projection wouldn't have allowed.
        $projection = new ProviderPromptProjection(
            runId: $original->runId,
            upstreamHashes: $upstream,
            sections: $sections,
            qualityChecks: QualityChecks::allPassing(),
            renderedPromptText: $renderedText,
            renderedPromptHash: $renderedHash,
            providerSafe: $original->providerSafe,
            promptProjectionHash: 'pending',
        );
        $finalHash = $this->hashProjection($projection);

        return new ProviderPromptProjection(
            runId: $projection->runId,
            upstreamHashes: $projection->upstreamHashes,
            sections: $projection->sections,
            qualityChecks: $projection->qualityChecks,
            renderedPromptText: $projection->renderedPromptText,
            renderedPromptHash: $projection->renderedPromptHash,
            providerSafe: $projection->providerSafe,
            promptProjectionHash: $finalHash,
        );
    }

    private function composeSections(
        PromptSections $original,
        FailureCapsule $capsule,
        int $attemptIndex,
        int $maxAttempts,
    ): PromptSections {
        $objective = trim($original->objective);
        $repairObjective = sprintf(
            '%s%sREPAIR ATTEMPT %d/%d for gate `%s` (failure_signature=%s)',
            $objective,
            $objective === '' ? '' : "\n\n",
            $attemptIndex,
            $maxAttempts,
            $capsule->gate,
            $capsule->failureSignature,
        );

        $operatingRules = $this->mergeUnique($original->operatingRules, self::REPAIR_OPERATING_RULES);
        $stopConditions = $this->mergeUnique($original->stopConditions, self::REPAIR_STOP_CONDITIONS);

        return new PromptSections(
            objective: $repairObjective,
            operatingRules: $operatingRules,
            miniSpecRef: $original->miniSpecRef,
            taskContractRef: $original->taskContractRef,
            contextRefs: $original->contextRefs,
            codeDiscoveryRef: $original->codeDiscoveryRef,
            // Hard invariant: allowed/forbidden files must NEVER change during
            // repair. We pass them straight through.
            allowedFiles: $original->allowedFiles,
            forbiddenFiles: $original->forbiddenFiles,
            expectedTests: $original->expectedTests,
            acceptanceCriteria: $original->acceptanceCriteria,
            stopConditions: $stopConditions,
            escalationConditions: $original->escalationConditions,
            outputContract: $original->outputContract,
            providerSafe: $original->providerSafe,
        );
    }

    private function renderPrompt(
        string $originalRendered,
        FailureCapsule $capsule,
        LightTaskContract $contract,
        int $attemptIndex,
        int $maxAttempts,
    ): string {
        $bullets = [
            '# Repair Capsule',
            sprintf('- attempt: %d/%d', $attemptIndex, $maxAttempts),
            sprintf('- run_id: %s', $capsule->runId),
            sprintf('- task_contract_hash: %s', $capsule->taskContractHash),
            sprintf('- failing_gate: %s', $capsule->gate),
            sprintf('- command: %s', $capsule->command ?? 'n/a'),
            sprintf('- exit_code: %s', $capsule->exitCode === null ? 'n/a' : (string) $capsule->exitCode),
            sprintf('- failing_test: %s', $capsule->failingTest ?? 'n/a'),
            sprintf('- failure_signature: %s', $capsule->failureSignature),
            sprintf('- previous_diff_hash: %s', $capsule->diffHash ?? 'none'),
            sprintf('- previous_changed_files: %s', $capsule->changedFiles === []
                ? '[]'
                : '['.implode(', ', $capsule->changedFiles).']'),
            sprintf('- provider_lock: %s/%s (fallback_allowed=%s)',
                $contract->providerLock->provider,
                $contract->providerLock->modelFamily,
                $contract->providerLock->fallbackAllowed ? 'true' : 'false',
            ),
            '',
            '# Primary Error (normalized)',
            $capsule->primaryErrorExcerpt === '' ? '(empty)' : $capsule->primaryErrorExcerpt,
            '',
            '# Stop Conditions',
        ];
        foreach (self::REPAIR_STOP_CONDITIONS as $cond) {
            $bullets[] = '- '.$cond;
        }
        $bullets[] = '';

        return rtrim($originalRendered)."\n\n".implode("\n", $bullets);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<string>
     */
    private function mergeUnique(array $a, array $b): array
    {
        $merged = array_values(array_unique([...$a, ...$b]));
        sort($merged, SORT_STRING);

        return $merged;
    }

    private function hashProjection(ProviderPromptProjection $projection): string
    {
        $payload = $projection->toCanonicalArray();
        // Mirror ProviderPromptProjection::hash() behaviour: exclude its own
        // hash field from the digest so we don't depend on the "pending"
        // placeholder we used to construct the skeleton.
        unset($payload['prompt_projection_hash']);

        return CanonicalHasher::hash(CanonicalJson::canonicalize($payload));
    }
}
