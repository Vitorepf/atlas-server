<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiGovernedBacklogService;
use Illuminate\Console\Command;

/**
 * Runtime surface for the Atlas AI Governed Backlog doc. Without args it prints
 * the governance snapshot (state machine, promotion declarations, spine
 * alignments). With --from + --to it checks one state transition; with --class
 * it reports the privacy/capability obligations an item class triggers.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-governed-backlog.md
 */
final class AtlasAiGovernedBacklogCommand extends Command
{
    protected $signature = 'atlas:aaeos:ai-governed-backlog
        {--from= : Source state for a transition check (source-material|candidate|promoted|rejected|archived)}
        {--to= : Target state for a transition check}
        {--class= : Item class to classify (general|personal-development|personal-sensor|executive-action)}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect and evaluate the Atlas AI governed backlog (state machine, promotion gate, privacy/capability obligations) runtime.';

    public function handle(AtlasAiGovernedBacklogService $service): int
    {
        $json = (bool) $this->option('json');

        try {
            $class = $this->option('class');
            if (is_string($class) && trim($class) !== '') {
                return $this->emit($service->classifyItem(str_replace('-', '_', $class)), $json);
            }

            $from = $this->option('from');
            $to = $this->option('to');
            if (is_string($from) && trim($from) !== '' && is_string($to) && trim($to) !== '') {
                $result = $service->transition(
                    str_replace('-', '_', $from),
                    str_replace('-', '_', $to),
                );

                return $this->emit($result, $json);
            }

            return $this->emit($service->snapshot(), $json);
        } catch (\Throwable $e) {
            $this->emit([
                'schema_version' => AtlasAiGovernedBacklogService::SCHEMA_VERSION,
                'error' => true,
                'message' => $e->getMessage(),
            ], $json);

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, bool $json): int
    {
        $this->line((string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        return self::SUCCESS;
    }
}
