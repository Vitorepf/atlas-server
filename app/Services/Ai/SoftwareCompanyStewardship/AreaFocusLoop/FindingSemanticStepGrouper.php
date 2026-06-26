<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;
use Closure;

/**
 * SEMANTIC-STEP-GROUP DECOMPOSITION concern, extracted from the god-class
 * {@see FindingSlicePlannerService}.
 *
 * Owns the semantic step-group decomposition pipeline: semanticStepGroups
 * (the top-level entry), runtimeSemanticStepGroups, runtimeTargetMethod,
 * runtimeSurgicalAnchor, semanticContractFiles, semanticContractClassName,
 * capabilityPhrase, targetGroups and docsTargetGroup.
 *
 * Capabilities that STAY in the service are passed in as Closures.
 * Public constants are referenced via FindingSlicePlannerService::CONST_NAME.
 */
class FindingSemanticStepGrouper
{
    /**
     * @param  Closure(array<string,mixed>): bool  $isCanonicalAaeosRuntimeGap
     * @param  Closure(string, array<int,string>): string  $matchingTest
     * @param  Closure(string, array<int,string>): string  $expectedTestPath
     * @param  Closure(string): bool  $isForbidden
     * @param  Closure(string): bool  $isBroadPath
     * @param  Closure(string): bool  $isContractLikeSupportFile
     */
    public function __construct(
        private readonly Closure $isCanonicalAaeosRuntimeGap,
        private readonly Closure $matchingTest,
        private readonly Closure $expectedTestPath,
        private readonly Closure $isForbidden,
        private readonly Closure $isBroadPath,
        private readonly Closure $isContractLikeSupportFile,
    ) {}

    public function semanticStepGroups(array $normalized, array $sourceFiles, array $testFiles): array
    {
        $primary = $sourceFiles[0] ?? '';
        if ($primary === '') {
            return [];
        }
        $cap = $this->capabilityPhrase((string) ($normalized['title'] ?? ''));
        $title = (string) ($normalized['title'] ?? 'the finding');
        $test = ($this->matchingTest)($primary, $testFiles);
        $implementationFiles = $test !== '' ? [$primary, $test] : [$primary];
        if (($this->isCanonicalAaeosRuntimeGap)($normalized)) {
            return $this->runtimeSemanticStepGroups($title, $primary, $cap, $implementationFiles);
        }

        $contractFiles = $this->semanticContractFiles($primary, $cap);
        if ($contractFiles === []) {
            $contractFiles = $implementationFiles;
        }

        $steps = [
            [
                'order' => 1,
                'kind' => 'contract',
                'shape' => FindingSlicePlannerService::SHAPE_SERVICE_AND_TEST,
                'objective' => sprintf(
                    'STEP 1 of 3 of the "%s" roadmap — do NOT implement the whole feature. Create or update ONLY the minimal PSR-4 data contract for "%s" in %s. Do NOT define the contract class inside %s. Then wire exactly one default/entry method in %s that consumes or returns that contract. Add focused tests for the contract default and the runtime wiring. This step is invalid if the diff only changes *Contract.php or reflection-only tests; changed_files must include %s and a focused runtime test. No real transformation logic yet.',
                    $title,
                    $cap,
                    $contractFiles[0] ?? $primary,
                    $primary,
                    $primary,
                    $primary,
                ),
            ],
            [
                'order' => 2,
                'kind' => 'skeleton',
                'depends_on' => 1,
                'shape' => FindingSlicePlannerService::SHAPE_SERVICE_AND_TEST,
                'objective' => sprintf(
                    'STEP 2 of 3 of the "%s" roadmap — depends on step 1, do NOT implement the full behavior. In %s add ONLY one entry method for "%s" that validates its input and returns the empty/default contract from step 1, plus a unit test asserting the empty-input path returns the default. No real transformation logic yet.',
                    $title, $primary, $cap,
                ),
            ],
            [
                'order' => 3,
                'kind' => 'first_behavior',
                'depends_on' => 2,
                'shape' => FindingSlicePlannerService::SHAPE_SERVICE_AND_TEST,
                'objective' => sprintf(
                    'STEP 3 of 3 of the "%s" roadmap — depends on step 2, implement ONLY the first rule. In %s make the entry method handle the single simplest "%s" case so one concrete well-defined input produces one concrete output, plus a unit test for exactly that case. Leave every remaining rule for future steps.',
                    $title, $primary, $cap,
                ),
            ],
        ];

        $groups = [];
        foreach ($steps as $step) {
            $files = (int) $step['order'] === 1
                ? AreaFocusStringListNormalizer::uniqueMergedStringValues($contractFiles, $implementationFiles)
                : $implementationFiles;

            $groups[] = [
                'files' => $files,
                'docs' => false,
                'step' => $step,
            ];
        }

        return $groups;
    }

