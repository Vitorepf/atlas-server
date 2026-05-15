<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentValidationGateCatalog;
use Tests\TestCase;

final class AgentValidationGateCatalogTest extends TestCase
{
    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_validation_gate_catalog.v1', AgentValidationGateCatalog::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_validation_gate_catalog', AgentValidationGateCatalog::MODE);
    }

    public function test_describe_top_level_shape(): void
    {
        $catalog = new AgentValidationGateCatalog;
        $out = $catalog->describe();
        $this->assertSame('atlas.self_construction.agent_validation_gate_catalog.v1', $out['schema_version']);
        $this->assertSame('read_only_agent_validation_gate_catalog', $out['mode']);
        $this->assertSame('available', $out['status']);
        $this->assertFalse($out['execution_allowed']);
        $this->assertFalse($out['dispatch_allowed']);
        $this->assertFalse($out['provider_call_allowed']);
        $this->assertFalse($out['token_spend_allowed']);
        $this->assertFalse($out['self_programming_allowed']);
        $this->assertFalse($out['ledger_write_allowed']);
        $this->assertFalse($out['runtime_write_allowed']);
        $this->assertSame(10, $out['gate_count']);
        $this->assertIsArray($out['gates']);
        $this->assertIsArray($out['gate_ids']);
        $this->assertIsArray($out['types']);
        $this->assertIsArray($out['severities']);
        $this->assertIsArray($out['blocking_ids']);
        $this->assertIsArray($out['non_blocking_ids']);
        $this->assertIsArray($out['command_required_ids']);
        $this->assertIsArray($out['internal_only_ids']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $out['catalog_hash']);
        $this->assertIsArray($out['runtime_safety']);
    }

    public function test_all_10_gate_ids_present(): void
    {
        $catalog = new AgentValidationGateCatalog;
        $ids = $catalog->ids();
        $expected = [
            'architecture_validate',
            'continuation_summary_check',
            'diff_check',
            'docs_health',
            'evidence_check',
            'focused_tests',
            'php_lint',
            'rollback_plan_check',
            'scope_check',
            'unit_tests',
        ];
        $this->assertSame($expected, $ids);
        foreach ($expected as $id) {
            $this->assertTrue($catalog->has($id), "missing gate: {$id}");
            $this->assertIsArray($catalog->get($id));
        }
    }

    public function test_each_gate_has_canonical_fields(): void
    {
        $catalog = new AgentValidationGateCatalog;
        $required = ['id', 'name', 'type', 'severity', 'blocking', 'requires_command', 'command', 'expected_artifact', 'repair_hint'];
        foreach ($catalog->gates() as $id => $gate) {
            foreach ($required as $field) {
                $this->assertArrayHasKey($field, $gate, "gate {$id} missing field {$field}");
            }
            $this->assertSame($id, $gate['id']);
            $this->assertIsString($gate['name']);
            $this->assertNotSame('', $gate['name']);
            $this->assertIsString($gate['type']);
            $this->assertIsString($gate['severity']);
            $this->assertIsBool($gate['blocking']);
            $this->assertIsBool($gate['requires_command']);
            $this->assertIsString($gate['command']);
            $this->assertNotSame('', $gate['command']);
            $this->assertIsString($gate['expected_artifact']);
            $this->assertNotSame('', $gate['expected_artifact']);
            $this->assertIsString($gate['repair_hint']);
            $this->assertNotSame('', $gate['repair_hint']);
        }
    }

    public function test_severity_values_in_allowed_set(): void
    {
        $catalog = new AgentValidationGateCatalog;
        $allowed = ['low', 'medium', 'high', 'critical'];
        foreach ($catalog->gates() as $gate) {
            $this->assertContains($gate['severity'], $allowed);
        }
    }

    public function test_type_values_in_allowed_set(): void
    {
        $catalog = new AgentValidationGateCatalog;
        $allowed = ['lint', 'test', 'docs', 'architecture', 'vcs', 'scope', 'evidence', 'continuation', 'rollback'];
        foreach ($catalog->gates() as $gate) {
            $this->assertContains($gate['type'], $allowed);
        }
    }

    public function test_blocking_partition(): void
    {
        $catalog = new AgentValidationGateCatalog;
        $blocking = $catalog->blockingIds();
        $nonBlocking = $catalog->nonBlockingIds();
        $this->assertSame([], array_intersect($blocking, $nonBlocking));
        $this->assertSame(count($catalog->ids()), count($blocking) + count($nonBlocking));
        $this->assertContains('php_lint', $blocking);
        $this->assertContains('unit_tests', $blocking);
        $this->assertContains('focused_tests', $blocking);
        $this->assertContains('docs_health', $blocking);
        $this->assertContains('architecture_validate', $blocking);
        $this->assertContains('diff_check', $blocking);
        $this->assertContains('scope_check', $blocking);
        $this->assertContains('evidence_check', $blocking);
        $this->assertContains('rollback_plan_check', $blocking);
        $this->assertContains('continuation_summary_check', $nonBlocking);
    }

    public function test_command_required_vs_internal_partition(): void
    {
        $catalog = new AgentValidationGateCatalog;
        $out = $catalog->describe();
        $cmd = $out['command_required_ids'];
        $int = $out['internal_only_ids'];
        $this->assertSame([], array_intersect($cmd, $int));
        $this->assertContains('php_lint', $cmd);
        $this->assertContains('unit_tests', $cmd);
        $this->assertContains('focused_tests', $cmd);
        $this->assertContains('docs_health', $cmd);
        $this->assertContains('architecture_validate', $cmd);
        $this->assertContains('diff_check', $cmd);
        $this->assertContains('scope_check', $int);
        $this->assertContains('evidence_check', $int);
        $this->assertContains('continuation_summary_check', $int);
        $this->assertContains('rollback_plan_check', $int);
    }

    public function test_specific_gate_metadata_correct(): void
    {
        $catalog = new AgentValidationGateCatalog;
        $php = $catalog->get('php_lint');
        $this->assertSame('lint', $php['type']);
        $this->assertSame('high', $php['severity']);
        $this->assertTrue($php['blocking']);
        $this->assertTrue($php['requires_command']);
        $this->assertSame('php -l', $php['command']);

        $unit = $catalog->get('unit_tests');
        $this->assertSame('test', $unit['type']);
        $this->assertSame('critical', $unit['severity']);
        $this->assertTrue($unit['blocking']);
        $this->assertTrue($unit['requires_command']);

        $focused = $catalog->get('focused_tests');
        $this->assertSame('test', $focused['type']);
        $this->assertSame('high', $focused['severity']);
        $this->assertTrue($focused['blocking']);
        $this->assertStringContainsString('phpunit', $focused['command']);

        $docs = $catalog->get('docs_health');
        $this->assertSame('docs', $docs['type']);
        $this->assertStringContainsString('atlas:engineering:knowledge docs-health', $docs['command']);

        $arch = $catalog->get('architecture_validate');
        $this->assertSame('architecture', $arch['type']);
        $this->assertStringContainsString('architecture-validate', $arch['command']);

        $diff = $catalog->get('diff_check');
        $this->assertSame('vcs', $diff['type']);
        $this->assertSame('git diff --check', $diff['command']);
        $this->assertSame('medium', $diff['severity']);

        $scope = $catalog->get('scope_check');
        $this->assertSame('scope', $scope['type']);
        $this->assertSame('critical', $scope['severity']);
        $this->assertFalse($scope['requires_command']);

        $evidence = $catalog->get('evidence_check');
        $this->assertSame('evidence', $evidence['type']);
        $this->assertFalse($evidence['requires_command']);
        $this->assertSame('high', $evidence['severity']);

        $cont = $catalog->get('continuation_summary_check');
        $this->assertSame('continuation', $cont['type']);
        $this->assertFalse($cont['blocking']);
        $this->assertFalse($cont['requires_command']);
        $this->assertSame('medium', $cont['severity']);

        $roll = $catalog->get('rollback_plan_check');
        $this->assertSame('rollback', $roll['type']);
        $this->assertSame('critical', $roll['severity']);
        $this->assertFalse($roll['requires_command']);
        $this->assertTrue($roll['blocking']);
    }

    public function test_unknown_gate_returns_null(): void
    {
        $catalog = new AgentValidationGateCatalog;
        $this->assertNull($catalog->get('does_not_exist'));
        $this->assertFalse($catalog->has('does_not_exist'));
        $this->assertNull($catalog->get(''));
    }

    public function test_ids_sorted(): void
    {
        $catalog = new AgentValidationGateCatalog;
        $ids = $catalog->ids();
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
    }

    public function test_ids_by_type_groups_correctly(): void
    {
        $catalog = new AgentValidationGateCatalog;
        $byType = $catalog->idsByType();
        $this->assertArrayHasKey('lint', $byType);
        $this->assertArrayHasKey('test', $byType);
        $this->assertArrayHasKey('docs', $byType);
        $this->assertArrayHasKey('architecture', $byType);
        $this->assertArrayHasKey('vcs', $byType);
        $this->assertArrayHasKey('scope', $byType);
        $this->assertArrayHasKey('evidence', $byType);
        $this->assertArrayHasKey('continuation', $byType);
        $this->assertArrayHasKey('rollback', $byType);
        $this->assertContains('php_lint', $byType['lint']);
        $this->assertContains('unit_tests', $byType['test']);
        $this->assertContains('focused_tests', $byType['test']);
        $this->assertSame(2, count($byType['test']));
        $this->assertSame(1, count($byType['lint']));
        $this->assertSame(1, count($byType['docs']));
        $this->assertSame(1, count($byType['architecture']));
        $this->assertSame(1, count($byType['vcs']));
        $this->assertSame(1, count($byType['scope']));
        $this->assertSame(1, count($byType['evidence']));
        $this->assertSame(1, count($byType['continuation']));
        $this->assertSame(1, count($byType['rollback']));
    }

    public function test_hash_stable_and_changes_with_payload(): void
    {
        $catalog = new AgentValidationGateCatalog;
        $a = $catalog->describe();
        $b = $catalog->describe();
        $this->assertSame($a['catalog_hash'], $b['catalog_hash']);
        $this->assertSame($catalog->hash(), $a['catalog_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $catalog->hash());
        $this->assertSame(64, strlen($a['catalog_hash']));
    }

    public function test_runtime_safety_block_all_false(): void
    {
        $catalog = new AgentValidationGateCatalog;
        $rs = $catalog->describe()['runtime_safety'];
        $this->assertTrue($rs['runtime_safety_all_false']);
        $this->assertFalse($rs['execution_allowed']);
        $this->assertFalse($rs['dispatch_allowed']);
        $this->assertFalse($rs['provider_call_allowed']);
        $this->assertFalse($rs['token_spend_allowed']);
        $this->assertFalse($rs['self_programming_allowed']);
        $this->assertFalse($rs['ledger_write_allowed']);
        $this->assertFalse($rs['runtime_write_allowed']);
    }

    public function test_gate_ids_and_gates_consistent(): void
    {
        $catalog = new AgentValidationGateCatalog;
        $describe = $catalog->describe();
        $ids = $describe['gate_ids'];
        $gates = $describe['gates'];
        $this->assertSame(count($ids), count($gates));
        foreach ($gates as $i => $gate) {
            $this->assertSame($ids[$i], $gate['id']);
        }
    }

    public function test_command_required_gates_have_command_strings(): void
    {
        $catalog = new AgentValidationGateCatalog;
        foreach ($catalog->gates() as $gate) {
            if ($gate['requires_command'] === true) {
                $this->assertNotSame('', $gate['command']);
                $this->assertStringNotContainsString('internal:', $gate['command']);
            } else {
                $this->assertStringStartsWith('internal:', $gate['command']);
            }
        }
    }

    public function test_severity_index_contents(): void
    {
        $catalog = new AgentValidationGateCatalog;
        $out = $catalog->describe();
        $sev = $out['severities'];
        $this->assertArrayHasKey('critical', $sev);
        $this->assertArrayHasKey('high', $sev);
        $this->assertArrayHasKey('medium', $sev);
        $this->assertContains('unit_tests', $sev['critical']);
        $this->assertContains('scope_check', $sev['critical']);
        $this->assertContains('rollback_plan_check', $sev['critical']);
        $this->assertContains('php_lint', $sev['high']);
        $this->assertContains('docs_health', $sev['high']);
        $this->assertContains('architecture_validate', $sev['high']);
        $this->assertContains('evidence_check', $sev['high']);
        $this->assertContains('focused_tests', $sev['high']);
        $this->assertContains('diff_check', $sev['medium']);
        $this->assertContains('continuation_summary_check', $sev['medium']);
    }

    public function test_gates_total_severity_count(): void
    {
        $catalog = new AgentValidationGateCatalog;
        $sev = $catalog->describe()['severities'];
        $total = 0;
        foreach ($sev as $ids) {
            $total += count($ids);
        }
        $this->assertSame(10, $total);
    }
}
