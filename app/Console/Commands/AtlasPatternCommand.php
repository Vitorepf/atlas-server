<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Services\Ai\Learning\Pattern\ProcessPatternEvidenceTracker;
use App\Services\Ai\Learning\Pattern\ProcessPatternMatcher;
use App\Services\Ai\Learning\Pattern\ProcessPatternRepository;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasPatternCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:pattern
        {actionOrName? : Pattern name or action: catalog|matcher|author|apply}
        {subject? : Problem description for matcher, pattern name for apply/author}
        {--category= : Optional category}
        {--outcome=partial : Outcome for apply: success|partial|failure|abandoned}
        {--intent= : Intent for author mode}
        {--context= : Problem context for author mode}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect, author, match and apply Atlas Cognitive Process Patterns.';

    public function handle(
        ProcessPatternRepository $patterns,
        ProcessPatternMatcher $matcher,
        ProcessPatternEvidenceTracker $tracker,
        AtlasEvidenceLedger $ledger,
    ): int {
        $action = trim((string) ($this->argument('actionOrName') ?? 'catalog')) ?: 'catalog';
        $subject = trim((string) ($this->argument('subject') ?? ''));
        $category = $this->stringOption('category');

        return match ($action) {
            'catalog' => $this->render([
                'schema_version' => 'atlas.cognitive.process_pattern_cli.v1',
                'status' => 'ok',
                'mode' => 'catalog',
                'patterns' => $patterns->catalog($category),
            ]),
            'matcher' => $this->render($matcher->match($subject, $category)),
            'author' => $this->author($patterns, $ledger, $subject, $category),
            'apply' => $this->apply($patterns, $tracker, $subject),
            default => $this->render([
                'schema_version' => 'atlas.cognitive.process_pattern_cli.v1',
                'status' => 'ok',
                'mode' => 'show',
                'pattern' => $patterns->findByName($action),
            ]),
        };
    }

    private function author(ProcessPatternRepository $patterns, AtlasEvidenceLedger $ledger, string $name, ?string $category): int
    {
        if ($name === '') {
            return $this->render(['status' => 'invalid_input', 'reason' => 'name_required_for_author'], self::FAILURE);
        }

        $pattern = $patterns->upsert([
            'name' => $name,
            'category' => $category ?: 'process',
            'intent' => $this->stringOption('intent') ?: 'Reusable operator-authored process pattern.',
            'problem_context' => $this->stringOption('context') ?: 'Operator-authored pattern awaiting richer evidence.',
            'forces' => [['name' => 'clarity', 'description' => 'Needs reusable decision/process structure.']],
            'solution' => ['abstract' => 'Apply '.$name, 'steps' => ['name context', 'apply pattern', 'measure outcome']],
            'consequences' => ['pros' => ['reusable process memory'], 'cons' => ['requires review'], 'trade_offs' => []],
            'created_via' => 'operator',
            'status' => 'active',
        ]);

        $ledger->record(LedgerEventType::ProcessPatternCataloged, [
            'schema_version' => 'atlas.cognitive.process_pattern_cataloged.v1',
            'name' => $pattern['name'] ?? $name,
            'category' => $pattern['category'] ?? $category,
            'created_via' => 'operator',
        ], $this->ledgerContext('atlas.pattern.author', $pattern['name'] ?? $name));

        return $this->render([
            'schema_version' => 'atlas.cognitive.process_pattern_cli.v1',
            'status' => $pattern['status'] === 'invalid' ? 'invalid' : 'authored',
            'mode' => 'author',
            'pattern' => $pattern,
        ], $pattern['status'] === 'invalid' ? self::FAILURE : self::SUCCESS);
    }

    private function apply(ProcessPatternRepository $patterns, ProcessPatternEvidenceTracker $tracker, string $name): int
    {
        $pattern = $patterns->findByName($name);
        if ($pattern === null) {
            return $this->render(['status' => 'missing', 'reason' => 'pattern_not_found'], self::FAILURE);
        }

        return $this->render([
            'schema_version' => 'atlas.cognitive.process_pattern_cli.v1',
            'status' => 'applied',
            'mode' => 'apply',
            'pattern' => $pattern,
            'application' => $tracker->apply((int) $pattern['id'], (string) ($pattern['category'] ?? 'process'), (string) $this->option('outcome')),
        ]);
    }

    private function render(array $payload, int $exit = self::SUCCESS): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $exit;
        }

        $this->components->twoColumnDetail('Atlas Pattern', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Mode', (string) ($payload['mode'] ?? data_get($payload, 'schema_version', 'n/a')));

        return $exit;
    }


    /**
     * @return array<string,mixed>
     */
    private function ledgerContext(string $stage, string $name): array
    {
        return [
            'tenant_id' => 'default',
            'operator_id' => 'atlas_pattern_cli',
            'envelope_id' => 'process_pattern:'.$name,
            'correlation_id' => 'process_pattern:'.$name,
            'emitter_stage' => $stage,
            'emitter_version' => 'atlas.cognitive.process_pattern.v1',
        ];
    }
}
