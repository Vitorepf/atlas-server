<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipPriorityEngineService;
use Illuminate\Console\Command;

final class AtlasSoftwareCompanyPriorityEngineCommand extends Command
{
    protected $signature = 'atlas:software-company-stewardship:priority-engine
        {--area=agentic_engineering_os : Stewardship area id}
        {--focus=dev_forge : Stewardship focus}
        {--input-file= : Optional JSON file with candidates/findings/specs/work_orders/branches/queue_items}
        {--json : Emit JSON only}';

    protected $description = 'AP-785 · rank stewardship work by largest advancement and robustness.';

    public function handle(StewardshipPriorityEngineService $service): int
    {
        $input = [
            'area_id' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
        ];

        $file = trim((string) ($this->option('input-file') ?? ''));
        if ($file !== '') {
            if (! is_file($file)) {
                return $this->emitBlocked('input_file_not_found', "Input file not found: {$file}");
            }

            $decoded = json_decode((string) file_get_contents($file), true);
            if (! is_array($decoded)) {
                return $this->emitBlocked('input_file_invalid_json', "Input file is not a JSON object or array: {$file}");
            }

            $input += array_is_list($decoded) ? ['candidates' => $decoded] : $decoded;
        }

        $payload = $service->rank($input);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($payload['status'] ?? '') === StewardshipPriorityEngineService::STATUS_BLOCKED
                ? self::FAILURE
                : self::SUCCESS;
        }

        $this->components->twoColumnDetail('AP-785 priority engine', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Area', (string) ($payload['area_id'] ?? ''));
        $this->components->twoColumnDetail('Focus', (string) ($payload['focus'] ?? ''));
        $this->components->twoColumnDetail('Top item', (string) data_get($payload, 'top_candidate.item_id', ''));
        $this->components->twoColumnDetail('Lane', (string) data_get($payload, 'top_candidate.lane', ''));
        $this->components->twoColumnDetail('Score', (string) data_get($payload, 'top_candidate.final_priority_score', ''));

        foreach (array_slice((array) ($payload['ranked_items'] ?? []), 0, 6) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $this->line(sprintf(
                '  #%d %s · %s · %s',
                (int) ($item['rank'] ?? 0),
                (string) ($item['lane'] ?? ''),
                (string) ($item['item_id'] ?? ''),
                (string) ($item['reason'] ?? ''),
            ));
        }

        return self::SUCCESS;
    }

    private function emitBlocked(string $reason, string $detail): int
    {
        $payload = [
            'schema_version' => 'atlas.software_company_stewardship.priority_engine_error.v1',
            'status' => 'blocked',
            'reason' => $reason,
            'detail' => $detail,
        ];

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::FAILURE;
    }
}
