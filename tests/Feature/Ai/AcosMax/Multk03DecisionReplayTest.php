<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AcosMax;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class Multk03DecisionReplayTest extends TestCase
{
    private string $consultationsPath;

    private string $liveOutcomesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(4));
        $this->consultationsPath = storage_path('framework/testing/multk03-consultations-'.$tag.'.jsonl');
        $this->liveOutcomesPath = storage_path('framework/testing/multk03-live-outcomes-'.$tag.'.jsonl');
        @mkdir(dirname($this->consultationsPath), 0775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->consultationsPath);
        @unlink($this->liveOutcomesPath);
        parent::tearDown();
    }

    public function test_same_state_replay_has_zero_divergence_and_does_not_write_ledgers(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->appendConsultation('env-'.$i, 'follow_learned_route', 'backend');
        }
        file_put_contents($this->liveOutcomesPath, "sentinel\n");
        $consultBefore = hash_file('sha256', $this->consultationsPath);
        $liveBefore = hash_file('sha256', $this->liveOutcomesPath);

        $payload = $this->callReplay();

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(10, $payload['n_replayed']);
        $this->assertSame(0, $payload['n_diverged']);
        $this->assertSame(0.0, $payload['divergence_rate']);
        $this->assertSame(['backend' => ['n' => 10, 'diverged' => 0, 'divergence_rate' => 0.0, 'status' => 'ok']], $payload['by_scope']);
        $this->assertSame($consultBefore, hash_file('sha256', $this->consultationsPath));
        $this->assertSame($liveBefore, hash_file('sha256', $this->liveOutcomesPath));
        $this->assertFalse(data_get($payload, 'claim_policy.writes_gateway_consultations'));
        $this->assertFalse(data_get($payload, 'claim_policy.writes_live_outcomes'));
    }

    public function test_cells_under_minimum_n_are_insufficient(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->appendConsultation('env-small-'.$i, 'free_to_choose', 'ops', currentVerdict: 'blocked');
        }

        $payload = $this->callReplay();

        $this->assertSame('insufficient_n', $payload['status']);
        $this->assertSame(3, $payload['n_replayed']);
        $this->assertSame(3, $payload['n_diverged']);
        $this->assertSame(1.0, $payload['divergence_rate']);
        $this->assertSame('insufficient_n', $payload['by_scope']['ops']['status']);
    }

    private function appendConsultation(string $hash, string $verdict, string $taskCategory, ?string $currentVerdict = null): void
    {
        $row = [
            'schema_version' => 'atlas.atlas_decide.gateway_consultation.v1',
            'consulted_at' => '2026-07-12T12:00:00+00:00',
            'scope' => ['task_category' => $taskCategory, 'role' => 'builder', 'framework' => null, 'privacy_class' => 'normal'],
            'verdict' => $verdict,
            'routing_basis' => $verdict === 'follow_learned_route' ? 'learned_route' : 'free_to_choose',
            'envelope_hash' => $hash,
        ];
        if ($currentVerdict !== null) {
            $row['replay_current_verdict'] = $currentVerdict;
        }

        file_put_contents($this->consultationsPath, json_encode($row, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
    }

    /**
     * @return array<string,mixed>
     */
    private function callReplay(): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:decide:replay-divergence', [
            '--consultations' => $this->consultationsPath,
            '--live-outcomes' => $this->liveOutcomesPath,
            '--json' => true,
        ], $output);

        $this->assertSame(0, $exit);

        return json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);
    }
}
