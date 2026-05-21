<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasSemanticEmbeddingFoundationService;
use Illuminate\Console\Command;

final class AtlasSemanticEmbeddingFoundationCommand extends Command
{
    protected $signature = 'atlas:context:semantic-foundation
        {--json : Emit canonical JSON}
        {--source=* : Optional local text/markdown file to inspect as read-only candidate source}';

    protected $description = 'Build the AUCRI ASEF manifest/readiness without external embeddings or vector-store writes.';

    public function handle(AtlasSemanticEmbeddingFoundationService $service): int
    {
        $sources = $this->sourcesFromOptions();
        $payload = $sources === []
            ? $service->readiness()
            : $service->candidateSet($sources);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Semantic Embedding Foundation', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);

        if (isset($payload['summary'])) {
            $this->components->twoColumnDetail('Chunks', (string) data_get($payload, 'summary.chunks', 0));
            $this->components->twoColumnDetail('Provider-safe chunks', (string) data_get($payload, 'summary.provider_safe_chunks', 0));
        } else {
            $this->components->twoColumnDetail('Embedding policy', (string) data_get($payload, 'embedding_policy.provider'));
            $this->components->twoColumnDetail('Laravel scope', (string) data_get($payload, 'embedding_policy.laravel_scope'));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function sourcesFromOptions(): array
    {
        $sources = [];

        foreach ((array) $this->option('source') as $sourcePath) {
            $path = (string) $sourcePath;
            if ($path === '' || ! is_file($path)) {
                continue;
            }

            $sources[] = [
                'source_ref' => $path,
                'text' => (string) file_get_contents($path),
                'privacy_class' => 'normal',
                'authority_level' => 'repo_file',
            ];
        }

        return $sources;
    }
}
