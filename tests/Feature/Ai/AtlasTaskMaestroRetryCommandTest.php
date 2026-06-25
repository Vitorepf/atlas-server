<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Console\Commands\AtlasTaskMaestroRetryCommand;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasTaskMaestroRetryCommandTest extends TestCase
{
    public function test_evidence_returns_empty_facts_with_exit_zero_and_no_score_field(): void
    {
        $exit = Artisan::call('atlas:task:maestro:retry', ['action' => 'evidence', '--json' => true]);
        $this->assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame('evidence', $payload['action']);
        $this->assertIsArray($payload['facts']);

        foreach ($payload['facts'] as $row) {
            foreach (['score', 'rank', 'rating', 'quality'] as $forbidden) {
                $this->assertArrayNotHasKey($forbidden, (array) $row, 'forbidden aggregate field present: '.$forbidden);
            }
            // Required FACT counters per the contract.
            foreach (['attempts', 'successes', 'failures'] as $required) {
                $this->assertArrayHasKey($required, (array) $row);
            }
        }
    }

    public function test_bogus_action_exits_2_and_names_the_four_valid_actions(): void
    {
        $exit = Artisan::call('atlas:task:maestro:retry', ['action' => 'bogus', '--json' => true]);
        $this->assertSame(2, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame('refused', $payload['outcome']);
        $this->assertSame('unknown_action', $payload['reason']);
        foreach (['inspect', 'policy', 'evidence', 'history'] as $expected) {
            $this->assertContains($expected, (array) $payload['valid_actions']);
        }
    }

    public function test_policy_and_history_are_read_only(): void
    {
        $policy = Artisan::call('atlas:task:maestro:retry', ['action' => 'policy', '--json' => true]);
        $this->assertSame(0, $policy);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame('policy', $payload['action']);
        $this->assertArrayHasKey('max_retries', (array) $payload['policy']);

        // Read-only invariant: source file mentions no write/append/provider call.
        $src = (string) file_get_contents(
            (new \ReflectionClass(AtlasTaskMaestroRetryCommand::class))->getFileName(),
        );
        foreach (['->append(', 'AiGatewayService', 'AiProviderManager'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, 'forbidden write/provider call: '.$forbidden);
        }
    }
}
