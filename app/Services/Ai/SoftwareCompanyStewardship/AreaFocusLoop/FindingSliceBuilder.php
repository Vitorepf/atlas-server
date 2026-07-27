<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use Closure;

/**
 * SLICE-BUILDING concern, extracted from the god-class
 * {@see FindingSlicePlannerService}.
 *
 * Owns buildSlices (the top-level slice assembler), buildSlice (the per-group
 * slice factory), and the per-slice helpers: sliceObjective, successCondition,
 * evidenceObligations, expectedDiffShape, boundedAllowedFiles,
 * sliceValidationTests, validationCommands, phpunitValidationCommand,
 * worktreeSafeValidationCommand.
 *
 * Capabilities that STAY in the service are passed in as Closures — the SAME
 * closure-binding pattern used by AtlasLoopRefillerSupplyLaneCoordinator.
 * Public constants are referenced via FindingSlicePlannerService::CONST_NAME;
 * the private FORBIDDEN_FILES constant is passed as a constructor arg.
 */
class FindingSliceBuilder
{
    /**
     * @param  list<string>  $forbiddenFiles
     * @param  Closure(array<string>, bool): bool  $hasFocusedValidation
     * @param  Closure(string): string  $riskLevel
     * @param  Closure(string, string, string): string  $mergePolicy
     * @param  Closure(string, bool): string  $resolveOwner
     * @param  Closure(string, array<string,mixed>): bool  $ownerRuntimeReady
     * @param  Closure(string): ?array<string,mixed>  $providerFit
     * @param  Closure(string, int, array<int,string>): string  $sliceId
     * @param  Closure(array<int,string>): array<string,mixed>  $blocked
     * @param  Closure(string): bool  $isSourceFile
     * @param  Closure(string): bool  $isTestFile
     * @param  Closure(string): bool  $isDocFile
     * @param  Closure(string): bool  $isBroadPath
     * @param  Closure(string): bool  $isForbidden
     * @param  Closure(string): string  $testBasenameFor
     * @param  Closure(string, array<int,string>): string  $expectedTestPath
     */
    public function __construct(
        private readonly array $forbiddenFiles,
        private readonly Closure $hasFocusedValidation,
        private readonly Closure $riskLevel,
        private readonly Closure $mergePolicy,
        private readonly Closure $resolveOwner,
        private readonly Closure $ownerRuntimeReady,
        private readonly Closure $providerFit,
        private readonly Closure $sliceId,
        private readonly Closure $blocked,
        private readonly Closure $isSourceFile,
        private readonly Closure $isTestFile,
        private readonly Closure $isDocFile,
        private readonly Closure $isBroadPath,
        private readonly Closure $isForbidden,
        private readonly Closure $testBasenameFor,
        private readonly Closure $expectedTestPath,
    ) {}

    public function buildSlices(array $groups, array $normalized, array $context, bool $docsOnlyFinding): array
    {
        $slices = [];
        $blockers = [];
        $sequence = 0;

        foreach ($groups as $group) {
            $sequence++;
            $built = $this->buildSlice($group, $normalized, $context, $sequence, $docsOnlyFinding);
            if ($built['ok']) {
                $slices[] = $built['slice'];

                continue;
            }
            foreach ($built['blockers'] as $blocker) {
                $blockers[] = $blocker;
            }
        }

        if ($slices === []) {
            return ($this->blocked)($blockers !== [] ? $blockers : [FindingSlicePlannerService::BLOCKER_ALLOWED_FILES_TOO_BROAD]);
        }

        // Re-sequence kept slices so sequence numbers stay dense and ordered.
        foreach ($slices as $index => $slice) {
            $slices[$index]['sequence'] = $index + 1;
            $slices[$index]['slice_id'] = ($this->sliceId)($normalized['finding_hash'], $index + 1, $slice['allowed_files']);
        }

        return ['status' => FindingSlicePlannerService::STATUS_SLICED, 'blockers' => [], 'slices' => $slices];
    }

