<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\AtlasCodeRealityUsageIntelligenceService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

final class AtlasCodeRealityCommand extends Command
{
    protected $signature = 'atlas:code-reality
        {action=classify : classify|usage-map|reachability|anti-duplicate|dead-code-candidates|deletion-preflight|reality-audit|global-duplication-audit|status-drift-audit|context-pack}
        {--target= : Path, symbol or runtime target}
        {--feature= : Feature name for anti-duplicate}
        {--task= : Task text for provider-safe context pack}
        {--json : Emit canonical JSON}
        {--summary : Emit compact JSON for large audit payloads}
        {--strict : Exit non-zero unless ready}';

    protected $description = 'Read-only ACRUI operational reality classifier, reachability and anti-duplication gate.';

    public function handle(AtlasCodeRealityUsageIntelligenceService $service): int
    {
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'classify' => $service->classify((string) ($this->option('target') ?: '')),
            'usage-map' => $service->usageMap((string) ($this->option('target') ?: '')),
            'reachability' => $service->reachability((string) ($this->option('target') ?: '')),
            'anti-duplicate' => $service->antiDuplicate((string) ($this->option('feature') ?: $this->option('target') ?: '')),
            'dead-code-candidates' => $service->deadCodeCandidates(),
            'deletion-preflight' => $service->deletionPreflight((string) ($this->option('target') ?: '')),
            'reality-audit' => $service->realityAudit(),
            'global-duplication-audit' => $service->globalDuplicationAudit(),
            'status-drift-audit' => $service->statusDriftAudit(),
            'context-pack' => $service->contextPack((string) ($this->option('task') ?: $this->option('feature') ?: $this->option('target') ?: '')),
            default => null,
        };

        if ($payload === null) {
            $this->error('Unknown action. Expected classify, usage-map, reachability, anti-duplicate, dead-code-candidates, deletion-preflight, reality-audit, global-duplication-audit, status-drift-audit or context-pack.');

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $jsonPayload = (bool) $this->option('summary') ? $this->summaryPayload($payload) : $payload;
            $json = json_encode($jsonPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
            $this->output->write($json.PHP_EOL, false, OutputInterface::OUTPUT_RAW);

            return $this->exitCode($payload);
        }

        $this->components->twoColumnDetail('Atlas Code Reality', (string) $payload['status']);
        $this->components->twoColumnDetail('Action', (string) ($payload['action'] ?? $action));
        $this->components->twoColumnDetail('Writes', $payload['writes'] ? 'yes' : 'no');
        if (isset($payload['classification'])) {
            $this->components->twoColumnDetail('Classification', (string) $payload['classification']);
        }
        if (isset($payload['decision'])) {
            $this->components->twoColumnDetail('Decision', (string) $payload['decision']);
        }
        $this->components->twoColumnDetail('Hash', (string) $payload['certification_hash']);

        return $this->exitCode($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function exitCode(array $payload): int
    {
        return (bool) $this->option('strict') && $payload['status'] !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function summaryPayload(array $payload): array
    {
        return [
            'schema_version' => $payload['schema_version'] ?? AtlasCodeRealityUsageIntelligenceService::SCHEMA_VERSION,
            'status' => $payload['status'] ?? 'unknown',
            'action' => $payload['action'] ?? (string) $this->argument('action'),
            'scope' => $payload['scope'] ?? null,
            'writes' => $payload['writes'] ?? null,
            'summary' => $payload['summary'] ?? null,
            'blockers' => $payload['blockers'] ?? [],
            'review_items' => array_slice((array) ($payload['review_items'] ?? []), 0, 30),
            'triage_queue_sample' => $this->queueSample((array) ($payload['triage_queue'] ?? []), 5),
            'ai_confusion_cleanup_queue_sample' => $this->queueSample((array) ($payload['ai_confusion_cleanup_queue'] ?? []), 5),
            'claim_policy' => $payload['claim_policy'] ?? null,
            'certification_hash' => $payload['certification_hash'] ?? null,
            'summary_output' => [
                'enabled' => true,
                'omits_heavy_sections' => [
                    'documentation',
                    'code',
                    'topic_clusters',
                    'critical_topic_pressure',
                    'status_drift',
                ],
                'full_payload_command' => 'php artisan atlas:code-reality '.(string) $this->argument('action').' --json',
            ],
        ];
    }

    /**
     * @param  array<int,mixed>  $items
     * @return array<int,mixed>
     */
    private function queueSample(array $items, int $limit): array
    {
        return array_values(array_map(
            fn (mixed $item): mixed => is_array($item) ? $this->queueSampleItem($item) : $item,
            array_slice($items, 0, $limit)
        ));
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function queueSampleItem(array $item): array
    {
        $sample = array_intersect_key($item, array_flip([
            'id',
            'kind',
            'severity',
            'status',
            'source',
            'path',
            'action',
            'summary',
            'required_decision',
            'claim_policy',
        ]));

        foreach (['next_commands', 'method_uris', 'paths'] as $key) {
            if (isset($item[$key]) && is_array($item[$key])) {
                $sample[$key] = array_slice($item[$key], 0, 5);
            }
        }

        return $sample;
    }
}
