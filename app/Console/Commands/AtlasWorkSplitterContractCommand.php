<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasWorkSplitterContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Self-Construction Work Splitter CLI.
 *
 *   php artisan atlas:aaeos:work-splitter-contract
 *     [--backlog=path/to/backlog.json]   // JSON list of backlog items
 *     [--git-status=app/Foo.php,docs/x.md]
 *     [--hot-scopes=runtimes/python/voice_realtime/]
 *     [--available-lanes=docs,tests]
 *     [--max-packets=5]
 *     [--risk-policy=conservative]
 *     [--json]
 *
 * Read-only, deterministic. Emits up to five disjoint write-set packets the
 * operator can hand to parallel AI sessions, and reports withheld/blocked work.
 * It NEVER claims, patches, edits hot files or enables execution.
 *
 * With no options it runs a built-in safe-default backlog so the surface is
 * exercisable on its own.
 *
 * @see docs/engineering-knowledge-base/self-construction/work-splitter-contract.md
 */
class AtlasWorkSplitterContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:work-splitter-contract
        {--backlog= : path to a JSON file containing the backlog list}
        {--git-status= : comma-separated files already modified by another lane}
        {--hot-scopes= : comma-separated paths owned by another active front}
        {--available-lanes= : comma-separated lanes the session may use now}
        {--max-packets=5 : requested ceiling of emitted packets (capped at 5)}
        {--risk-policy=conservative : conservative|balanced}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · work splitter that emits up to five disjoint packets and withholds hot/unsafe work.';

    public function handle(AtlasWorkSplitterContractService $service): int
    {
        try {
            $input = [
                'backlog' => $this->backlog(),
                'current_git_status' => $this->list('git-status'),
                'hot_scopes' => $this->list('hot-scopes'),
                'available_lanes' => $this->list('available-lanes'),
                'max_packets' => (int) $this->option('max-packets'),
                'risk_policy' => (string) $this->option('risk-policy'),
            ];

            $result = $service->split($input);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['packet_count'] > 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'work_splitter_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    /**
     * Load the backlog from --backlog JSON, or a built-in safe default so the
     * command is exercisable with zero arguments.
     *
     * @return list<array<string,mixed>>
     */
    private function backlog(): array
    {
        $path = $this->option('backlog');
        if (is_string($path) && trim($path) !== '' && is_file(trim($path))) {
            $decoded = json_decode((string) file_get_contents(trim($path)), true);
            if (is_array($decoded)) {
                /** @var list<array<string,mixed>> $decoded */
                return $decoded;
            }
        }

        return $this->defaultBacklog();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function defaultBacklog(): array
    {
        $validator = 'php artisan atlas:aaeos:scope-validator-contract --json';

        return [
            [
                'id' => 'SC-DOCS',
                'lane' => 'docs',
                'objective' => 'Keep Self-Construction root and structural docs synchronized.',
                'write_files' => ['docs/engineering-knowledge-base/atlas-ai-self-construction-os.md'],
                'scope_validator_command' => $validator,
            ],
            [
                'id' => 'SC-CONTRACTS',
                'lane' => 'packet_contracts',
                'objective' => 'Maintain packet, splitter and validator contracts.',
                'write_files' => ['docs/engineering-knowledge-base/self-construction/work-splitter-contract.md'],
                'scope_validator_command' => $validator,
            ],
            [
                'id' => 'SC-TESTS',
                'lane' => 'tests',
                'objective' => 'Add focused read-only evidence assertions.',
                'write_files' => ['tests/Unit/Ai/Aaeos/Generated/AtlasWorkSplitterContractTest.php'],
                'scope_validator_command' => $validator,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function list(string $option): array
    {
        $raw = $this->option($option);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn ($v) => $v !== '',
        ));
    }
}