    public function runtimeSemanticStepGroups(string $title, string $primary, string $capability, array $implementationFiles): array
    {
        $steps = [
            [
                'order' => 1,
                'kind' => 'runtime_signal',
                'shape' => FindingSlicePlannerService::SHAPE_SERVICE_AND_TEST,
                'target_method' => $this->runtimeTargetMethod('runtime_signal', $capability),
                'target_symbol' => $this->runtimeTargetMethod('runtime_signal', $capability),
                'surgical_anchor' => $this->runtimeSurgicalAnchor($primary, 'runtime_signal', $capability),
                'mutation_anchor' => $this->runtimeSurgicalAnchor($primary, 'runtime_signal', $capability),
                'objective' => sprintf(
                    'STEP 1 of 3 of the "%s" roadmap — update ONLY %s and its focused test to add the smallest runtime signal method %s() for "%s". Do not create new PHP files, *Contract.php files, scaffold-only classes or reflection-only tests. This step is invalid unless changed_files include %s and the focused runtime test.',
                    $title,
                    $primary,
                    $this->runtimeTargetMethod('runtime_signal', $capability),
                    $capability,
                    $primary,
                ),
            ],
            [
                'order' => 2,
                'kind' => 'runtime_wiring',
                'depends_on' => 1,
                'shape' => FindingSlicePlannerService::SHAPE_SERVICE_AND_TEST,
                'target_method' => $this->runtimeTargetMethod('runtime_wiring', $capability),
                'target_symbol' => $this->runtimeTargetMethod('runtime_wiring', $capability),
                'surgical_anchor' => $this->runtimeSurgicalAnchor($primary, 'runtime_wiring', $capability),
                'mutation_anchor' => $this->runtimeSurgicalAnchor($primary, 'runtime_wiring', $capability),
                'objective' => sprintf(
                    'STEP 2 of 3 of the "%s" roadmap — wire the smallest consumer method %s() for "%s" inside %s and prove it with the same focused test. Do not create new PHP files, *Contract.php files or standalone scaffold; leave broader behavior for a later packet.',
                    $title,
                    $this->runtimeTargetMethod('runtime_wiring', $capability),
                    $capability,
                    $primary,
                ),
            ],
            [
                'order' => 3,
                'kind' => 'first_behavior',
                'depends_on' => 2,
                'shape' => FindingSlicePlannerService::SHAPE_SERVICE_AND_TEST,
                'target_method' => $this->runtimeTargetMethod('first_behavior', $capability),
                'target_symbol' => $this->runtimeTargetMethod('first_behavior', $capability),
                'surgical_anchor' => $this->runtimeSurgicalAnchor($primary, 'first_behavior', $capability),
                'mutation_anchor' => $this->runtimeSurgicalAnchor($primary, 'first_behavior', $capability),
                'objective' => sprintf(
                    'STEP 3 of 3 of the "%s" roadmap — implement one concrete "%s" behavior in method %s() in %s and assert exactly that case in the focused test. Do not create new PHP files, *Contract.php files or docs-only progress.',
                    $title,
                    $capability,
                    $this->runtimeTargetMethod('first_behavior', $capability),
                    $primary,
                ),
            ],
        ];

        return array_map(static fn (array $step): array => [
            'files' => $implementationFiles,
            'docs' => false,
            'step' => $step,
        ], $steps);
    }

