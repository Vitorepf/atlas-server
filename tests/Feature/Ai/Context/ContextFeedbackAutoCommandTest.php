<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Models\AiRagFeedbackEvent;
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
            $this->assertSame('partial', $events->first()->outcome_status);
            $this->assertSame('session-auto-'.sha1('abcdef0123456789,0123456789abcdef'), $events->first()->retrieval_receipt_id);
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
}
