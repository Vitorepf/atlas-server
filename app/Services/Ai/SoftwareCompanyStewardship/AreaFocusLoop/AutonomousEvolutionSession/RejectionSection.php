<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSession;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusPathNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\ZeroProviderPreflightGate;

/**
 * Candidate-rejection leaf + candidate-scope section, extracted VERBATIM from
 * AutonomousEvolutionSessionService by the GOD-DEBULK split. Holds the factory-max
 * starvation / terminal-unlock rejection-reason branches, repair-learning /
 * benchmark / topology-leak / high-risk / factory-scope / existing-source
 * candidate predicates, forge-authority liveness, allowed-file derivation and
 * expected-test-path mapping. The rejection HEAD (candidateRejectionReason) and the
 * AP-806 honest-stop stay on the parent, which reaches these leaves through thin
 * delegators; back-calls to shared parent predicates/services go through
 * {@see AutonomousEvolutionSessionService}. Taxonomy classes are referenced qualified.
 */
final class RejectionSection
{
    public function __construct(
        private readonly AutonomousEvolutionSessionService $parent,
    ) {}

    /**
     * AP-790 starvation recovery must stay executable even when the same state
     * hash was review-locked, quarantined or previously attempted. Only hard
     * factory-max safety checks apply so empty selection becomes one bounded
     * owner-runtime cycle instead of repeating no_candidate_with_allowed_files.
     *
     * @param  list<string>  $allowedFiles
     */
    public function factoryMaxStarvationRecoveryRejectionReason(
        array $finding,
        array $allowedFiles,
        string $scopeProfile,
    ): string {
        if ($scopeProfile !== AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX) {
            return '';
        }
        if (! $this->parent->touchesFactoryRuntime($allowedFiles)) {
            return 'factory_max_requires_direct_factory_runtime_or_test_impact';
        }

        return '';
    }

    /**
     * AP-790 terminal backlog unlock must stay executable through review locks so
     * terminal-locked starvation can convert into one bounded owner-runtime cycle.
     *
     * @param  list<string>  $allowedFiles
     */
    public function factoryMaxTerminalBacklogUnlockRejectionReason(
        array $finding,
        array $allowedFiles,
        string $scopeProfile,
    ): string {
        if ($scopeProfile !== AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX) {
            return '';
        }
        if (! $this->parent->touchesFactoryRuntime($allowedFiles)) {
            return 'factory_max_requires_direct_factory_runtime_or_test_impact';
        }

        return '';
    }