    public function buildSlice(array $group, array $normalized, array $context, int $sequence, bool $docsOnlyFinding): array
    {
        $allowedFiles = $this->boundedAllowedFiles($group['files']);
        if ($allowedFiles === []) {
            return ['ok' => false, 'slice' => [], 'blockers' => [FindingSlicePlannerService::BLOCKER_ALLOWED_FILES_TOO_BROAD]];
        }

        // AP-806 semantic step: when present, the step carries its own narrowed
        // objective + shape so the slice is a small ordered sub-task, not the
        // whole finding restated.
        $step = is_array($group['step'] ?? null) ? $group['step'] : null;
        $isDocs = $group['docs'];
        $sliceTests = $this->sliceValidationTests($allowedFiles, $normalized);
        $validationCommands = $this->validationCommands($sliceTests, $isDocs, $context);
        if (! ($this->hasFocusedValidation)($validationCommands, $isDocs)) {
            return ['ok' => false, 'slice' => [], 'blockers' => [FindingSlicePlannerService::BLOCKER_VALIDATION_COMMAND_MISSING]];
        }

        $owner = ($this->resolveOwner)($normalized['owner_candidate'], $isDocs);
        if ($owner === '') {
            return ['ok' => false, 'slice' => [], 'blockers' => [FindingSlicePlannerService::BLOCKER_PROVIDER_FIT_UNKNOWN]];
        }
        if (! ($this->ownerRuntimeReady)($owner, $context)) {
            return ['ok' => false, 'slice' => [], 'blockers' => [FindingSlicePlannerService::BLOCKER_OWNER_RUNTIME_NOT_READY]];
        }

        $providerFit = ($this->providerFit)($owner);
        if ($providerFit === null) {
            return ['ok' => false, 'slice' => [], 'blockers' => [FindingSlicePlannerService::BLOCKER_PROVIDER_FIT_UNKNOWN]];
        }

        $evidenceObligations = $this->evidenceObligations($normalized, $allowedFiles, $sliceTests, $isDocs);
        if ($evidenceObligations === []) {
            return ['ok' => false, 'slice' => [], 'blockers' => [FindingSlicePlannerService::BLOCKER_EVIDENCE_OBLIGATIONS_MISSING]];
        }

        $shape = $step !== null ? (string) $step['shape'] : $this->expectedDiffShape($allowedFiles, $isDocs);
        $riskLevel = ($this->riskLevel)($normalized['severity']);
        $mergePolicy = ($this->mergePolicy)($owner, $shape, $riskLevel);

        $slice = [
            'schema_version' => FindingSlicePlannerService::SLICE_SCHEMA,
            'slice_id' => ($this->sliceId)($normalized['finding_hash'], $sequence, $allowedFiles),
            'sequence' => $sequence,
            'owner' => $owner,
            'risk_level' => $riskLevel,
            'objective' => $step !== null ? (string) $step['objective'] : $this->sliceObjective($normalized, $allowedFiles, $isDocs),
            'decomposition' => $step !== null ? 'semantic_step:'.(string) $step['kind'] : 'file_group',
            'depends_on_sequence' => $step['depends_on'] ?? null,
            'target_symbol' => $step !== null ? trim((string) ($step['target_symbol'] ?? '')) : '',
            'target_method' => $step !== null ? trim((string) ($step['target_method'] ?? '')) : '',
            'method_anchor' => $step !== null ? trim((string) ($step['target_method'] ?? '')) : '',
            'surgical_anchor' => $step !== null ? trim((string) ($step['surgical_anchor'] ?? '')) : '',
            'mutation_anchor' => $step !== null ? trim((string) ($step['mutation_anchor'] ?? '')) : '',
            'allowed_files' => $allowedFiles,
            'forbidden_files' => $this->forbiddenFiles,
            'expected_diff_shape' => $shape,
            'validation_commands' => $validationCommands,
            'evidence_obligations' => $evidenceObligations,
            'provider_fit' => $providerFit,
            'max_runtime_seconds' => FindingSlicePlannerService::DEFAULT_MAX_RUNTIME_SECONDS,
            'retry_policy' => [
                'max_retries' => 1,
                'transient_blockers' => ['provider_timeout', 'owner_runtime_routing_not_executable'],
                'permanent_blockers' => ['provider_scope_violation', 'validation_failed'],
            ],
            'merge_policy' => $mergePolicy,
            'success_condition' => $this->successCondition($sliceTests, $shape, $allowedFiles),
        ];

        return ['ok' => true, 'slice' => $slice, 'blockers' => []];
    }

    public function sliceObjective(array $normalized, array $allowedFiles, bool $isDocs): string
    {
        $target = $allowedFiles[0] ?? 'the selected scope';
        $title = $normalized['title'] !== '' ? $normalized['title'] : 'the selected finding';
        if ($isDocs) {
            return sprintf('Correct canonical docs for "%s", scoped to %s, to unblock runtime/certification.', $title, $target);
        }

        return sprintf(
            'Implement the bounded runtime/test slice of "%s" by changing %s and its focused test within allowed_files. Do not create contract-only, scaffold-only, docs-only or reflection-only progress; the diff must either change runtime behavior or add a focused runtime assertion that proves this factory improvement.',
            $title,
            $target,
        );
    }

    public function successCondition(array $tests, string $shape, array $allowedFiles): string
    {
        if ($tests !== []) {
            return sprintf('Diff stays within allowed_files (%d) and `./vendor/bin/phpunit --configuration=phpunit.xml %s` passes.', count($allowedFiles), $tests[0]);
        }
        if ($shape === FindingSlicePlannerService::SHAPE_DOCS_ONLY) {
            return sprintf('Diff stays within allowed_files (%d) and docs-health passes.', count($allowedFiles));
        }

        return sprintf('Diff stays within allowed_files (%d) and focused validation passes.', count($allowedFiles));
    }

