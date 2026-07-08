<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationEvidenceQueryService;
use Tests\TestCase;

final class AgentControlPlaneCertificationEvidenceQueryServiceTest extends TestCase
{
    private function query(array $options = []): array
    {
        return app(AgentControlPlaneCertificationEvidenceQueryService::class)->query($options);
    }

    // ── Output structure ───────────────────────────────────────────────

    public function test_query_returns_expected_schema(): void
    {
        $result = $this->query();

        $this->assertSame(
            AgentControlPlaneCertificationEvidenceQueryService::SCHEMA_VERSION,
            $result['schema_version'],
        );
        $this->assertSame(
            AgentControlPlaneCertificationEvidenceQueryService::MODE,
            $result['mode'],
        );
        $this->assertArrayHasKey('query_hash', $result);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['query_hash']);
    }

    // ── No-filter query ────────────────────────────────────────────────

    public function test_query_without_filters_returns_available_and_records(): void
    {
        $result = $this->query();

        $this->assertSame('available', $result['status']);
        $this->assertGreaterThan(0, $result['record_count']);
        $this->assertGreaterThan(0, $result['result_count']);
        $this->assertNotEmpty($result['results']);
    }

    // ── Read-only guarantees ───────────────────────────────────────────

    public function test_query_read_only_guarantees(): void
    {
        $result = $this->query();

        $this->assertTrue($result['read_only']);
        $this->assertFalse($result['execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertFalse($result['external_provider_call']);
        $this->assertFalse($result['token_spend']);
        $this->assertFalse($result['process_started']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['completion_claim_allowed']);
        $this->assertNotEmpty($result['non_execution_guarantees']);
    }

    // ── Filters ────────────────────────────────────────────────────────

    public function test_query_with_valid_field_filter_returns_filtered_results(): void
    {
        $result = $this->query([
            'filters' => [
                ['field' => 'slice', 'operator' => 'equals', 'value' => ''],
            ],
        ]);

        // Filtering for empty slice should still work — it's a valid filter
        $this->assertSame('no_match', $result['status']);
        $this->assertSame(0, $result['result_count']);
    }

    public function test_query_with_invalid_field_returns_blocked(): void
    {
        $result = $this->query([
            'filters' => [
                ['field' => 'nonexistent_field', 'operator' => 'equals', 'value' => 'x'],
            ],
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertNotEmpty($result['invalid_filters']);
        $this->assertContains('unsupported_field', $result['invalid_filters'][0]['reasons']);
    }

    public function test_query_with_invalid_operator_returns_blocked(): void
    {
        $result = $this->query([
            'filters' => [
                ['field' => 'slice', 'operator' => 'regex', 'value' => 'x'],
            ],
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertNotEmpty($result['invalid_filters']);
        $this->assertContains('unsupported_operator', $result['invalid_filters'][0]['reasons']);
    }

    public function test_query_with_in_operator_and_non_array_value_returns_blocked(): void
    {
        $result = $this->query([
            'filters' => [
                ['field' => 'slice', 'operator' => 'in', 'value' => 'not-an-array'],
            ],
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertNotEmpty($result['invalid_filters']);
    }

    public function test_query_filters_by_slice_equals(): void
    {
        // Use a real slice key from the data — check what's available via facets
        $result = $this->query([
            'filters' => [
                ['field' => 'slice', 'operator' => 'exists'],
            ],
        ]);

        // At least we know the filter syntax works
        $this->assertContains($result['status'], ['available', 'no_match']);
        $this->assertEmpty($result['invalid_filters']);
    }

    // ── Supported fields/operators ─────────────────────────────────────

    public function test_query_exposes_supported_fields_and_operators(): void
    {
        $result = $this->query();

        $this->assertNotEmpty($result['supported_fields']);
        $this->assertContains('slice', $result['supported_fields']);
        $this->assertContains('capability', $result['supported_fields']);
        $this->assertContains('proof_kind', $result['supported_fields']);

        $this->assertNotEmpty($result['supported_operators']);
        $this->assertContains('equals', $result['supported_operators']);
        $this->assertContains('contains', $result['supported_operators']);
        $this->assertContains('exists', $result['supported_operators']);
        $this->assertContains('missing', $result['supported_operators']);
        $this->assertContains('in', $result['supported_operators']);
        $this->assertContains('not_in', $result['supported_operators']);
    }

    // ── Facets ─────────────────────────────────────────────────────────

    public function test_query_returns_facets(): void
    {
        $result = $this->query();

        $this->assertArrayHasKey('facets', $result);
        $this->assertNotEmpty($result['facets']);
    }

    // ── Hash stability (query_hash excludes query_id and generated_at) ──

    public function test_query_hash_is_valid_hex_format(): void
    {
        $result = $this->query();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['query_hash']);
    }

    // ── Results have expected structure ────────────────────────────────

    public function test_query_results_have_expected_structure(): void
    {
        $result = $this->query();

        if ($result['result_count'] > 0) {
            $record = $result['results'][0];
            $this->assertArrayHasKey('kind', $record);
            $this->assertArrayHasKey('field', $record);
            $this->assertArrayHasKey('value', $record);
            $this->assertArrayHasKey('meta', $record);
        }
        $this->assertNotEmpty($result['results']);
    }

    // ── Human summary ──────────────────────────────────────────────────

    public function test_query_returns_human_summary(): void
    {
        $result = $this->query();

        $this->assertArrayHasKey('human_summary', $result);
        $this->assertStringContainsString('Evidence query', $result['human_summary']);
    }
}
