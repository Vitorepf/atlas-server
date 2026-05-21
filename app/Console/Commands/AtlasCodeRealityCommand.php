<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\AtlasCodeRealityUsageIntelligenceService;
use Illuminate\Console\Command;

final class AtlasCodeRealityCommand extends Command
{
    protected $signature = 'atlas:code-reality
        {action=classify : classify|usage-map|reachability|anti-duplicate|dead-code-candidates|deletion-preflight|reality-audit|context-pack}
        {--target= : Path, symbol or runtime target}
        {--feature= : Feature name for anti-duplicate}
        {--task= : Task text for provider-safe context pack}
        {--json : Emit canonical JSON}
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
            'context-pack' => $service->contextPack((string) ($this->option('task') ?: $this->option('feature') ?: $this->option('target') ?: '')),
            default => null,
        };

        if ($payload === null) {
            $this->error('Unknown action. Expected classify, usage-map, reachability, anti-duplicate, dead-code-candidates, deletion-preflight, reality-audit or context-pack.');

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

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
}