    public function evidenceObligations(array $normalized, array $allowedFiles, array $tests, bool $isDocs): array
    {
        // Completion cannot be certified without a stable finding identity that
        // ties the slice result back to the source finding.
        if ($normalized['finding_id'] === '' && $normalized['finding_hash'] === '') {
            return [];
        }

        $obligations = [];
        if ($normalized['finding_id'] !== '') {
            $obligations[] = 'finding_id:'.$normalized['finding_id'];
        } else {
            $obligations[] = 'finding_hash:'.$normalized['finding_hash'];
        }
        if ($normalized['spec_candidate_id'] !== '') {
            $obligations[] = 'spec_seed:'.$normalized['spec_candidate_id'];
        }
        foreach ($tests as $test) {
            $obligations[] = 'test_pass:'.$test;
        }
        if ($isDocs) {
            $obligations[] = 'docs_health_pass';
        }
        $obligations[] = 'git_diff_within_allowed_files';
        $obligations[] = 'inbox_item_emitted_before_merge';
        $obligations[] = 'decision_receipt_recorded';

        return AreaFocusStringListNormalizer::uniqueStringValues($obligations);
    }

    public function expectedDiffShape(array $allowedFiles, bool $isDocs): string
    {
        $hasSource = false;
        $hasTest = false;
        $hasDoc = false;
        foreach ($allowedFiles as $file) {
            if (($this->isTestFile)($file)) {
                $hasTest = true;
            } elseif (($this->isDocFile)($file)) {
                $hasDoc = true;
            } elseif (($this->isSourceFile)($file)) {
                $hasSource = true;
            }
        }

        if ($isDocs || ($hasDoc && ! $hasSource && ! $hasTest)) {
            return FindingSlicePlannerService::SHAPE_DOCS_ONLY;
        }
        if ($hasDoc && $hasTest && ! $hasSource) {
            return FindingSlicePlannerService::SHAPE_DOCS_AND_TEST;
        }
        if ($hasSource && $hasTest) {
            return FindingSlicePlannerService::SHAPE_SERVICE_AND_TEST;
        }
        if ($hasTest && ! $hasSource) {
            return FindingSlicePlannerService::SHAPE_TEST_ONLY;
        }

        return FindingSlicePlannerService::SHAPE_SERVICE_ONLY;
    }

    public function boundedAllowedFiles(array $files): array
    {
        $bounded = [];
        foreach ($files as $file) {
            if (($this->isBroadPath)($file) || ($this->isForbidden)($file)) {
                continue;
            }
            $bounded[] = $file;
        }
        $bounded = AreaFocusStringListNormalizer::uniqueStringValues($bounded);
        if (count($bounded) > FindingSlicePlannerService::MAX_FILES_PER_SLICE) {
            return [];
        }

        return $bounded;
    }

    public function sliceValidationTests(array $allowedFiles, array $normalized): array
    {
        $tests = [];
        foreach ($allowedFiles as $file) {
            if (($this->isTestFile)($file)) {
                $tests[] = $file;
            }
        }
        foreach ($normalized['tests_required'] as $test) {
            $candidate = AreaFocusPathNormalizer::repoRelativeNoWhitespace($test);
            if (($this->isTestFile)($candidate)) {
                $tests[] = $candidate;
            }
        }
        // Derive a test for each source file in scope when none is explicit.
        if ($tests === []) {
            foreach ($allowedFiles as $file) {
                if (($this->isSourceFile)($file)) {
                    $derived = ($this->expectedTestPath)(($this->testBasenameFor)($file), [$file]);
                    if ($derived !== '' && in_array($derived, $allowedFiles, true)) {
                        $tests[] = $derived;
                    }
                }
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($tests);
    }

    public function validationCommands(array $tests, bool $isDocs, array $context): array
    {
        $commands = [];
        foreach (AreaFocusStringListNormalizer::trimmedStrings($context['validation_commands'] ?? []) as $command) {
            $commands[] = $this->worktreeSafeValidationCommand($command);
        }
        foreach ($tests as $test) {
            $commands[] = $this->phpunitValidationCommand($test);
        }
        if ($isDocs) {
            $commands[] = 'php artisan atlas:engineering:knowledge docs-health --json';
        }
        $commands[] = 'git diff --check';

        return array_values(array_slice(array_unique($commands), 0, 5));
    }

    public function phpunitValidationCommand(string $test): string
    {
        return './vendor/bin/phpunit --configuration=phpunit.xml '.$test;
    }

    public function worktreeSafeValidationCommand(string $command): string
    {
        $command = trim($command);
        if (preg_match('/^php\s+artisan\s+test(?:\s+(.*))?$/', $command, $matches) === 1) {
            $args = trim((string) ($matches[1] ?? ''));

            return './vendor/bin/phpunit --configuration=phpunit.xml'.($args !== '' ? ' '.$args : '');
        }

        return $command;
    }
}
