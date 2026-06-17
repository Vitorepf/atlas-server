<?php

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\PromptSections;
use PHPUnit\Framework\TestCase;

final class PromptSectionsTest extends TestCase
{
    use SchemaContractAssertions;

    private function example(array $overrides = []): PromptSections
    {
        $defaults = [
            'objective' => 'fix the failing test',
            'operatingRules' => ['rule_a', 'rule_b'],
            'miniSpecRef' => 'sha:spec',
            'taskContractRef' => 'sha:tc',
            'contextRefs' => ['ref_a'],
            'codeDiscoveryRef' => 'sha:cd',
            'allowedFiles' => ['app/A.php'],
            'forbiddenFiles' => ['vendor/*'],
            'expectedTests' => ['tests/AT.php'],
            'acceptanceCriteria' => ['passes'],
            'stopConditions' => ['scope_violation'],
            'escalationConditions' => ['risk_r4'],
            'outputContract' => ['unified_diff'],
        ];
        $merged = array_replace($defaults, $overrides);

        return new PromptSections(
            objective: $merged['objective'],
            operatingRules: $merged['operatingRules'],
            miniSpecRef: $merged['miniSpecRef'],
            taskContractRef: $merged['taskContractRef'],
            contextRefs: $merged['contextRefs'],
            codeDiscoveryRef: $merged['codeDiscoveryRef'],
            allowedFiles: $merged['allowedFiles'],
            forbiddenFiles: $merged['forbiddenFiles'],
            expectedTests: $merged['expectedTests'],
            acceptanceCriteria: $merged['acceptanceCriteria'],
            stopConditions: $merged['stopConditions'],
            escalationConditions: $merged['escalationConditions'],
            outputContract: $merged['outputContract'],
        );
    }

    public function test_canonical_array_is_sorted(): void
    {
        $sections = $this->example();
        $this->assertCanonicalArrayKeysSorted($sections);

        $expected = [
            'acceptance_criteria', 'allowed_files', 'code_discovery_ref', 'context_refs',
            'escalation_conditions', 'expected_tests', 'forbidden_files', 'known_failure_modes',
            'mini_spec_ref', 'non_goals', 'objective', 'operating_rules', 'output_contract',
            'stop_conditions', 'task_contract_ref',
        ];
        $this->assertSame($expected, array_keys($sections->toCanonicalArray()));
    }

    public function test_round_trip_preserves_hash(): void
    {
        $sections = $this->example();
        $rebuilt = PromptSections::fromArray(json_decode($sections->toJson(), true));
        $this->assertHashStable($sections, $rebuilt);
        $this->assertJsonRoundtripStable($sections);
    }

    public function test_hash_differs_when_objective_changes(): void
    {
        $a = $this->example();
        $b = $this->example(['objective' => 'a different mission']);
        $this->assertHashIsSha256($a);
        $this->assertHashDiffers($a, $b);
    }

    public function test_has_file_rule_conflict_detects_overlap(): void
    {
        $clean = $this->example();
        $this->assertFalse($clean->hasFileRuleConflict());

        $conflict = $this->example([
            'allowedFiles' => ['shared.php'],
            'forbiddenFiles' => ['shared.php'],
        ]);
        $this->assertTrue($conflict->hasFileRuleConflict());
    }

    public function test_has_all_required_sections(): void
    {
        $this->assertTrue($this->example()->hasAllRequiredSections());
        $this->assertFalse($this->example(['objective' => ''])->hasAllRequiredSections());
        $this->assertFalse($this->example(['outputContract' => []])->hasAllRequiredSections());
    }
}
