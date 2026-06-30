<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex;

use App\Services\Ai\AutonomousEvolution\Cortex\AtlasCortexUniversalContract;
use App\Services\Ai\AutonomousEvolution\Cortex\AtlasCortexUniversalFactsSchema;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModelBuilder;
use Tests\TestCase;

/**
 * Proves the schema: SCHEMA_ID matches the contract's contractSchemaId(); validate() returns [] on a canonical
 * facts payload (shape-matching the comprehension model's toArray()); validate() returns a non-empty error
 * list whose first message contains the literal substring 'never a score' when a unit carries a forbidden
 * `score => 0.42` key; the real builder's output also validates clean for a tiny fixture.
 */
final class AtlasCortexUniversalFactsSchemaTest extends TestCase
{
    private function schema(): AtlasCortexUniversalFactsSchema
    {
        return new AtlasCortexUniversalFactsSchema;
    }

    public function test_schema_id_matches_documented_literal_and_contract(): void
    {
        $this->assertSame('atlas.cortex.facts.v1', AtlasCortexUniversalFactsSchema::SCHEMA_ID);
        $contract = $this->app->make(AtlasCortexUniversalContract::class);
        $this->assertSame(AtlasCortexUniversalFactsSchema::SCHEMA_ID, $contract->contractSchemaId());
    }

    public function test_canonical_facts_payload_validates_clean(): void
    {
        $facts = [
            'snapshot_id' => 'snap-1',
            'inventory' => ['app/Foo.php' => ['rel_path' => 'app/Foo.php']],
            'orphans' => [],
            'clone_clusters' => [],
            'forbidden' => [],
            'doc_stated_gaps' => [],
            // Optional aspirational keys
            'edges' => [],
            'doc_purposes' => [],
            'doc_purposes_provenance' => 'writable_prose',
            'doc_stated_gaps_provenance' => 'writable_prose',
            'schema_version' => '2.0',
        ];
        $this->assertSame([], $this->schema()->validate($facts));
    }

    public function test_unit_with_forbidden_score_key_fails_validation_with_never_a_score_message(): void
    {
        $facts = [
            'snapshot_id' => 'snap-1',
            'inventory' => [],
            'orphans' => [],
            'clone_clusters' => [],
            'forbidden' => [],
            'doc_stated_gaps' => [],
            'units' => [
                'App\\Foo' => ['score' => 0.42],
            ],
        ];

        $errors = $this->schema()->validate($facts);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('never a score', $errors[0]);
    }

    public function test_unit_with_rank_or_grade_key_also_fails(): void
    {
        foreach (['Rank' => ['rank' => 1], 'Grade' => ['grade' => 'A']] as $name => $unit) {
            $facts = [
                'snapshot_id' => 's',
                'inventory' => [],
                'orphans' => [],
                'clone_clusters' => [],
                'forbidden' => [],
                'doc_stated_gaps' => [],
                'units' => [$name => $unit],
            ];
            $errors = $this->schema()->validate($facts);
            $this->assertNotEmpty($errors, "{$name} unit must fail");
            $this->assertStringContainsString('never a score', $errors[0]);
        }
    }

    public function test_missing_required_top_level_key_fails(): void
    {
        $errors = $this->schema()->validate(['snapshot_id' => 'x']);
        $this->assertNotEmpty($errors);
        $combined = implode("\n", $errors);
        foreach (['inventory', 'orphans', 'clone_clusters', 'forbidden', 'doc_stated_gaps'] as $key) {
            $this->assertStringContainsString(AtlasCortexUniversalFactsSchema::VIOLATION_MISSING_FIELD.': '.$key, $combined);
        }
    }

    public function test_definition_returns_schema_id_and_required_block(): void
    {
        $def = $this->schema()->definition();

        $this->assertSame('atlas.cortex.facts.v1', $def['schema_id']);
        $this->assertContains('snapshot_id', $def['required']);
        $this->assertContains('inventory', $def['required']);
    }

    // ---------- violation codes ----------

    private function base(): array
    {
        return ['snapshot_id' => 'snap-1', 'inventory' => [], 'orphans' => [], 'clone_clusters' => [], 'forbidden' => [], 'doc_stated_gaps' => []];
    }

