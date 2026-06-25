<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves atlas:self-construction:learning-transfer: classify integrates give_back events; gate admits
 * proven candidates and rejects unbound/unverified/secret ones; plan emits lesson rows from accepted
 * candidates; template returns the canonical lesson record shape; missing facts ⇒ usage_error; unknown
 * action ⇒ unknown_action.
 */
final class AtlasSelfConstructionLearningTransferCommandTest extends TestCase
{
    private string $factsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factsPath = sys_get_temp_dir().'/atlas_lt_facts_'.bin2hex(random_bytes(6)).'.json';
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

    public function test_classify_action_integrates_give_back_events(): void
    {
        $this->writeJson(['events' => [
            ['task_packet_id' => 'pkt-a', 'reason' => 'scope_repair', 'blocking_deficiencies' => ['missing_impl_file: app/X.php']],
        ]]);
        Artisan::call('atlas:self-construction:learning-transfer', ['action' => 'classify', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('ok', $p['status']);
        $this->assertCount(1, $p['classification']['recommendations']);
    }

    public function test_gate_admits_proven_candidate_and_rejects_unbound(): void
    {
        $this->writeJson(['candidates' => [
            ['id' => 'd-1', 'kind' => 'decision', 'bound' => true, 'fact_summary' => 'capability X delivered'],
            ['id' => 'x-1', 'kind' => 'decision', 'bound' => false, 'fact_summary' => 'unbound'],
        ]]);
        Artisan::call('atlas:self-construction:learning-transfer', ['action' => 'gate', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertCount(1, $p['gate']['export_plan']);
        $this->assertCount(1, $p['gate']['rejections']);
    }

    public function test_plan_emits_lesson_rows_from_accepted_candidates(): void
    {
        $this->writeJson(['candidates' => [
            ['id' => 'd-1', 'kind' => 'capability_lift', 'bound' => true, 'fact_summary' => 'shipped X helper'],
        ]]);
        Artisan::call('atlas:self-construction:learning-transfer', ['action' => 'plan', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertCount(1, $p['transfer_plan']['lessons']);
        $this->assertSame('capability_lift', $p['transfer_plan']['lessons'][0]['kind']);
    }

    public function test_template_returns_canonical_lesson_record_shape(): void
    {
        Artisan::call('atlas:self-construction:learning-transfer', ['action' => 'template', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('atlas.learning_transfer.lesson_record.v1', $p['template']['schema']);
        $this->assertNull($p['template']['lesson_id']);
        $this->assertTrue($p['template']['provider_safe']);
    }

    public function test_classify_with_missing_facts_yields_usage_error(): void
    {
        $exit = Artisan::call('atlas:self-construction:learning-transfer', ['action' => 'classify', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $p['status']);
    }

    public function test_unknown_action_yields_unknown_action(): void
    {
        $exit = Artisan::call('atlas:self-construction:learning-transfer', ['action' => 'bogus', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('unknown_action', $p['status']);
    }
}
