<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\Retrieval\Maxa04JinaV3DualReadService;
use Illuminate\Console\Command;
use Throwable;

final class AtlasSemanticJinaV3DualReadCommand extends Command
{
    protected $signature = 'atlas:semantic:jina-v3-dual-read
        {--cases= : Optional JSON file with dual-read cases to evaluate}
        {--record : Append the evaluated cases to the MAXA-04 dual-read ledger}
        {--json : Emit JSON}';

    protected $description = 'MAXA-04 read-only/default-off jina-v3 dual-read mechanism report.';

    public function handle(Maxa04JinaV3DualReadService $service): int
    {
        $cases = $this->readCases();
        $payload = $cases === null
            ? $service->plan()
            : ((bool) $this->option('record') ? $service->record($cases) : $service->evaluate($cases));

        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        return self::SUCCESS;
    }

    /** @return list<array<string,mixed>>|null */
    private function readCases(): ?array
    {
        $path = $this->option('cases');
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents(trim($path)), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $rows = array_is_list($decoded) ? $decoded : (array) ($decoded['cases'] ?? []);

        return array_values(array_filter($rows, 'is_array'));
    }
}
