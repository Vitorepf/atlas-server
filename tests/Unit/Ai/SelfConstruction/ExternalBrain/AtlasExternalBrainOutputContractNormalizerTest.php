<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOutputContractNormalizer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOutputContractNormalizerTest extends TestCase
{
    private function normalizer(): AtlasExternalBrainOutputContractNormalizer
    {
        return new AtlasExternalBrainOutputContractNormalizer;
    }

    private function valid(array $overrides = []): array
    {
        return array_merge([
            'id'            => 'p1',
            'objective'     => 'Implement FooService',
            'allowed_files' => ['app/Services/Foo.php'],
            'acceptance'    => ['tests pass', 'no regressions'],
            'evidence'      => ['phpunit:FooTest'],
        ], $overrides);
    }

    // ── AC4: output shape ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->normalizer()->normalize([]);
        $this->assertSame(AtlasExternalBrainOutputContractNormalizer::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('normalized_contracts', $r);
        $this->assertArrayHasKey('rejected_inputs', $r);
        $this->assertArrayHasKey('missing_fields', $r);
        $this->assertArrayHasKey('task_fabric_ready', $r);
    }

    // ── AC2: required fields extracted ───────────────────────────────────────

    public function test_valid_proposal_normalized_with_all_required_fields(): void
    {
        $r = $this->normalizer()->normalize(['proposals' => [$this->valid()]]);
        $this->assertCount(1, $r['normalized_contracts']);
        $c = $r['normalized_contracts'][0];
        $this->assertSame('Implement FooService', $c['objective']);
        $this->assertSame(['app/Services/Foo.php'], $c['allowed_files']);
        $this->assertSame(['tests pass', 'no regressions'], $c['acceptance']);
        $this->assertSame(['phpunit:FooTest'], $c['evidence']);
    }

    public function test_optional_fields_defaulted_to_empty_when_absent(): void
    {
        $r = $this->normalizer()->normalize(['proposals' => [$this->valid()]]);
        $c = $r['normalized_contracts'][0];
        $this->assertSame([], $c['scope_in']);
        $this->assertSame([], $c['risks']);
        $this->assertSame([], $c['dependencies']);
    }

    public function test_optional_fields_preserved_when_present(): void
    {
        $r = $this->normalizer()->normalize(['proposals' => [$this->valid([
            'scope_in'     => ['ServiceLayer'],
            'risks'        => ['breaking change'],
            'dependencies' => ['dep-A'],
        ])]]);
        $c = $r['normalized_contracts'][0];
        $this->assertSame(['ServiceLayer'],  $c['scope_in']);
        $this->assertSame(['breaking change'], $c['risks']);
        $this->assertSame(['dep-A'],          $c['dependencies']);
    }

    // ── AC3: rejection on missing/empty required fields ───────────────────────

    public function test_missing_objective_causes_rejection(): void
    {
        $r = $this->normalizer()->normalize(['proposals' => [$this->valid(['objective' => ''])]]);
        $this->assertEmpty($r['normalized_contracts']);
        $this->assertCount(1, $r['rejected_inputs']);
        $this->assertContains('objective', $r['rejected_inputs'][0]['missing_fields']);
        $this->assertContains('objective', $r['missing_fields']);
    }

    public function test_empty_allowed_files_causes_rejection(): void
    {
        $r = $this->normalizer()->normalize(['proposals' => [$this->valid(['allowed_files' => []])]]);
        $this->assertCount(1, $r['rejected_inputs']);
        $this->assertContains('allowed_files', $r['rejected_inputs'][0]['missing_fields']);
    }

    public function test_missing_acceptance_causes_rejection(): void
    {
        $r = $this->normalizer()->normalize(['proposals' => [$this->valid(['acceptance' => []])]]);
        $this->assertContains('acceptance', $r['rejected_inputs'][0]['missing_fields']);
    }

    public function test_missing_evidence_causes_rejection(): void
    {
        $r = $this->normalizer()->normalize(['proposals' => [$this->valid(['evidence' => []])]]);
        $this->assertContains('evidence', $r['rejected_inputs'][0]['missing_fields']);
    }

    public function test_multiple_missing_fields_all_reported(): void
    {
        $r = $this->normalizer()->normalize(['proposals' => [[
            'id' => 'incomplete',
            // objective and acceptance missing
            'allowed_files' => ['path.php'],
            'evidence'      => ['phpunit:test'],
        ]]]);
        $missing = $r['rejected_inputs'][0]['missing_fields'];
        $this->assertContains('objective',   $missing);
        $this->assertContains('acceptance',  $missing);
    }

    public function test_normalizer_never_invents_field_values(): void
    {
        // Proposal with absent objective must be REJECTED, never given a default value.
        $r = $this->normalizer()->normalize(['proposals' => [$this->valid(['objective' => null])]]);
        $this->assertEmpty($r['normalized_contracts']);
        $this->assertNotEmpty($r['rejected_inputs']);
    }

    // ── AC4: task_fabric_ready ────────────────────────────────────────────────

    public function test_task_fabric_ready_true_when_all_normalized(): void
    {
        $r = $this->normalizer()->normalize(['proposals' => [$this->valid()]]);
        $this->assertTrue($r['task_fabric_ready']);
    }

    public function test_task_fabric_ready_false_when_any_rejected(): void
    {
        $r = $this->normalizer()->normalize(['proposals' => [
            $this->valid(['id' => 'ok']),
            $this->valid(['id' => 'bad', 'objective' => '']),
        ]]);
        $this->assertFalse($r['task_fabric_ready']);
    }

    public function test_task_fabric_ready_false_when_no_proposals(): void
    {
        $r = $this->normalizer()->normalize([]);
        $this->assertFalse($r['task_fabric_ready']);
    }

    // ── missing_fields union ──────────────────────────────────────────────────

    public function test_missing_fields_is_union_across_all_rejected(): void
    {
        $r = $this->normalizer()->normalize(['proposals' => [
            $this->valid(['objective' => '']),
            $this->valid(['evidence'  => []]),
        ]]);
        $this->assertContains('objective', $r['missing_fields']);
        $this->assertContains('evidence',  $r['missing_fields']);
        // No duplicates.
        $this->assertSame(array_unique($r['missing_fields']), $r['missing_fields']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = ['proposals' => [
            $this->valid(['id' => 'a']),
            $this->valid(['id' => 'b', 'acceptance' => []]),
        ]];
        $a = $this->normalizer()->normalize($facts);
        $b = $this->normalizer()->normalize($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