    /**
     * If a broad parent class has repeatedly produced non-retryable failures,
     * reject the parent before provider spend so AP-806 can re-slice it into a
     * bounded Self-Construction packet. This mirrors the zero-provider preflight
     * signal but moves it into selection, avoiding cheap-but-useless blocked
     * cycles when there is still executable packet runway.
     *
     * @param  array<string,mixed>  $finding
     */
    public function repairLearningRejectsCandidateBeforeProvider(string $areaId, string $focus, array $finding): bool
    {
        if ((string) ($finding['origin_type'] ?? '') === 'self_construction_admission_packet') {
            return false;
        }

        $hint = $this->parent->repairLearning()->repairHintForTaskClass(
            $areaId,
            $focus,
            $this->parent->repairLearningTaskClass($finding),
        );

        return is_array($hint)
            && ZeroProviderPreflightGate::repairLearningShowsNonRetryableFailurePattern($hint);
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     */
    public function benchmarkOrRivalsCandidate(array $finding, array $allowedFiles): bool
    {
        $haystack = strtolower(implode(' ', array_merge($allowedFiles, [
            (string) ($finding['finding_id'] ?? ''),
            (string) ($finding['title'] ?? ''),
            (string) ($finding['detail'] ?? ''),
            (string) ($finding['why_it_matters'] ?? ''),
        ])));

        return str_contains($haystack, 'rivals') || str_contains($haystack, 'benchmark');
    }

    /**
     * Atlas Dev's provider prompt quality gate intentionally blocks Forge/Council
     * instructions. Rejecting these candidates before owner execution keeps the
     * 24h loop from spending a full branch/sandbox cycle on a prompt projection
     * that cannot be sent.
     *
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     */
    public function atlasDevForbiddenTopologyLeakCandidate(array $finding, array $allowedFiles): bool
    {
        $haystack = strtolower(implode(' ', array_merge($allowedFiles, [
            (string) ($finding['finding_id'] ?? ''),
            (string) ($finding['title'] ?? ''),
            (string) ($finding['detail'] ?? ''),
            (string) ($finding['why_it_matters'] ?? ''),
            (string) ($finding['proposed_next_action'] ?? ''),
            (string) data_get($finding, 'spec_seed.title', ''),
            (string) data_get($finding, 'spec_seed.rationale', ''),
        ])));

        return str_contains($haystack, 'forge') || str_contains($haystack, 'council');
    }

    /** @param array<string,mixed> $finding */
    public function highRiskDeepFinding(array $finding): bool
    {
        if ($this->parent->isStructuralRuntimeGapFinding($finding)) {
            return false;
        }

        $origin = (string) ($finding['origin'] ?? '');
        $originType = (string) ($finding['origin_type'] ?? '');
        if ($origin === 'factory_max_seed' || str_starts_with($originType, 'ap')) {
            return false;
        }

        return strtolower((string) ($finding['severity'] ?? '')) === 'high';
    }

    /**
     * Mirrors AP-774's narrow factory-scoped auto-merge boundary so AP-790 does
     * not select work it cannot merge without human review while Forge authority
     * is unavailable.
     *
     * @param  list<string>  $allowedFiles
     */
    public function factoryScopedAutonomousPatchCandidate(array $allowedFiles): bool
    {
        if ($allowedFiles === []) {
            return false;
        }

        foreach ($allowedFiles as $file) {
            if (str_starts_with($file, 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/')) {
                continue;
            }
            if (str_starts_with($file, 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/')) {
                continue;
            }
            if (str_starts_with($file, 'app/Services/Ai/SoftwareCompanyStewardship/ProductMode/')) {
                continue;
            }
            if (str_starts_with($file, 'tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/')) {
                continue;
            }

            return false;
        }

        return true;
    }

    /** @param array<string,mixed> $finding */
    public function hasExistingImplementationSource(array $finding): bool
    {
        $files = AreaFocusStringListNormalizer::preserveNonBlankStrings($finding['affected_files'] ?? []);
        if ((string) ($finding['origin_type'] ?? '') === 'self_construction_admission_packet') {
            $packet = is_array($finding['self_construction_packet'] ?? null) ? $finding['self_construction_packet'] : [];
            $files = AreaFocusStringListNormalizer::uniqueMergedStringValues(
                $files,
                AreaFocusStringListNormalizer::preserveNonBlankStrings($packet['parent_affected_files'] ?? []),
            );
        }

        foreach ($files as $file) {
            if (! str_starts_with($file, 'app/')) {
                continue;
            }
            $absolute = function_exists('base_path') ? base_path($file) : $file;
            if (is_file($absolute)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $forgeInputs */
    public function hasLiveForgeAuthority(array $forgeInputs): bool
    {
        $obra = trim((string) ($forgeInputs['forge_obra'] ?? $forgeInputs['obra_id'] ?? ''));
        $topology = is_array($forgeInputs['forge_live_topology'] ?? null) ? $forgeInputs['forge_live_topology'] : [];
        $decision = is_array($forgeInputs['forge_live_decision'] ?? null) ? $forgeInputs['forge_live_decision'] : [];

        return $obra !== ''
            && strtolower((string) ($topology['status'] ?? '')) === 'live'
            && $decision !== [];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return list<string>
     */
    public function allowedFiles(array $finding): array
    {
        $files = array_merge(
            AreaFocusStringListNormalizer::preserveNonBlankStrings($finding['affected_files'] ?? []),
            AreaFocusStringListNormalizer::preserveNonBlankStrings($finding['affected_docs'] ?? []),
            AreaFocusStringListNormalizer::preserveNonBlankStrings(data_get($finding, 'spec_seed.tests_required', [])),
        );
        foreach ((array) ($finding['evidence_refs'] ?? []) as $ref) {
            if (! is_string($ref)) {
                continue;
            }
            if (str_starts_with($ref, 'expected_test:')) {
                $basename = trim(substr($ref, strlen('expected_test:')));
                $testPath = $this->expectedTestPath($basename, AreaFocusStringListNormalizer::preserveNonBlankStrings($finding['affected_files'] ?? []));
                if ($testPath !== '') {
                    $files[] = $testPath;
                }
            }
            if (preg_match_all('/(?:tests|app|docs|config|routes|database)\/[A-Za-z0-9_.,:\/\\\\ -]+?\.(?:php|md|ts|tsx|json|yml|yaml)/', $ref, $matches)) {
                foreach ($matches[0] as $match) {
                    $files[] = trim($match, " \t\n\r\0\x0B,.:");
                }
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues(array_filter(array_map(
            fn (string $file): string => AreaFocusPathNormalizer::repoRelativeNoWhitespace($file),
            $files,
        ), fn (string $file): bool => $file !== '' && ! $this->parent->forbidden($file)));
    }

    /**
     * @param  list<string>  $affectedFiles
     */
    public function expectedTestPath(string $basename, array $affectedFiles): string
    {
        if ($basename === '') {
            return '';
        }
        $source = $affectedFiles[0] ?? '';
        if (str_starts_with($source, 'app/Services/Ai/NightShift/')) {
            return 'tests/Unit/Ai/NightShift/'.$basename;
        }
        if (str_starts_with($source, 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/')) {
            return 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/'.$basename;
        }
        if (str_starts_with($source, 'app/Services/Ai/')) {
            $tail = substr($source, strlen('app/Services/Ai/'));
            $dir = trim(dirname($tail), '.');

            return 'tests/Unit/Ai/'.($dir !== '' ? $dir.'/' : '').$basename;
        }

        return 'tests/Unit/'.$basename;
    }
}
