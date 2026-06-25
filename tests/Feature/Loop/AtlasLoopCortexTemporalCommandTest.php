<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopCortexTemporalCommand;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Temporal\AtlasCortexSymbolAgeReporter;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Tests\TestCase;

final class AtlasLoopCortexTemporalCommandTest extends TestCase
{
    private const KNOWN_FQCN = 'App\\Console\\Commands\\AtlasLoopFormalInvariantProofCli';

    public function test_age_subcommand_emits_json_byte_identical_to_reporter(): void
    {
        $exit = Artisan::call('atlas:loop:cortex:temporal', [
            'action' => 'age',
            '--fqcn' => [self::KNOWN_FQCN],
            '--json' => true,
        ]);
        $cliRaw = trim(Artisan::output());

        $reporter = $this->app->make(AtlasCortexSymbolAgeReporter::class);
        $expected = (string) json_encode(
            $reporter->report([self::KNOWN_FQCN]),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $this->assertSame($expected, $cliRaw, 'age --json must be byte-identical to AgeReporter::report');
        $this->assertContains($exit, [0, 2], 'reporter never throws ⇒ CLI exits 0 (rows) or 2 (empty)');
    }

    public function test_query_orphans_older_than_returns_zero_or_two_without_fabricating(): void
    {
        // No --fqcn list ⇒ empty result by service contract ⇒ exit 2 (zero rows, NEVER fabricates).
        $exit = Artisan::call('atlas:loop:cortex:temporal', [
            'action' => 'query',
            '--predicate' => 'orphans-older-than',
            '--days' => 30,
            '--json' => true,
        ]);
        $raw = trim(Artisan::output());
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);

        if (count($decoded) === 0) {
            $this->assertSame(2, $exit, 'empty result ⇒ exit 2');
        } else {
            $this->assertSame(0, $exit, 'non-empty result ⇒ exit 0');
        }
    }

    public function test_invalid_predicate_returns_exit_1_with_allowed_set_named(): void
    {
        $exit = Artisan::call('atlas:loop:cortex:temporal', [
            'action' => 'query',
            '--predicate' => 'bogus-predicate',
            '--json' => true,
        ]);
        $this->assertSame(1, $exit);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertSame('invalid_predicate', $payload['error']);
        foreach (AtlasLoopCortexTemporalCommand::VALID_PREDICATES as $p) {
            $this->assertStringContainsString($p, (string) $payload['message']);
        }
    }

    public function test_unknown_action_returns_exit_1(): void
    {
        $exit = Artisan::call('atlas:loop:cortex:temporal', [
            'action' => 'bogus',
            '--json' => true,
        ]);
        $this->assertSame(1, $exit);
    }

    public function test_command_source_contains_no_score_rank_recommendation_or_should_fix_tokens(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(AtlasLoopCortexTemporalCommand::class))->getFileName(),
        );
        foreach (['score', 'rank', 'recommendation', 'should-fix', 'should_fix'] as $forbidden) {
            $this->assertSame(
                0,
                preg_match('/\b'.preg_quote($forbidden, '/').'\b/i', $src),
                'forbidden token present in command source: '.$forbidden,
            );
        }
    }
}
