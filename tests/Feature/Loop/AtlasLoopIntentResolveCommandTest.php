<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopIntentResolveCommand;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentResolver\AtlasLoopIntentAmbiguityClarifierProposer;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentResolver\AtlasLoopIntentAmbiguityFollowUpScheduler;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentResolver\AtlasLoopIntentAmbiguityResolutionLedger;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Tests\TestCase;

final class AtlasLoopIntentResolveCommandTest extends TestCase
{
    private string $ledgerPath = '';

    /** @var list<array<string,mixed>> */
    private array $intents = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-intent-ledger-'.bin2hex(random_bytes(6)).'.jsonl';
        config(['atlas.loop.intent_resolver.ledger_path' => $this->ledgerPath]);

        $this->intents = [
            [
                'intent_id' => 'FIXTURE',
                'text' => 'optimize the thing soon',
                'capture_at_utc' => '2026-06-25T00:00:00Z',
                'enumerated_tokens' => ['this_quarter', 'this_week'],
                'ambiguities' => [
                    [
                        'ambiguity_finding_id' => 'amb-soon',
                        'dimension' => 'vague-term',
                        'source_span' => 'soon',
                        'question_hash' => 'q-soon',
                    ],
                ],
            ],
        ];

        $intentsRef = &$this->intents;
        $this->app->instance(
            AtlasLoopIntentResolveCommand::INTENTS_SOURCE_BINDING,
            static function () use (&$intentsRef): array {
                return $intentsRef;
            },
        );

        // Rebind ledger + scheduler so they pick up the fresh ledger path + intents source.
        $this->app->forgetInstance(AtlasLoopIntentAmbiguityResolutionLedger::class);
        $this->app->forgetInstance(AtlasLoopIntentAmbiguityFollowUpScheduler::class);
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function callCli(string $action, array $options = []): array
    {
        $opts = ['action' => $action, '--json' => true];
        foreach ($options as $k => $v) {
            $opts['--'.$k] = $v;
        }
        $exit = Artisan::call('atlas:loop:intent:resolve', $opts);
        $raw = trim(Artisan::output());
        $payload = json_decode($raw, true);

        return ['exit' => $exit, 'raw' => $raw, 'payload' => is_array($payload) ? $payload : []];
    }

    public function test_propose_emits_byte_identical_json_on_repeated_invocations(): void
    {
        $first = $this->callCli('propose', ['intent' => 'FIXTURE']);
        $second = $this->callCli('propose', ['intent' => 'FIXTURE']);

        $this->assertSame(0, $first['exit']);
        $this->assertSame(0, $second['exit']);
        $this->assertSame($first['raw'], $second['raw'], 'propose must be byte-stable across invocations');
    }

    public function test_resolve_with_valid_choice_appends_row_and_clears_pending(): void
    {
        $proposed = $this->callCli('propose', ['intent' => 'FIXTURE']);
        $packets = (array) $proposed['payload']['packets'];
        $this->assertNotEmpty($packets);

        // Pick a candidate from the actual proposed set so we satisfy candidatesProvider.
        $firstPacket = reset($packets);
        $candidate = (string) (((array) $firstPacket['candidates'])[0] ?? '');
        $this->assertNotSame('', $candidate);

        $pendingBefore = $this->callCli('pending');
        $this->assertSame(1, count($pendingBefore['payload']['rows']));

        $resolved = $this->callCli('resolve', [
            'intent' => 'FIXTURE',
            'question' => 'q-soon',
            'choice' => $candidate,
        ]);
        $this->assertSame(0, $resolved['exit']);
        $this->assertSame('resolved', $resolved['payload']['outcome']);

        $rows = (array) file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(1, $rows);

        $pendingAfter = $this->callCli('pending');
        $this->assertSame(0, $pendingAfter['exit']);
        $hashes = array_map(static fn (array $r): string => (string) $r['question_hash'], (array) $pendingAfter['payload']['rows']);
        $this->assertNotContains('q-soon', $hashes);
    }

    public function test_resolve_with_unknown_choice_is_fail_closed_and_ledger_unchanged(): void
    {
        $resolved = $this->callCli('resolve', [
            'intent' => 'FIXTURE',
            'question' => 'q-soon',
            'choice' => 'a_choice_that_was_never_proposed',
        ]);
        $this->assertNotSame(0, $resolved['exit'], 'fail-closed exit code expected');
        $this->assertFileDoesNotExist($this->ledgerPath, 'ledger must NOT be created on rejected resolution');
    }

    public function test_constructor_injects_three_services_and_has_no_provider_symbol_references(): void
    {
        $reflection = new ReflectionClass(AtlasLoopIntentResolveCommand::class);
        $ctor = $reflection->getConstructor();
        $this->assertNotNull($ctor);
        $paramTypes = [];
        foreach ($ctor->getParameters() as $p) {
            $type = $p->getType();
            $paramTypes[] = $type !== null && method_exists($type, 'getName') ? $type->getName() : '';
        }
        $this->assertContains(AtlasLoopIntentAmbiguityClarifierProposer::class, $paramTypes);
        $this->assertContains(AtlasLoopIntentAmbiguityResolutionLedger::class, $paramTypes);
        $this->assertContains(AtlasLoopIntentAmbiguityFollowUpScheduler::class, $paramTypes);

        $source = (string) file_get_contents((string) $reflection->getFileName());
        foreach (['Hermes', 'AiGatewayService', 'AiProviderManager'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, 'forbidden provider symbol present: '.$forbidden);
        }
    }
}
