<?php

namespace App\Services\Digital;

use App\Models\DigitalImportEvent;
use App\Models\DigitalSession;
use App\Support\Metadata;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class RizeWebhookIngestor
{
    public function __construct(
        private readonly DigitalActivitySnapshotBuilder $snapshots,
        private readonly RizeSessionNormalizer $normalizer,
    ) {}

    public function ingest(array $payload): DigitalImportEvent
    {
        $eventType = $this->firstString($payload, ['event', 'event_type', 'type', 'name']) ?? 'unknown';
        $sourceEventId = $this->firstString($payload, ['id', 'event_id', 'eventId', 'data.id', 'payload.id']);

        $import = DigitalImportEvent::create([
            'source' => 'rize',
            'source_event_id' => $sourceEventId,
            'event_type' => $eventType,
            'received_at' => now(),
            'raw_payload' => Metadata::forStorage($payload),
            'metadata' => Metadata::forStorage([
                'ingestor' => 'rize-webhook-v1',
            ]),
        ]);

        try {
            $session = $this->normalizer->normalize($payload, $sourceEventId, 'rize-webhook-v1');

            if (! $session) {
                $import->update([
                    'status' => 'ignored',
                    'processed_at' => now(),
                    'metadata' => Metadata::forStorage([
                        'ingestor' => 'rize-webhook-v1',
                        'reason' => 'payload_did_not_contain_session_timestamps',
                    ]),
                ]);

                return $import->refresh();
            }

            $digitalSession = DigitalSession::updateOrCreate(
                ['client_id' => $session['client_id']],
                $session
            );

            $this->snapshots->rebuild(
                CarbonImmutable::parse($digitalSession->started_at)->setTimezone($digitalSession->recorded_timezone),
                $digitalSession->recorded_timezone
            );

            $import->update([
                'status' => 'processed',
                'processed_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $import->update([
                'status' => 'failed',
                'processed_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);
        }

        return $import->refresh();
    }

    private function firstString(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = Arr::get($payload, $path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
