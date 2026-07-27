<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Models\AiRagFeedbackEvent;
use App\Services\Ai\Context\AtlasCanonicalContextRef;
use App\Services\Ai\Context\AtlasDeliveredPackLedger;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BootsCompoundingSchema;
use Tests\TestCase;

final class ContextFeedbackAutoCommandTest extends TestCase
{
    use BootsCompoundingSchema;

    public function test_records_one_aggregated_feedback_event_from_transcript_pack_hashes(): void
    {
        $this->bootCompoundingSchema();
        $transcript = tempnam(sys_get_temp_dir(), 'atlas-feedback-auto-');
        file_put_contents($transcript, implode("\n", [
            'user prompt with injected pack context_pack_hash=abcdef0123456789 ok',
            'another turn context_pack_hash=0123456789abcdef and repeat context_pack_hash=abcdef0123456789',
        ]));

        try {
            $exit = Artisan::call('atlas:context:feedback-auto', [
                '--transcript' => $transcript,
                '--outcome' => 'partial',
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            $this->assertSame('captured', $payload['status']);
            $this->assertSame(['abcdef0123456789', '0123456789abcdef'], $payload['context_pack_hashes']);
            $this->assertTrue($payload['persisted']);

            $events = AiRagFeedbackEvent::query()->where('flow_id', 'claude.session.auto')->get();
            $this->assertCount(1, $events);
            $this->assertSame('unknown', $events->first()->outcome_status);
            $this->assertSame('session-auto-'.sha1('abcdef0123456789,0123456789abcdef'), $events->first()->retrieval_receipt_id);
        } finally {
            @unlink($transcript);
            $this->dropCompoundingSchema();
        }
    }

    public function test_prefix_hashes_join_delivered_refs_from_delivered_pack_ledger(): void
    {
        $this->bootCompoundingSchema();
        $ledgerPath = $this->configureDeliveredPackLedger();
        $hashA = str_repeat('a', 64);
        $hashB = str_repeat('b', 64);
        $packA = $this->recordDeliveredPack($ledgerPath, $hashA, [
            ['id' => 'sym:AutoOne', 'file_path' => 'app/AutoOne.php', 'symbol_type' => 'class'],
            ['id' => 'sym:Shared', 'file_path' => 'app/Shared.php', 'symbol_type' => 'class'],
        ]);
        $packB = $this->recordDeliveredPack($ledgerPath, $hashB, [
            ['id' => 'sym:Shared', 'file_path' => 'app/Shared.php', 'symbol_type' => 'class'],
            ['id' => 'sym:AutoTwo', 'file_path' => 'app/AutoTwo.php', 'symbol_type' => 'class'],
        ]);
        $expectedRefs = AtlasCanonicalContextRef::uniqueStrings([
            ...AtlasCanonicalContextRef::deliveredFromPack($packA),
            ...AtlasCanonicalContextRef::deliveredFromPack($packB),
        ]);
        // Citar um ref no transcript NÃO o torna "usado": era exatamente esse
        // str_contains que media o eco do próprio pack. O registrador devolve o
        // conjunto ENTREGUE, e a atribuição se declara 'unmeasured'.
        $citedRef = $expectedRefs[1];
        $transcript = tempnam(sys_get_temp_dir(), 'atlas-feedback-auto-ledger-');
        file_put_contents($transcript, implode("\n", [
            '## Context feedback request context_pack_hash='.substr($hashA, 0, 16),
            '## Context feedback request context_pack_hash='.substr($hashB, 0, 16),
            'mechanical citation of delivered ref '.$citedRef,
        ]));

        try {
            $exit = Artisan::call('atlas:context:feedback-auto', [
                '--transcript' => $transcript,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            $this->assertSame('captured', $payload['status']);
            $this->assertSame([$hashA, $hashB], $payload['resolved_context_pack_hashes']);
            $this->assertSame($expectedRefs, $payload['delivered_context_refs']);
            $this->assertSame($expectedRefs, $payload['used_context_refs']);
            $this->assertSame('unmeasured', $payload['attribution_quality']);

            $event = AiRagFeedbackEvent::query()->where('flow_id', 'claude.session.auto')->firstOrFail();
            $this->assertSame($expectedRefs, data_get($event->payload, 'payload.context_ref_attribution.delivered_refs.*.ref'));
            $this->assertSame($expectedRefs, data_get($event->payload, 'payload.context_ref_attribution.used_refs.*.ref'));
        } finally {
            @unlink($transcript);
            $this->dropCompoundingSchema();
        }
    }

    public function test_user_prompt_submit_pack_marker_memory_refs_are_captured_without_structured_outcome(): void
    {
        $this->bootCompoundingSchema();
        $hash = str_repeat('e', 64);
        $memoryId = '019f5191-c035-7128-b128-487e985126e5';
        $transcript = tempnam(sys_get_temp_dir(), 'atlas-feedback-auto-pack-marker-');
        file_put_contents($transcript, implode("\n", [
            'UserPromptSubmit injected Atlas pack marker context_pack_hash='.substr($hash, 0, 16),
            json_encode([
                'context_feedback_request' => [
                    'delivered_context_refs' => [
                        'memory:lift-ref-abc123',
                    ],
                ],
                'memory' => [
                    [
                        'id' => $memoryId,
                        'slug' => 'loop-morto-autonomos-vivo',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            'Resumo final em prosa: testes passaram, sem tool result estruturado.',
        ]));

        try {
            $exit = Artisan::call('atlas:context:feedback-auto', [
                '--transcript' => $transcript,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            $this->assertSame('captured', $payload['status']);
            $this->assertSame('unknown', $payload['outcome']);
            $this->assertEqualsCanonicalizing([
                'memory:lift-ref-abc123',
                $memoryId,
                'memory:loop-morto-autonomos-vivo',
            ], $payload['delivered_context_refs']);

            $event = AiRagFeedbackEvent::query()->where('flow_id', 'claude.session.auto')->firstOrFail();
            $this->assertSame('unknown', $event->outcome_status);
            $eventDeliveredRefs = data_get($event->payload, 'payload.context_ref_attribution.delivered_refs.*.ref');
            $this->assertEmpty(array_diff($payload['delivered_context_refs'], $eventDeliveredRefs));
        } finally {
            @unlink($transcript);
            $this->dropCompoundingSchema();
        }
    }

    public function test_structured_bash_exit_code_derives_outcome_with_unmeasured_attribution(): void
    {
        $this->bootCompoundingSchema();
        $hash = str_repeat('c', 64);
        $ledgerPath = $this->configureDeliveredPackLedger();
        $this->recordDeliveredPack($ledgerPath, $hash, [
            ['id' => 'sym:Outcome', 'file_path' => 'app/Outcome.php', 'symbol_type' => 'class'],
        ]);
        $transcript = tempnam(sys_get_temp_dir(), 'atlas-feedback-auto-structured-');
        file_put_contents($transcript, implode("\n", [
            'context_pack_hash='.substr($hash, 0, 16),
            json_encode([
                'message' => [
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => 'toolu_bash_1',
                        'name' => 'Bash',
                    ]],
                ],
            ], JSON_THROW_ON_ERROR),
            json_encode([
                'message' => [
                    'content' => [[
                        'type' => 'tool_result',
                        'tool_use_id' => 'toolu_bash_1',
                        'exit_code' => 0,
                    ]],
                ],
            ], JSON_THROW_ON_ERROR),
        ]));

        try {
            $exit = Artisan::call('atlas:context:feedback-auto', [
                '--transcript' => $transcript,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            // O outcome segue inferido dos exit codes estruturados; a ATRIBUIÇÃO de
            // quais refs foram úteis não tem medidor e se declara 'unmeasured'.
            $this->assertSame('passed', $payload['outcome']);
            $this->assertSame('unmeasured', $payload['attribution_quality']);

            $event = AiRagFeedbackEvent::query()->where('flow_id', 'claude.session.auto')->firstOrFail();
            $this->assertSame('passed', $event->outcome_status);
            $this->assertSame('unmeasured', data_get($event->payload, 'payload.attribution_quality'));
        } finally {
            @unlink($transcript);
            $this->dropCompoundingSchema();
        }
    }

    public function test_prose_only_tests_passed_stays_unknown_with_unmeasured_attribution_quality(): void
    {
        $this->bootCompoundingSchema();
        $hash = str_repeat('d', 64);
        $ledgerPath = $this->configureDeliveredPackLedger();
        $this->recordDeliveredPack($ledgerPath, $hash, [
            ['id' => 'sym:Prose', 'file_path' => 'app/Prose.php', 'symbol_type' => 'class'],
        ]);
        $transcript = tempnam(sys_get_temp_dir(), 'atlas-feedback-auto-prose-');
        file_put_contents($transcript, implode("\n", [
            'context_pack_hash='.substr($hash, 0, 16),
            'Resumo final: testes passaram e tudo esta verde.',
        ]));

        try {
            $exit = Artisan::call('atlas:context:feedback-auto', [
                '--transcript' => $transcript,
                '--outcome' => 'passed',
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            $this->assertSame('unknown', $payload['outcome']);
            $this->assertSame('unmeasured', $payload['attribution_quality']);

            $event = AiRagFeedbackEvent::query()->where('flow_id', 'claude.session.auto')->firstOrFail();
            $this->assertSame('unknown', $event->outcome_status);
            $this->assertSame('unmeasured', data_get($event->payload, 'payload.attribution_quality'));
        } finally {
            @unlink($transcript);
            $this->dropCompoundingSchema();
        }
    }

    public function test_missing_transcript_is_fail_open_exit_zero(): void
    {
        $exit = Artisan::call('atlas:context:feedback-auto', [
            '--transcript' => '/nonexistent/transcript.jsonl',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('skipped', $payload['status']);
        $this->assertSame('transcript_missing_or_unreadable', $payload['note']);
    }

    /**
     * @param  array<int,array<string,string>>  $codeGraph
     * @return array<string,mixed>
     */
    private function recordDeliveredPack(string $ledgerPath, string $hash, array $codeGraph): array
    {
        $pack = [
            'context_pack_hash' => $hash,
            'code_graph' => $codeGraph,
            'reality_graph_paths' => [],
            'memory' => [],
            'budget' => ['total_budget_chars' => 1000],
            'context_delivery_policy' => ['status' => 'test'],
            'generated_at' => now()->toJSON(),
        ];

        (new AtlasDeliveredPackLedger($ledgerPath))->record($pack);

        return $pack;
    }

    private function configureDeliveredPackLedger(): string
    {
        $dir = sys_get_temp_dir().'/atlas-feedback-auto-ledger-'.bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        $path = $dir.'/delivered-pack-ledger.jsonl';
        config()->set('atlas.aobg.delivered_pack_ledger.path', $path);

        return $path;
    }
}