    public function runtimeTargetMethod(string $stepKind, string $capability): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $capability) ?: [];
        $words = array_values(array_filter($words, static fn (string $word): bool => $word !== ''));
        if ($words === []) {
            $words = ['runtime', 'capability'];
        }

        $base = lcfirst(implode('', array_map(
            static fn (string $word): string => ucfirst(strtolower($word)),
            $words,
        )));

        $suffix = match ($stepKind) {
            'runtime_signal' => 'Signal',
            'runtime_wiring' => 'Wiring',
            'first_behavior' => 'Behavior',
            default => 'RuntimeStep',
        };

        return $base.$suffix;
    }

    public function runtimeSurgicalAnchor(string $primary, string $stepKind, string $capability): string
    {
        return sprintf(
            'file:%s; target_method:%s; semantic_step:%s; capability:%s; constraint:modify_existing_runtime_surface_and_focused_test_only',
            $primary,
            $this->runtimeTargetMethod($stepKind, $capability),
            $stepKind,
            $capability,
        );
    }

    public function semanticContractFiles(string $primary, string $capability): array
    {
        if (! str_starts_with($primary, 'app/') || ! str_ends_with($primary, '.php')) {
            return [];
        }

        $class = $this->semanticContractClassName($capability);
        if ($class === '') {
            return [];
        }

        $source = rtrim(dirname($primary), '.').'/'.$class.'.php';
        $test = ($this->expectedTestPath)($class.'Test.php', [$source]);

        return array_values(array_filter(array_unique([$source, $test]), fn (string $file): bool => ! ($this->isBroadPath)($file) && ! ($this->isForbidden)($file)));
    }

    public function semanticContractClassName(string $capability): string
    {
        $normalized = trim((string) preg_replace('/[^A-Za-z0-9]+/', ' ', str_replace(['-', '_'], ' ', $capability)));
        if ($normalized === '') {
            return '';
        }

        $words = array_values(array_filter(explode(' ', $normalized), static fn (string $word): bool => $word !== ''));
        if ($words === []) {
            return '';
        }

        $last = count($words) - 1;
        $lowerLast = strtolower($words[$last]);
        if (str_ends_with($lowerLast, 'ies') && strlen($lowerLast) > 3) {
            $words[$last] = substr($words[$last], 0, -3).'y';
        } elseif (str_ends_with($lowerLast, 's') && ! str_ends_with($lowerLast, 'ss') && strlen($lowerLast) > 3) {
            $words[$last] = substr($words[$last], 0, -1);
        }

        $class = implode('', array_map(
            static fn (string $word): string => ucfirst(strtolower($word)),
            $words,
        ));

        if (! preg_match('/(Contract|Slice|Packet|Plan|Spec|Schema)$/', $class)) {
            $class .= 'Contract';
        }

        return $class;
    }

    public function capabilityPhrase(string $title): string
    {
        $t = trim($title);
        $t = (string) preg_replace('/^(introduce|implement|add|wire|materialize|build|create|establish|make|enable|close|strengthen|route|unify|expose|give|generate|run|dispatch|measure|evaluate|let|require|consolidate|attach|surface|harden)\s+/i', '', $t);
        $parts = preg_split('/\s+(for|into|in|of|to|across|before|after|on|with|so|that|when|as)\s+/i', $t, 2);
        $t = trim($parts[0] ?? $t);

        return $t !== '' ? $t : 'this capability';
    }

    public function targetGroups(array $sourceFiles, array $testFiles): array
    {
        $remainingTests = $testFiles;
        $groups = [];
        $runtimeSources = [];
        $supportSources = [];

        foreach ($sourceFiles as $source) {
            if (($this->isContractLikeSupportFile)($source)) {
                $supportSources[] = $source;

                continue;
            }

            $runtimeSources[] = $source;
        }

        if ($runtimeSources === []) {
            $runtimeSources = $sourceFiles;
            $supportSources = [];
        }

        foreach ($runtimeSources as $source) {
            $match = ($this->matchingTest)($source, $remainingTests);
            $files = [$source];
            if ($match !== '') {
                $files[] = $match;
                $remainingTests = array_values(array_filter($remainingTests, static fn (string $t): bool => $t !== $match));
            }
            $groups[] = ['files' => array_values(array_slice(array_unique($files), 0, FindingSlicePlannerService::MAX_FILES_PER_SLICE)), 'docs' => false];
        }

        if ($groups !== [] && $supportSources !== []) {
            $supportFiles = [];
            foreach ($supportSources as $source) {
                $supportFiles[] = $source;
                $match = ($this->matchingTest)($source, $remainingTests);
                if ($match !== '') {
                    $supportFiles[] = $match;
                    $remainingTests = array_values(array_filter($remainingTests, static fn (string $t): bool => $t !== $match));
                }
            }

            $merged = AreaFocusStringListNormalizer::uniqueMergedStringValues($groups[0]['files'], $supportFiles);
            if (count($merged) <= FindingSlicePlannerService::MAX_FILES_PER_SLICE) {
                $groups[0]['files'] = $merged;
            }
        }

        // Test-only finding (missing_test where affected_files are the tests).
        if ($sourceFiles === []) {
            foreach ($remainingTests as $test) {
                $groups[] = ['files' => [$test], 'docs' => false];
            }
        } elseif ($remainingTests !== []) {
            // Unmatched tests attach to the first source slice (kept bounded).
            $first = $groups[0]['files'];
            $groups[0]['files'] = array_values(array_slice(array_unique(array_merge($first, $remainingTests)), 0, FindingSlicePlannerService::MAX_FILES_PER_SLICE));
        }

        return array_values(array_slice($groups, 0, FindingSlicePlannerService::MAX_SLICES));
    }

    public function docsTargetGroup(array $docFiles): array
    {
        return ['files' => array_values(array_slice($docFiles, 0, FindingSlicePlannerService::MAX_FILES_PER_SLICE)), 'docs' => true];
    }
}
