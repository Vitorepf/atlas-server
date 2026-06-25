<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopCortexQueryCommand;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopCortexQueryCommandTest extends TestCase
{
    private string $historyPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->historyPath = sys_get_temp_dir().'/atlas-cortex-query-history-'.bin2hex(random_bytes(6)).'.jsonl';
        $this->app->instance(AtlasLoopCortexQueryCommand::HISTORY_PATH_BINDING, $this->historyPath);
        $this->app->instance(
            AtlasLoopCortexQueryCommand::SNAPSHOT_SOURCE_BINDING,
            static fn (string $name): array => [
                ['fqcn' => 'App\\Foo', 'method_name' => 'doIt'],
                ['fqcn' => 'App\\Bar', 'method_name' => 'tweak'],
            ],
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->historyPath);
        parent::tearDown();
    }

    public function test_parse_prints_ast_with_grammar_clauses_and_exits_zero(): void
    {
        $exit = Artisan::call('atlas:loop:cortex:query', [
            'action' => 'parse',
            '--dsl' => 'SELECT fqcn FROM cortex_api_diff LIMIT 1',
        ]);
        $this->assertSame(0, $exit);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        // The AST must carry top-level grammar clauses (SELECT, FROM, LIMIT — produced by the parser).
        $this->assertArrayHasKey('SELECT', $payload);
        $this->assertArrayHasKey('FROM', $payload);
        $this->assertArrayHasKey('LIMIT', $payload);
    }

    public function test_execute_prints_fact_envelope_over_injected_snapshot(): void
    {
        $exit = Artisan::call('atlas:loop:cortex:query', [
            'action' => 'execute',
            '--dsl' => 'SELECT fqcn FROM cortex_api_diff LIMIT 5',
            '--snapshot' => 'test',
        ]);
        $this->assertSame(0, $exit);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('columns', $payload);
        $this->assertArrayHasKey('rows', $payload);
        $this->assertArrayHasKey('snapshot_version', $payload);
        $this->assertCount(2, (array) $payload['rows']);
    }

    public function test_history_lists_prior_queries_from_jsonl_log(): void
    {
        // Seed two queries.
        Artisan::call('atlas:loop:cortex:query', ['action' => 'parse', '--dsl' => 'SELECT fqcn FROM cortex_api_diff']);
        Artisan::call('atlas:loop:cortex:query', ['action' => 'parse', '--dsl' => 'SELECT method_name FROM cortex_api_diff']);

        $exit = Artisan::call('atlas:loop:cortex:query', ['action' => 'history', '--limit' => 10]);
        $this->assertSame(0, $exit);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame('history', $payload['action']);
        $this->assertGreaterThanOrEqual(2, count((array) $payload['rows']));
    }

    public function test_unknown_action_exits_non_zero_and_names_the_action(): void
    {
        $exit = Artisan::call('atlas:loop:cortex:query', ['action' => 'bogus']);
        $this->assertNotSame(0, $exit);
        $combined = Artisan::output();
        $this->assertStringContainsString('bogus', $combined);
        $this->assertStringContainsString('unknown_action', $combined);
    }

    public function test_malformed_dsl_exits_non_zero_with_parse_error(): void
    {
        $exit = Artisan::call('atlas:loop:cortex:query', [
            'action' => 'parse',
            '--dsl' => 'SELECT FROM',
        ]);
        $this->assertNotSame(0, $exit);
        $combined = Artisan::output();
        $this->assertStringContainsString('parse_error', $combined);
    }
}
