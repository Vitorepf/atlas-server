<?php

namespace App\Console\Commands;

use App\Services\Ai\OperatorIntelligence\AtlasProjectStackLearner;
use App\Services\Ai\Support\JsonFileStore;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * "Atlas learns your projects, fast." Reads a project's real manifests + docs and records
 * what it is, its stack, and how to work in it — every fact cited to a source file, none
 * invented. Project-scoped + reversible (the artifact is a plain file the operator can
 * delete). This is the anti-hallucination project-onboarding surface.
 */
class AtlasLearnProjectCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:learn-project
        {path? : project root (default: current working directory)}
        {--persist : write the learned project knowledge to a reversible artifact}
        {--json : machine-readable output}';

    protected $description = 'Learn a project fast (stack, purpose, how-to-work) from its real files — every fact cited, nothing invented.';

    public function handle(AtlasProjectStackLearner $learner): int
    {
        $path = (string) ($this->argument('path') ?: getcwd());
        if (! is_dir($path)) {
            $this->error('Not a directory: '.$path);

            return self::FAILURE;
        }

        $knowledge = $learner->learn($path);

        if ($this->option('persist')) {
            $knowledge['persisted_to'] = $this->persist($knowledge);
        }

        if ((bool) $this->option('json')) {
            $this->line($this->encode($knowledge));

            return self::SUCCESS;
        }

        $this->info(sprintf('Learned "%s" — %d grounded facts (every one cites a source file):',
            $knowledge['project_name'], $knowledge['fact_count']));
        if ($knowledge['summary'] !== '') {
            $this->line('  Purpose: '.\Illuminate\Support\Str::limit($knowledge['summary'], 120));
        }
        if ($knowledge['stack'] !== []) {
            $this->line('  Stack:   '.implode(', ', $knowledge['stack']));
        }
        $rows = array_map(static fn (array $f): array => [$f['kind'], \Illuminate\Support\Str::limit((string) $f['fact'], 60), $f['source']], $knowledge['facts']);
        if ($rows !== []) {
            $this->table(['kind', 'fact', 'source'], $rows);
        }
        if (isset($knowledge['persisted_to'])) {
            $this->line('Persisted (reversible — delete the file to forget): '.$knowledge['persisted_to']);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $knowledge
     */
    private function persist(array $knowledge): string
    {
        $dir = storage_path('app/atlas/project-knowledge');
        $slug = preg_replace('/[^a-z0-9_-]+/i', '-', (string) $knowledge['project_name']) ?: 'project';
        $path = $dir.'/'.strtolower($slug).'.json';
        JsonFileStore::write($path, $knowledge, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $path;
    }
}
