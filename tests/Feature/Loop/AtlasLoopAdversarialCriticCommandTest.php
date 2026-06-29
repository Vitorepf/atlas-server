<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the adversarial critic is live at the operator surface and emits deterministic facts: a cheap
 * ratio-winner is challenged and replaced by a genuinely bigger leap among the floor-passers; when the
 * winner is itself the biggest leap it stands unchallenged. A missing winner is a usage error.
 */
final class AtlasLoopAdversarialCriticCommandTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['atlas.loop.critic_numerator_threshold' => 0.6]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_requires_winner(): void
    {
        $exit = Artisan::call('atlas:loop:adversarial-critic', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_cheap_winner_is_challenged_for_a_bigger_leap(): void
    {
        $small = $this->candidate('app/Small.php', 0.3, 0.3, 0.3); // magnitude 0.027
        $big = $this->candidate('app/Big.php', 1.0, 1.0, 1.0);     // magnitude 1.0

        $decoded = $this->invoke(['winner' => $small, 'floor_passers' => [$small, $big]]);

        $this->assertSame('atlas.loop.adversarial_critic.v1', $decoded['schema']);
        $this->assertTrue($decoded['challenged']);
        $this->assertSame('app/Big.php', $decoded['pick']['path']);
    }

    public function test_biggest_winner_stands_unchallenged(): void
    {
        $big = $this->candidate('app/Big.php', 1.0, 1.0, 1.0);

        $decoded = $this->invoke(['winner' => $big, 'floor_passers' => [$big]]);

        $this->assertFalse($decoded['challenged']);
        $this->assertSame('app/Big.php', $decoded['pick']['path']);
    }

    /**
     * @return array<string,mixed>
     */
    private function candidate(string $path, float $impact, float $breadth, float $compounding): array
    {
        return [
            'path' => $path,
            '_score' => ['components' => [
                'strategic_impact' => $impact,
                'breadth' => $breadth,
                'compounding' => $compounding,
            ]],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function invoke(array $payload): array
    {
        $path = tempnam(sys_get_temp_dir(), 'critic_').'.json';
        $this->files[] = $path;
        file_put_contents($path, json_encode($payload));

        $exit = Artisan::call('atlas:loop:adversarial-critic', ['--input' => $path, '--json' => true]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
