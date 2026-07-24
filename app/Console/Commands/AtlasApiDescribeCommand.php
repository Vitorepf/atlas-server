<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * O2 · the APP-READY surface catalog — the machine-readable front door a sibling surface
 * (mobile/desktop app) uses to DISCOVER the terminal product API: which command serves each
 * product area, how to invoke it for JSON, and where its schema version lives.
 *
 * `--check` runs every runnable entry live and reports conformance (json_valid +
 * version_key present) — the standing meter of the app contract, not a one-off claim.
 */
class AtlasApiDescribeCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:api:describe
        {--check : Run every runnable entry and report live JSON conformance}
        {--json : Print machine-readable JSON (default; text renders a table)}';

    protected $description = 'Catálogo machine-readable da superfície de produto do terminal (comandos-núcleo, invocação JSON, schemas) para apps irmãos.';

    /**
     * The product-area catalog. `version_key` is where the payload carries its schema
     * version (null = unversioned payload, catalogued honestly). `run` is the artisan
     * invocation `--check` uses; null = not runnable without input/interaction.
     *
     * PUBLIC: o harness de performance (atlas:api:perf) mede exatamente este catálogo —
     * uma fonte, dois medidores (conformidade e latência).
     *
     * @var array<int,array<string,mixed>>
     */
    public const CATALOG = [
        ['area' => 'cockpit', 'command' => 'atlas:cli:cockpit', 'json_invocation' => 'atlas:cli:cockpit --json', 'version_key' => 'schema_version', 'known_schema' => 'atlas.cli.cockpit.v1', 'run' => ['atlas:cli:cockpit', ['--json' => true, '--landings' => 1]]],
        ['area' => 'inbox', 'command' => 'atlas:cli:inbox', 'json_invocation' => 'atlas:cli:inbox list --json', 'version_key' => null, 'known_schema' => null, 'run' => ['atlas:cli:inbox', ['action' => 'list', '--json' => true, '--limit' => 1]]],
        ['area' => 'review_publish', 'command' => 'atlas:task:review:publish', 'json_invocation' => 'atlas:task:review:publish --json', 'version_key' => 'schema_version', 'known_schema' => 'atlas.task_landing.review_publisher.v1', 'run' => null /* publica itens reais — não rodar em check */],
        ['area' => 'review_deep', 'command' => 'atlas:task:review:deep', 'json_invocation' => 'atlas:task:review:deep <sha|task_packet_id> --json', 'version_key' => 'schema_version', 'known_schema' => 'atlas.task_landing.deep_review.v1', 'run' => null /* requer ref */],
        ['area' => 'brain', 'command' => 'atlas:brain:metrics', 'json_invocation' => 'atlas:brain:metrics --format=json', 'version_key' => null, 'known_schema' => null, 'run' => ['atlas:brain:metrics', ['--format' => 'json']]],
        ['area' => 'task_health', 'command' => 'atlas:task:health', 'json_invocation' => 'atlas:task:health --json', 'version_key' => 'schema', 'known_schema' => 'atlas.task_serving.coordination_health.v1', 'run' => ['atlas:task:health', ['--json' => true]]],
        ['area' => 'autonomy', 'command' => 'atlas:autonomy:status', 'json_invocation' => 'atlas:autonomy:status --json', 'version_key' => 'schema_version', 'known_schema' => 'atlas.loop.autonomy_tier_status.v1', 'run' => ['atlas:autonomy:status', ['--json' => true]]],
        ['area' => 'obra', 'command' => 'atlas:code:obra-command-center', 'json_invocation' => 'atlas:code:obra-command-center --json', 'version_key' => 'schema_version', 'known_schema' => null, 'run' => ['atlas:code:obra-command-center', ['--json' => true]]],
        ['area' => 'memory', 'command' => 'atlas:memory:list', 'json_invocation' => 'atlas:memory:list --json', 'version_key' => null, 'known_schema' => 'atlas.memory_entry.safety.v1 (por entry)', 'run' => ['atlas:memory:list', ['--json' => true]]],
        ['area' => 'context', 'command' => 'atlas:context-pack', 'json_invocation' => 'atlas:context-pack "<task>" --json', 'version_key' => 'schema', 'known_schema' => null, 'run' => null /* requer task */],
        ['area' => 'dashboard', 'command' => 'atlas:cli:dashboard', 'json_invocation' => 'atlas:cli:dashboard --json', 'version_key' => null, 'known_schema' => null, 'run' => ['atlas:cli:dashboard', ['--json' => true]]],
        ['area' => 'chat', 'command' => 'atlas:ai:chat', 'json_invocation' => null, 'version_key' => null, 'known_schema' => null, 'run' => null /* interativo — app irmão usa o gateway HTTP mobile */],
    ];

    public function handle(): int
    {
        $entries = array_map(static function (array $entry): array {
            $entry['runnable'] = $entry['run'] !== null;
            $entry['conformance'] = $entry['json_invocation'] === null
                ? 'interactive_only'
                : ($entry['version_key'] !== null ? 'versioned' : 'json_unversioned');
            unset($entry['run']);

            return $entry;
        }, self::CATALOG);

        $payload = [
            'schema_version' => 'atlas.api.catalog.v1',
            'purpose' => 'Superfície de produto do terminal para apps irmãos (mobile/desktop) — mesmo backend, superfície irmã.',
            'areas_count' => count($entries),
            'catalog' => $entries,
        ];

        if ((bool) $this->option('check')) {
            $payload['check'] = $this->check();
        }

        if ((bool) $this->option('json') || true) { // JSON é o default deste comando (catálogo é pra máquinas)
            $this->line($this->encode($payload));
        }

        $failed = collect($payload['check']['results'] ?? [])->where('ok', false)->count();

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Live conformance: run every runnable catalog entry, validate the output parses as
     * JSON and (when declared) carries its version key. Failures are reported, never thrown.
     *
     * @return array<string,mixed>
     */
    private function check(): array
    {
        $results = [];
        foreach (self::CATALOG as $entry) {
            if ($entry['run'] === null) {
                continue;
            }
            [$command, $params] = $entry['run'];
            $result = ['area' => $entry['area'], 'command' => $command, 'ok' => false];
            try {
                $buffer = new BufferedOutput;
                Artisan::call($command, $params, $buffer);
                $decoded = json_decode(trim($buffer->fetch()), true);
                if (! is_array($decoded)) {
                    $result['reason'] = 'output_not_json';
                } elseif ($entry['version_key'] !== null && ! array_key_exists($entry['version_key'], $decoded)) {
                    $result['reason'] = 'version_key_missing:'.$entry['version_key'];
                } else {
                    $result['ok'] = true;
                    if ($entry['version_key'] !== null) {
                        $result['schema'] = $decoded[$entry['version_key']];
                    }
                }
            } catch (Throwable $e) {
                $result['reason'] = 'exception: '.mb_substr($e->getMessage(), 0, 160);
            }
            $results[] = $result;
        }

        return [
            'ran' => count($results),
            'passed' => count(array_filter($results, static fn (array $r): bool => $r['ok'])),
            'results' => $results,
        ];
    }
}
