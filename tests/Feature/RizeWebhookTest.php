<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RizeWebhookTest extends TestCase
{
    use DatabaseTransactions;

    public function test_rize_webhook_stores_session_and_rebuilds_daily_snapshot(): void
    {
        if (config('database.default') === 'sqlite') {
            $this->markTestSkipped('Digital Sensor 4 migrations use PostgreSQL JSONB/partial indexes.');
        }

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        config()->set('services.rize.webhook_secret', null);
        config()->set('services.rize.timezone', 'America/Sao_Paulo');

        $this->postJson('/integrations/rize/webhook', [
            'event' => 'session.completed',
            'id' => 'rize-event-1',
            'data' => [
                'app_name' => 'Cursor',
                'bundle_id' => 'com.todesktop.230313mzl4w4u92',
                'started_at' => '2026-04-28T12:00:00.000Z',
                'ended_at' => '2026-04-28T12:35:00.000Z',
                'project' => 'Atlas',
            ],
        ], [
            'X-Atlas-Token' => 'test-token-with-enough-length-123',
        ])
            ->assertAccepted()
            ->assertJsonPath('status', 'processed');

        $this->assertDatabaseHas('digital_import_events', [
            'source' => 'rize',
            'source_event_id' => 'rize-event-1',
            'status' => 'processed',
        ]);

        $this->assertDatabaseHas('digital_sessions', [
            'source' => 'rize',
            'source_event_id' => 'rize-event-1',
            'source_name' => 'Cursor',
            'duration_seconds' => 2100,
        ]);

        $this->assertDatabaseHas('digital_activity_snapshots', [
            'source' => 'atlas_server',
            'snapshot_date' => '2026-04-28',
            'snapshot_timezone' => 'America/Sao_Paulo',
            'signal_count' => 1,
            'total_screen_time_min' => 35,
            'deep_work_total_min' => null,
            'algorithmic_input_min' => null,
        ]);
    }
}
