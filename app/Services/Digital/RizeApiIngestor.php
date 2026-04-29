<?php

namespace App\Services\Digital;

use App\Models\DigitalImportEvent;
use App\Models\DigitalSession;
use App\Support\Metadata;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

class RizeApiIngestor
{
    public function __construct(
        private readonly RizeApiClient $client,
        private readonly RizeSessionNormalizer $normalizer,
        private readonly DigitalActivitySnapshotBuilder $snapshots,
    ) {}

    public function sync(CarbonImmutable $from, CarbonImmutable $to, int $pageSize = 100, bool $dryRun = false): array
    {
        $import = DigitalImportEvent::create([
            'source' => 'rize',
            'source_event_id' => 'rize-api:'.$from->utc()->toIso8601String().':'.$to->utc()->toIso8601String(),
            'event_type' => 'api.sync',
            'received_at' => now(),
            'raw_payload' => Metadata::forStorage([
                'from' => $from->utc()->toIso8601String(),
                'to' => $to->utc()->toIso8601String(),
                'page_size' => $pageSize,
                'dry_run' => $dryRun,
            ]),
            'metadata' => Metadata::forStorage([
                'ingestor' => 'rize-api-v1',
            ]),
        ]);

        $stats = [
            'from' => $from->utc()->toIso8601String(),
            'to' => $to->utc()->toIso8601String(),
            'pages' => 0,
            'received' => 0,
            'normalized' => 0,
            'stored' => 0,
            'ignored' => 0,
            'snapshots_rebuilt' => 0,
            'dry_run' => $dryRun,
        ];

        $touchedDates = collect();
        $after = null;

        try {
            do {
                $page = $this->client->fetchSessions($from, $to, $pageSize, $after);
                $stats['pages']++;
                $stats['received'] += count($page->records);

                foreach ($page->records as $record) {
                    $session = $this->normalizer->normalize($record, null, 'rize-api-v1');

                    if (! $session) {
                        $stats['ignored']++;

                        continue;
                    }

                    $stats['normalized']++;
                    $touchedDates->push([
                        'date' => CarbonImmutable::parse($session['started_at'])
                            ->setTimezone($session['recorded_timezone'])
                            ->startOfDay(),
                        'timezone' => $session['recorded_timezone'],
                    ]);

                    if ($dryRun) {
                        continue;
                    }

                    DigitalSession::updateOrCreate(
                        ['client_id' => $session['client_id']],
                        $session,
                    );
                    $stats['stored']++;
                }

                $after = $page->endCursor;
            } while ($page->hasNextPage && $after);

            if (! $dryRun) {
                $stats['snapshots_rebuilt'] = $this->rebuildSnapshots($touchedDates);
            }

            $import->update([
                'status' => 'processed',
                'processed_at' => now(),
                'metadata' => Metadata::forStorage([
                    'ingestor' => 'rize-api-v1',
                    'stats' => $stats,
                ]),
            ]);
        } catch (Throwable $exception) {
            $import->update([
                'status' => 'failed',
                'processed_at' => now(),
                'error_message' => $exception->getMessage(),
                'metadata' => Metadata::forStorage([
                    'ingestor' => 'rize-api-v1',
                    'stats' => $stats,
                ]),
            ]);

            throw $exception;
        }

        return $stats;
    }

    private function rebuildSnapshots(Collection $touchedDates): int
    {
        return $touchedDates
            ->unique(fn (array $item): string => $item['timezone'].':'.$item['date']->toDateString())
            ->each(fn (array $item): mixed => $this->snapshots->rebuild($item['date'], $item['timezone']))
            ->count();
    }
}
