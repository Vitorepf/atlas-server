<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Console\Commands\AtlasSelfConstructionAutopoiesisCommand;
use App\Services\Ai\SelfConstruction\Autopoiesis\AtlasSelfConstructionAutopoiesisOutcomeInterpreter;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves atlas:self-construction:autopoiesis: hypothesize / design / gate / interpret all return
 * facts-only envelopes with a governed_organ statement; malformed --facts payloads are handled
 * (usage_error / design_invalid); unknown action returns unknown_action.
 */
final class AtlasSelfConstructionAutopoiesisCommandTest extends TestCase
{
    private string $factsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factsPath = sys_get_temp_dir().'/atlas_autop_facts_'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->factsPath);
        parent::tearDown();
    }

    private function writeJson(array $data): void
    {
        file_put_contents($this->factsPath, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    public function test_hypothesize_returns_parsed_payload_with_governed_organ_statement(): void
    {
        $this->writeJson(['hypothesis_id' => 'h-1', 'claim' => 'X improves Y', 'scope_paths' => ['app/Demo/Foo.php']]);
        Artisan::call('atlas:self-construction:autopoiesis', ['action' => 'hypothesize', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $p['status']);
        $this->assertSame('h-1', $p['hypothesis']['hypothesis_id']);
        $this->assertSame(AtlasSelfConstructionAutopoiesisCommand::GOVERNED_ORGAN_STATEMENT['role'], $p['governed_organ']['role']);
    }

    public function test_design_returns_experiment_plan_when_hypothesis_valid(): void
    {
        $this->writeJson([
            'hypothesis_id' => 'h-1',
            'claim' => 'X',
            'scope_paths' => ['app/Demo/Foo.php'],
            'expected_evidence' => ['phpunit_green'],
            'gates' => ['phpunit'],
            'rollback' => ['strategy' => 'revert_commit', 'affected_files' => ['app/Demo/Foo.php']],
        ]);
        Artisan::call('atlas:self-construction:autopoiesis', ['action' => 'design', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('ok', $p['status']);
        $this->assertSame('designed', $p['experiment_plan']['status']);
    }

    public function test_design_fails_closed_on_missing_rollback_strategy(): void
    {
        $this->writeJson([
            'hypothesis_id' => 'h-1',
            'claim' => 'X',
            'scope_paths' => ['app/Demo/Foo.php'],
            'expected_evidence' => ['e'],
            'gates' => [],
            'rollback' => ['affected_files' => ['app/Demo/Foo.php']], // strategy missing
        ]);
        Artisan::call('atlas:self-construction:autopoiesis', ['action' => 'design', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('design_invalid', $p['status']);
    }

    public function test_gate_returns_blocking_facts_when_plan_requires_operator_approval(): void
    {
        $this->writeJson(['plan_id' => 'p', 'description' => 'requires operator approval per release']);
        Artisan::call('atlas:self-construction:autopoiesis', ['action' => 'gate', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertFalse($p['dependency_regression_gate']['allowed']);
    }

    public function test_interpret_returns_verdict_envelope(): void
    {
        $this->writeJson(['verification_passed' => true, 'evidence_ref' => 'evh-1', 'real_leverage_proof' => true]);
        Artisan::call('atlas:self-construction:autopoiesis', ['action' => 'interpret', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(AtlasSelfConstructionAutopoiesisOutcomeInterpreter::VERDICT_PROMOTE, $p['outcome']['verdict']);
    }

    public function test_missing_facts_yields_usage_error(): void
    {
        $exit = Artisan::call('atlas:self-construction:autopoiesis', ['action' => 'design', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $p['status']);
    }

    public function test_unknown_action_yields_unknown_action(): void
    {
        $exit = Artisan::call('atlas:self-construction:autopoiesis', ['action' => 'bogus', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('unknown_action', $p['status']);
    }

    public function test_generate_returns_hypothesis_envelope_for_real_failure_facts(): void
    {
        $this->writeJson([
            'failures' => [
                ['organ' => 'autopoiesis', 'class' => 'flaky_evidence', 'evidence_refs' => ['evh-1', 'evh-2']],
            ],
        ]);

        Artisan::call('atlas:self-construction:autopoiesis', ['action' => 'generate', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $p['status']);
        $this->assertSame(
            'atlas.autopoiesis.hypothesis.v1',
            $p['hypothesis_envelope']['schema_version']
        );
        $this->assertNotEmpty($p['hypothesis_envelope']['hypotheses']);
        $first = $p['hypothesis_envelope']['hypotheses'][0];
        $this->assertSame('autopoiesis', $first['target_organ']);
        $this->assertNotEmpty($first['class']);
        $this->assertSame(AtlasSelfConstructionAutopoiesisCommand::GOVERNED_ORGAN_STATEMENT['role'], $p['governed_organ']['role']);
    }

    public function test_generate_returns_rejected_when_only_proxy_only_seed_signals(): void
    {
        $this->writeJson([
            'seed_signals' => ['novelty', 'task_count', 'line_churn', 'green_self_report'],
        ]);

        Artisan::call('atlas:self-construction:autopoiesis', ['action' => 'generate', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $p['status']);
        $this->assertSame([], $p['hypothesis_envelope']['hypotheses']);
        $this->assertNotEmpty($p['hypothesis_envelope']['rejected']);
        $this->assertStringContainsString('proxy_only_seed', $p['hypothesis_envelope']['rejected'][0]['reason']);
    }

    public function test_generate_yields_usage_error_when_facts_missing(): void
    {
        $exit = Artisan::call('atlas:self-construction:autopoiesis', ['action' => 'generate', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $p['status']);
    }
}
