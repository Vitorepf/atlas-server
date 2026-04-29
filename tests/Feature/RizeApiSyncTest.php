<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RizeApiSyncTest extends TestCase
{
    use DatabaseTransactions;

    public function test_rize_api_sync_stores_sessions_and_rebuilds_daily_snapshots(): void
    {
        if (config('database.default') === 'sqlite') {
            $this->markTestSkipped('Digital Sensor 4 migrations use PostgreSQL JSONB/partial indexes.');
        }

        config()->set('services.rize.api_key', 'fake-rize-key');
        config()->set('services.rize.graphql_endpoint', 'https://api.rize.test/graphql');
        config()->set('services.rize.timezone', 'America/Sao_Paulo');
        config()->set('services.rize.sessions_root_path', 'sessions.nodes');
        config()->set('services.rize.sessions_page_info_path', 'sessions.pageInfo');

        Http::fake([
            'api.rize.test/graphql' => Http::response([
                'data' => [
                    'sessions' => [
                        'nodes' => [
                            [
                                'id' => 'rize-session-1',
                                'appName' => 'Cursor',
                                'appBundleId' => 'com.todesktop.230313mzl4w4u92',
                                'startedAt' => '2026-04-28T12:00:00.000Z',
                                'endedAt' => '2026-04-28T12:35:00.000Z',
                                'project' => 'Atlas',
                                'durationSeconds' => 2100,
                            ],
                        ],
                        'pageInfo' => [
                            'hasNextPage' => false,
                            'endCursor' => null,
                        ],
                    ],
                ],
            ]),
        ]);

        $this->artisan('atlas:rize:sync', [
            '--from' => '2026-04-28T00:00:00-03:00',
            '--to' => '2026-04-29T00:00:00-03:00',
        ])->assertSuccessful();

        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer fake-rize-key'));

        $this->assertDatabaseHas('digital_import_events', [
            'source' => 'rize',
            'event_type' => 'api.sync',
            'status' => 'processed',
        ]);

        $this->assertDatabaseHas('digital_sessions', [
            'source' => 'rize',
            'source_event_id' => 'rize-session-1',
            'source_name' => 'Cursor',
            'duration_seconds' => 2100,
        ]);

        $this->assertDatabaseHas('digital_activity_snapshots', [
            'source' => 'atlas_server',
            'snapshot_date' => '2026-04-28',
            'snapshot_timezone' => 'America/Sao_Paulo',
            'signal_count' => 1,
            'total_screen_time_min' => 35,
        ]);
    }
}