    public function test_unsafe_evidence_ref_violation_for_api_key_in_ref(): void
    {
        $facts = array_merge($this->base(), ['evidence_refs' => ['sk-ant-api01-supersecretkey12345678']]);
        $errors = $this->schema()->validate($facts);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString(AtlasCortexUniversalFactsSchema::VIOLATION_UNSAFE_EVIDENCE_REF, implode("\n", $errors));
    }

    public function test_safe_evidence_refs_produce_no_violation(): void
    {
        $facts = array_merge($this->base(), ['evidence_refs' => ['receipt:abc123', 'gate:phpunit-green', 'ledger:entry-42']]);
        $this->assertSame([], $this->schema()->validate($facts));
    }

    public function test_stale_fact_violation_when_captured_at_exceeds_threshold(): void
    {
        // Use a future nowUnix so a recent timestamp appears stale.
        $capturedAt = date('c', time());
        $futureNow  = time() + AtlasCortexUniversalFactsSchema::FRESHNESS_THRESHOLD_SECONDS + 3600;
        $facts      = array_merge($this->base(), ['captured_at' => $capturedAt]);
        $schema     = new AtlasCortexUniversalFactsSchema($futureNow);
        $errors     = $schema->validate($facts);
        $this->assertStringContainsString(AtlasCortexUniversalFactsSchema::VIOLATION_STALE_FACT, implode("\n", $errors));
    }

    public function test_fresh_captured_at_does_not_trigger_stale_violation(): void
    {
        $facts = array_merge($this->base(), ['captured_at' => date('c')]);
        $this->assertSame([], $this->schema()->validate($facts));
    }

    public function test_scalar_only_score_violation_for_numeric_only_unit(): void
    {
        $facts = array_merge($this->base(), ['units' => ['App\\Foo' => ['confidence' => 0.9]]]);
        $errors = $this->schema()->validate($facts);
        $this->assertStringContainsString(AtlasCortexUniversalFactsSchema::VIOLATION_SCALAR_ONLY_SCORE, implode("\n", $errors));
    }

    public function test_unit_with_structural_key_alongside_numeric_does_not_trigger_scalar_only(): void
    {
        $facts = array_merge($this->base(), ['units' => ['App\\Foo' => ['confidence' => 0.9, 'basis' => 'all gates green']]]);
        $this->assertSame([], $this->schema()->validate($facts));
    }

    public function test_normalize_returns_deterministic_facts_with_defaults(): void
    {
        $facts  = $this->base();
        $result = $this->schema()->normalize($facts);
        $this->assertArrayHasKey('facts', $result);
        $this->assertArrayHasKey('violations', $result);
        $this->assertSame([], $result['violations']);
        $this->assertArrayHasKey('source_workspace', $result['facts']);
        $this->assertArrayHasKey('evidence_refs', $result['facts']);
        $this->assertArrayHasKey('captured_at', $result['facts']);
        // Second call must produce byte-identical facts.
        $result2 = $this->schema()->normalize($facts);
        $this->assertSame(
            json_encode($result['facts'],  JSON_UNESCAPED_SLASHES),
            json_encode($result2['facts'], JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_real_builder_output_validates_clean_for_a_tiny_fixture_scope(): void
    {
        $fixtureDir = base_path('tests/Fixtures/Cortex/sample-scope');
        if (! is_dir($fixtureDir)) {
            mkdir($fixtureDir, 0775, true);
        }
        $sample = $fixtureDir.'/SampleClass.php';
        file_put_contents($sample, "<?php\nnamespace Tests\\Fixtures\\Cortex\\SampleScope;\nclass SampleClass { public function go(): void {} }\n");

        try {
            $builder = $this->app->make(AtlasLoopScopeComprehensionModelBuilder::class);
            $facts = $builder->build((string) base_path(), $fixtureDir, [])->toArray();

            $errors = $this->schema()->validate($facts);
            $this->assertSame([], $errors, 'builder output must validate clean: '.implode('; ', $errors));
        } finally {
            @unlink($sample);
            @rmdir($fixtureDir);
        }
    }
}
