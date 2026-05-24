<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\PromptProjection;

use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptQualityChecker;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptSectionsMapper;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\PromptSections;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use PHPUnit\Framework\TestCase;

final class PromptQualityCheckerTest extends TestCase
{
    use PromptProjectionFixtures;

    private function checker(): PromptQualityChecker
    {
        return new PromptQualityChecker;
    }

    private function baselineSections(): PromptSections
    {
        return (new PromptSectionsMapper)->map(
            envelope: $this->envelope(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );
    }

    private function baselineTaskContract(): LightTaskContract
    {
        return $this->taskContract();
    }

    public function test_baseline_passes_all_six_checks(): void
    {
        $sections = $this->baselineSections();
        $result = $this->checker()->check(
            sections: $sections,
            renderedPromptText: "rendered prompt with no contraband\n",
            taskContract: $this->baselineTaskContract(),
        );

        $this->assertTrue($result->allPassed(), implode(',', $result->failedChecks()));
    }

    public function test_check_fails_when_objective_is_empty(): void
    {
        $base = $this->baselineSections();
        $sections = new PromptSections(
            objective: '',
            operatingRules: $base->operatingRules,
            miniSpecRef: $base->miniSpecRef,
            taskContractRef: $base->taskContractRef,
            contextRefs: $base->contextRefs,
            codeDiscoveryRef: $base->codeDiscoveryRef,
            allowedFiles: $base->allowedFiles,
            forbiddenFiles: $base->forbiddenFiles,
            expectedTests: $base->expectedTests,
            acceptanceCriteria: $base->acceptanceCriteria,
            stopConditions: $base->stopConditions,
            escalationConditions: $base->escalationConditions,
            outputContract: $base->outputContract,
        );

        $result = $this->checker()->check(
            sections: $sections,
            renderedPromptText: "rendered prompt\n",
            taskContract: $this->baselineTaskContract(),
        );

        $this->assertFalse($result->noMissingRequiredSections);
    }

    public function test_check_fails_when_allowed_files_empty_under_write_contract(): void
    {
        $base = $this->baselineSections();
        $sections = new PromptSections(
            objective: $base->objective,
            operatingRules: $base->operatingRules,
            miniSpecRef: $base->miniSpecRef,
            taskContractRef: $base->taskContractRef,
            contextRefs: $base->contextRefs,
            codeDiscoveryRef: $base->codeDiscoveryRef,
            allowedFiles: [],
            forbiddenFiles: $base->forbiddenFiles,
            expectedTests: $base->expectedTests,
            acceptanceCriteria: $base->acceptanceCriteria,
            stopConditions: $base->stopConditions,
            escalationConditions: $base->escalationConditions,
            outputContract: $base->outputContract,
        );

        $result = $this->checker()->check(
            sections: $sections,
            renderedPromptText: "rendered prompt\n",
            taskContract: $this->baselineTaskContract(),
        );

        $this->assertFalse($result->noMissingRequiredSections);
        $this->assertFalse($result->noUnboundedScope);
    }

    public function test_check_fails_when_allowed_and_forbidden_overlap(): void
    {
        $base = $this->baselineSections();
        $conflict = $base->allowedFiles[0] ?? 'app/some/file.php';

        $sections = new PromptSections(
            objective: $base->objective,
            operatingRules: $base->operatingRules,
            miniSpecRef: $base->miniSpecRef,
            taskContractRef: $base->taskContractRef,
            contextRefs: $base->contextRefs,
            codeDiscoveryRef: $base->codeDiscoveryRef,
            allowedFiles: $base->allowedFiles,
            forbiddenFiles: array_merge($base->forbiddenFiles, [$conflict]),
            expectedTests: $base->expectedTests,
            acceptanceCriteria: $base->acceptanceCriteria,
            stopConditions: $base->stopConditions,
            escalationConditions: $base->escalationConditions,
            outputContract: $base->outputContract,
        );

        $result = $this->checker()->check(
            sections: $sections,
            renderedPromptText: "rendered prompt\n",
            taskContract: $this->baselineTaskContract(),
        );

        $this->assertFalse($result->noConflictingFileRules);
    }

    public function test_check_flags_benchmark_keyword_in_objective(): void
    {
        $base = $this->baselineSections();
        $sections = new PromptSections(
            objective: 'Vencer o RIVALS benchmark com o melhor score',
            operatingRules: $base->operatingRules,
            miniSpecRef: $base->miniSpecRef,
            taskContractRef: $base->taskContractRef,
            contextRefs: $base->contextRefs,
            codeDiscoveryRef: $base->codeDiscoveryRef,
            allowedFiles: $base->allowedFiles,
            forbiddenFiles: $base->forbiddenFiles,
            expectedTests: $base->expectedTests,
            acceptanceCriteria: $base->acceptanceCriteria,
            stopConditions: $base->stopConditions,
            escalationConditions: $base->escalationConditions,
            outputContract: $base->outputContract,
        );

        $result = $this->checker()->check(
            sections: $sections,
            renderedPromptText: "rendered prompt\n",
            taskContract: $this->baselineTaskContract(),
        );

        $this->assertFalse($result->noHiddenBenchmarkInstruction);
    }

    public function test_check_flags_benchmark_keyword_with_unicode_accents(): void
    {
        $base = $this->baselineSections();
        $sections = new PromptSections(
            objective: $base->objective,
            operatingRules: $base->operatingRules,
            miniSpecRef: $base->miniSpecRef,
            taskContractRef: $base->taskContractRef,
            contextRefs: $base->contextRefs,
            codeDiscoveryRef: $base->codeDiscoveryRef,
            allowedFiles: $base->allowedFiles,
            forbiddenFiles: $base->forbiddenFiles,
            expectedTests: $base->expectedTests,
            acceptanceCriteria: $base->acceptanceCriteria,
            stopConditions: $base->stopConditions,
            escalationConditions: $base->escalationConditions,
            outputContract: $base->outputContract,
        );

        $result = $this->checker()->check(
            sections: $sections,
            renderedPromptText: "precisamos derrotar Ríváls e mostrar leaderboard final\n",
            taskContract: $this->baselineTaskContract(),
        );

        $this->assertFalse($result->noHiddenBenchmarkInstruction);
    }

    public function test_check_flags_forge_or_council_terms(): void
    {
        $base = $this->baselineSections();
        $sections = new PromptSections(
            objective: 'Invoke forge para revisar a obra',
            operatingRules: $base->operatingRules,
            miniSpecRef: $base->miniSpecRef,
            taskContractRef: $base->taskContractRef,
            contextRefs: $base->contextRefs,
            codeDiscoveryRef: $base->codeDiscoveryRef,
            allowedFiles: $base->allowedFiles,
            forbiddenFiles: $base->forbiddenFiles,
            expectedTests: $base->expectedTests,
            acceptanceCriteria: $base->acceptanceCriteria,
            stopConditions: $base->stopConditions,
            escalationConditions: $base->escalationConditions,
            outputContract: $base->outputContract,
        );

        $result = $this->checker()->check(
            sections: $sections,
            renderedPromptText: "rendered prompt\n",
            taskContract: $this->baselineTaskContract(),
        );

        $this->assertFalse($result->noForgeOrCouncilLeakage);
    }

    public function test_check_flags_provider_unsafe_secret_in_rendered_text(): void
    {
        $result = $this->checker()->check(
            sections: $this->baselineSections(),
            renderedPromptText: "exemplo de prompt com Authorization: Bearer eyJ-something\n",
            taskContract: $this->baselineTaskContract(),
        );

        $this->assertFalse($result->providerSafe);
    }

    public function test_check_flags_unbounded_scope_token(): void
    {
        $base = $this->baselineSections();
        $sections = new PromptSections(
            objective: $base->objective,
            operatingRules: $base->operatingRules,
            miniSpecRef: $base->miniSpecRef,
            taskContractRef: $base->taskContractRef,
            contextRefs: $base->contextRefs,
            codeDiscoveryRef: $base->codeDiscoveryRef,
            allowedFiles: $base->allowedFiles,
            forbiddenFiles: $base->forbiddenFiles,
            expectedTests: $base->expectedTests,
            acceptanceCriteria: ['refatorar tudo conforme necessario'],
            stopConditions: $base->stopConditions,
            escalationConditions: $base->escalationConditions,
            outputContract: $base->outputContract,
        );

        $result = $this->checker()->check(
            sections: $sections,
            renderedPromptText: "rendered prompt\n",
            taskContract: $this->baselineTaskContract(),
        );

        $this->assertFalse($result->noUnboundedScope);
    }

    public function test_provider_safe_flag_short_circuits_when_requested_false(): void
    {
        $result = $this->checker()->check(
            sections: $this->baselineSections(),
            renderedPromptText: "rendered prompt\n",
            taskContract: $this->baselineTaskContract(),
            providerSafeRequested: false,
        );

        $this->assertFalse($result->providerSafe);
    }
}
