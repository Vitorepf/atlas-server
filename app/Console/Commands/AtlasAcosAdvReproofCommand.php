<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * ADV-01 - read-only summary of external adversarial re-proof verdicts.
 */
final class AtlasAcosAdvReproofCommand extends Command
{
    use EmitsCanonicalJson;

    private const SCHEMA_VERSION = 'atlas.acos.adv_reproof.v1';

    private const MIN_VERDICTS = 6;

    protected $signature = 'atlas:acos:adv-reproof
        {--json : Emit canonical JSON}
        {--path= : JSONL evidence path (default: storage/app/atlas/evidence/acos-adv-01-external-reproof.jsonl)}';

    protected $description = 'ADV-01 - summarize external adversarial re-proof verdicts from JSONL evidence.';

    public function handle(): int
    {
        $path = trim((string) $this->option('path'))
            ?: storage_path('app/atlas/evidence/acos-adv-01-external-reproof.jsonl');

        $payload = $this->report($path);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('path', (string) $payload['path']);
            $this->components->twoColumnDetail('total_verdicts', (string) $payload['total_verdicts']);
            foreach ($payload['counts'] as $verdict => $count) {
                $this->components->twoColumnDetail((string) $verdict, (string) $count);
            }
            foreach ($payload['blocking'] as $blocking) {
                $this->components->twoColumnDetail('blocking', (string) $blocking);
            }
        }

        return $payload['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{
     *     schema_version: string,
     *     status: 'passed'|'failed',
     *     path: string,
     *     total_verdicts: int,
     *     counts: array{confirmed: int, refuted: int, inconclusive: int},
     *     invalid_lines: list<int>,
     *     blocking: list<string>
     * }
     */
    private function report(string $path): array
    {
        $counts = [
            'confirmed' => 0,
            'refuted' => 0,
            'inconclusive' => 0,
        ];
        $total = 0;
        $invalidLines = [];
        $blocking = [];
        /** @var array<string,array{verdict:string,checked_at:string}> $latestByCertifier */
        $latestByCertifier = [];

        if (! is_file($path)) {
            $blocking[] = 'evidence_file_missing';
        } else {
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            foreach ($lines === false ? [] : $lines as $index => $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }

                $row = json_decode($line, true);
                if (! is_array($row)) {
                    $invalidLines[] = $index + 1;

                    continue;
                }

                $certifier = strtoupper(trim((string) ($row['certifier_id'] ?? '')));
                $verdict = strtolower(trim((string) ($row['verdict'] ?? 'inconclusive')));
                if (! array_key_exists($verdict, $counts)) {
                    $verdict = 'inconclusive';
                }
                $checkedAt = (string) ($row['checked_at'] ?? '');

                if ($certifier === '') {
                    // Legacy rows without certifier_id still count once.
                    $counts[$verdict]++;
                    $total++;

                    continue;
                }

                $previous = $latestByCertifier[$certifier] ?? null;
                if ($previous === null || strcmp($checkedAt, $previous['checked_at']) >= 0) {
                    $latestByCertifier[$certifier] = [
                        'verdict' => $verdict,
                        'checked_at' => $checkedAt,
                    ];
                }
            }

            foreach ($latestByCertifier as $row) {
                $counts[$row['verdict']]++;
                $total++;
            }
        }

        if ($counts['refuted'] > 0) {
            $blocking[] = 'refuted_verdicts_present';
        }

        if ($total < self::MIN_VERDICTS) {
            $blocking[] = 'verdict_count_below_floor';
        }

        if ($invalidLines !== []) {
            $blocking[] = 'invalid_jsonl_lines';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blocking === [] ? 'passed' : 'failed',
            'path' => $path,
            'total_verdicts' => $total,
            'counts' => $counts,
            'invalid_lines' => $invalidLines,
            'blocking' => array_values(array_unique($blocking)),
            'aggregation' => 'latest_per_certifier_id',
        ];
    }
}
