<?php

namespace App\Console\Commands;

use App\Services\Ai\Cognitive\Dreyfus\DreyfusPedagogyResolver;
use App\Services\Ai\Cognitive\PersonalWorkedExample\PersonalWorkedExampleExtractionRepository;
use App\Services\Ai\Cognitive\PersonalWorkedExample\PersonalWorkedExampleExtractor;
use App\Services\Ai\Cognitive\WorkedExample\ProcessFadingScheduler;
use App\Services\Ai\Cognitive\WorkedExample\WorkedExampleRenderer;
use App\Services\Ai\Cognitive\WorkedExample\WorkedExampleRepository;
use App\Services\Ai\Cognitive\WorkedExample\WorkedExampleSelector;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Gates\WorkedExampleAppropriateForStageGate;
use Illuminate\Console\Command;

class AtlasWorkedExampleCommand extends Command
{
    protected $signature = 'atlas:worked-example
        {actionOrTopic? : Topic, or action: list|show|author|extract|personal}
        {subject? : Example id for show, or topic for author}
        {--domain=learning : Domain for the example}
        {--source=any : Source preference: canonical_library|personal_ledger|operator_authored|any}
        {--extract-source=all : Personal extraction source: all|programming_pr|strategic_decision|feynman_session}
        {--window=90 : Extraction/history window in days}
        {--limit=50 : Maximum extraction candidates or results}
        {--status=any : Extraction status filter for extract status mode}
        {--node= : Optional knowledge node id for personal mode}
        {--dreyfus-stage=auto : Explicit Dreyfus stage 1..5 or auto}
        {--title= : Title for author mode}
        {--problem= : Problem context for author mode}
        {--json : Print machine-readable JSON}';

    protected $description = 'Deliver, list, show, or author governed Atlas Cognitive worked examples.';

    public function handle(
        WorkedExampleRepository $examples,
        WorkedExampleSelector $selector,
        ProcessFadingScheduler $fading,
        WorkedExampleRenderer $renderer,
        DreyfusPedagogyResolver $dreyfus,
        WorkedExampleAppropriateForStageGate $gate,
        AtlasEvidenceLedger $ledger,
        PersonalWorkedExampleExtractor $personalExtractor,
        PersonalWorkedExampleExtractionRepository $personalExtractions,
    ): int {
        $actionOrTopic = trim((string) ($this->argument('actionOrTopic') ?? ''));
        $subject = trim((string) ($this->argument('subject') ?? ''));
        $domain = trim((string) $this->option('domain')) ?: 'learning';
        $source = trim((string) $this->option('source')) ?: 'any';

        return match ($actionOrTopic) {
            'list' => $this->render([
                'schema_version' => 'atlas.cognitive.worked_example_cli.v1',
                'status' => 'ok',
                'mode' => 'list',
                'domain' => $domain,
                'examples' => $examples->list($domain, $source),
            ]),
            'show' => $this->render([
                'schema_version' => 'atlas.cognitive.worked_example_cli.v1',
                'status' => 'ok',
                'mode' => 'show',
                'example' => is_numeric($subject) ? $examples->find((int) $subject) : null,
            ]),
            'author' => $this->author($examples, $ledger, $subject, $domain),
            'extract' => $this->extract($personalExtractor, $subject, $domain),
            'personal' => $this->personal($examples, $personalExtractions, $domain),
            default => $this->deliver($selector, $fading, $renderer, $dreyfus, $gate, $ledger, $actionOrTopic, $domain, $source),
        };
    }

    private function extract(PersonalWorkedExampleExtractor $extractor, string $subject, string $domain): int
    {
        if ($subject === 'status') {
            return $this->render($extractor->status(
                status: trim((string) $this->option('status')) ?: 'any',
                limit: $this->positiveIntOption('limit', 50),
            ));
        }

        if ($subject === 'schedule') {
            return $this->extractSchedule();
        }

        if ($subject === 'scheduled') {
            return $this->runScheduledExtraction($extractor);
        }

        return $this->render($extractor->run(
            source: trim((string) $this->option('extract-source')) ?: 'all',
            domain: $domain === 'learning' ? 'any' : $domain,
            days: $this->positiveIntOption('window', 90),
            limit: $this->positiveIntOption('limit', 50),
        ));
    }

    private function extractSchedule(): int
    {
        $operation = strtolower(trim((string) $this->option('status')) ?: 'status');
        $repository = app(PersonalWorkedExampleExtractionRepository::class);

        $payload = match ($operation) {
            'on', 'enable', 'enabled' => $repository->setScheduleEnabled(true),
            'off', 'disable', 'disabled' => $repository->setScheduleEnabled(false),
            default => $repository->scheduleStatus(),
        };

        return $this->render(array_merge($payload, [
            'mode' => 'extract_schedule',
            'operation' => $operation,
            'autonomy' => [
                'auto_extract_enabled' => (bool) data_get($payload, 'job.enabled', false),
                'auto_apply' => false,
                'review_required' => true,
                'scheduler_registered' => (bool) data_get($payload, 'scheduler_registered', false),
            ],
        ]));
    }

    private function runScheduledExtraction(PersonalWorkedExampleExtractor $extractor): int
    {
        $repository = app(PersonalWorkedExampleExtractionRepository::class);
        $readiness = $repository->scheduledRunReadiness();

        if (! (bool) ($readiness['ready_to_run'] ?? false)) {
            return $this->render([
                'schema_version' => 'atlas.cognitive.personal_extraction_schedule_run.v1',
                'status' => 'skipped',
                'mode' => 'extract_scheduled',
                'reason' => $readiness['skip_reason'] ?? 'not_ready',
                'schedule' => $readiness,
                'autonomy' => [
                    'auto_apply' => false,
                    'review_required' => true,
                    'scheduler_registered' => (bool) data_get($readiness, 'scheduler_registered', false),
                ],
            ]);
        }

        $filters = (array) data_get($readiness, 'job.source_filters', []);
        $sources = (array) ($filters['sources'] ?? []);
        $domains = (array) ($filters['domains'] ?? []);
        $source = count($sources) === 1 ? (string) $sources[0] : 'all';
        $domain = count($domains) === 1 ? (string) $domains[0] : 'any';
        $result = $extractor->run(
            source: $source,
            domain: $domain,
            days: $this->boundedInt((int) ($filters['window_days'] ?? 90), 1, 365),
            limit: $this->boundedInt((int) ($filters['max_candidates'] ?? 50), 1, 200),
        );
        $updatedSchedule = $repository->markScheduledRunCompleted((array) ($result['summary'] ?? []));

        return $this->render([
            'schema_version' => 'atlas.cognitive.personal_extraction_schedule_run.v1',
            'status' => 'ok',
            'mode' => 'extract_scheduled',
            'result' => $result,
            'schedule' => $updatedSchedule,
            'autonomy' => [
                'auto_apply' => false,
                'review_required' => true,
                'scheduler_registered' => true,
            ],
        ]);
    }

    private function personal(WorkedExampleRepository $examples, PersonalWorkedExampleExtractionRepository $extractions, string $domain): int
    {
        $node = trim((string) ($this->option('node') ?? ''));
        $items = $examples->list($domain, 'personal_ledger');

        if ($node !== '') {
            $items = array_values(array_filter($items, fn (array $example): bool => (string) ($example['knowledge_node_id'] ?? '') === $node));
        }

        return $this->render([
            'schema_version' => 'atlas.cognitive.worked_example_cli.v1',
            'status' => 'ok',
            'mode' => 'personal',
            'domain' => $domain,
            'node' => $node ?: null,
            'examples' => $items,
            'recent_extractions' => $extractions->list('extracted', $this->positiveIntOption('limit', 50)),
        ]);
    }

    private function author(WorkedExampleRepository $examples, AtlasEvidenceLedger $ledger, string $topic, string $domain): int
    {
        if ($topic === '') {
            return $this->render([
                'schema_version' => 'atlas.cognitive.worked_example_cli.v1',
                'status' => 'invalid_input',
                'reason' => 'topic_required_for_author',
            ], self::FAILURE);
        }

        $example = $examples->create(
            topic: $topic,
            domain: $domain,
            title: trim((string) ($this->option('title') ?? '')) ?: 'Worked Example: '.$topic,
            problemContext: trim((string) ($this->option('problem') ?? '')) ?: 'Operator-authored worked example for '.$topic.'.',
            solutionFull: $this->defaultSolution($topic),
            source: 'operator_authored',
        );

        $ledger->record(LedgerEventType::WorkedExampleAuthored, [
            'schema_version' => 'atlas.cognitive.worked_example_authored.v1',
            'worked_example_id' => $example['id'] ?? null,
            'knowledge_node_id' => $example['knowledge_node_id'] ?? null,
            'domain' => $domain,
            'source' => 'operator_authored',
        ], $this->ledgerContext('atlas.worked_example.author', (string) ($example['knowledge_node_id'] ?? $topic)));

        return $this->render([
            'schema_version' => 'atlas.cognitive.worked_example_cli.v1',
            'status' => 'authored',
            'mode' => 'author',
            'example' => $example,
        ]);
    }

    private function deliver(
        WorkedExampleSelector $selector,
        ProcessFadingScheduler $fading,
        WorkedExampleRenderer $renderer,
        DreyfusPedagogyResolver $dreyfus,
        WorkedExampleAppropriateForStageGate $gate,
        AtlasEvidenceLedger $ledger,
        string $topic,
        string $domain,
        string $source,
    ): int {
        if ($topic === '') {
            return $this->render([
                'schema_version' => 'atlas.cognitive.worked_example_cli.v1',
                'status' => 'invalid_input',
                'reason' => 'topic_required',
            ], self::FAILURE);
        }

        $resolution = $dreyfus->resolve([
            'topic' => $topic,
            'domain' => $domain,
            'flow' => 'learning.worked_example',
            'dreyfus_stage_target' => trim((string) $this->option('dreyfus-stage')),
            'surface_id' => 'atlas_worked_example_cli',
        ]);
        $selection = $selector->select((string) $resolution['knowledge_node_id'], $domain, $source);

        if (($selection['status'] ?? null) !== 'selected') {
            return $this->render([
                'schema_version' => 'atlas.cognitive.worked_example_cli.v1',
                'status' => 'missing',
                'mode' => 'deliver',
                'reason' => $selection['reason'] ?? 'no_example',
                'dreyfus' => $resolution,
                'selection' => $selection,
            ], self::FAILURE);
        }

        $example = (array) $selection['selected'];
        $schedule = $fading->schedule((int) $resolution['dreyfus_stage_resolved'], (array) ($example['fading_levels'] ?? []));
        $gateResult = $gate->evaluate([
            'domain' => $domain,
            'surface_id' => 'atlas_worked_example_cli',
            'dreyfus_stage' => $resolution['dreyfus_stage_resolved'],
            'fading_level_resolved' => $schedule['fading_level_resolved'],
        ]);

        if (($gateResult['status'] ?? null) !== 'passed') {
            return $this->render([
                'schema_version' => 'atlas.cognitive.worked_example_cli.v1',
                'status' => 'blocked',
                'mode' => 'deliver',
                'gate' => $gateResult,
            ], self::FAILURE);
        }

        $rendered = $renderer->render($example, $schedule);
        app(WorkedExampleRepository::class)->markDelivered((int) $example['id']);
        $ledger->record(LedgerEventType::WorkedExampleDelivered, [
            'schema_version' => 'atlas.cognitive.worked_example_delivered.v1',
            'worked_example_id' => $example['id'] ?? null,
            'knowledge_node_id' => $resolution['knowledge_node_id'],
            'domain' => $domain,
            'dreyfus_stage' => $resolution['dreyfus_stage_resolved'],
            'fading_level_resolved' => $schedule['fading_level_resolved'],
            'source' => $example['source'] ?? null,
        ], $this->ledgerContext('atlas.worked_example.deliver', (string) $resolution['knowledge_node_id']));

        return $this->render([
            'schema_version' => 'atlas.cognitive.worked_example_cli.v1',
            'status' => 'ok',
            'mode' => 'deliver',
            'dreyfus' => $resolution,
            'selection' => $selection,
            'gate' => $gateResult,
            'worked_example' => $rendered,
        ]);
    }

    /**
     * @return array<int,array<string,string|int>>
     */
    private function defaultSolution(string $topic): array
    {
        return [
            ['step' => 1, 'action' => 'Defina o resultado esperado para '.$topic.'.', 'reasoning' => 'Processo bom comeca pelo criterio de sucesso.', 'why_works' => 'Evita executar atividade sem alvo.'],
            ['step' => 2, 'action' => 'Liste restricoes, riscos e entradas disponiveis.', 'reasoning' => 'Restricoes moldam a solucao real.', 'why_works' => 'Reduz retrabalho.'],
            ['step' => 3, 'action' => 'Execute o menor caminho verificavel.', 'reasoning' => 'Feedback curto vence plano grande sem evidencia.', 'why_works' => 'Cria loop de aprendizado.'],
            ['step' => 4, 'action' => 'Compare resultado contra criterio inicial.', 'reasoning' => 'Sem comparacao nao ha melhoria.', 'why_works' => 'Torna progresso mensuravel.'],
            ['step' => 5, 'action' => 'Registre o padrao reutilizavel e a falha evitada.', 'reasoning' => 'O exemplo vira capital cognitivo.', 'why_works' => 'Permite transferencia futura.'],
        ];
    }

    private function render(array $payload, int $exit = self::SUCCESS): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }

        $this->components->twoColumnDetail('Atlas Worked Example', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Mode', (string) ($payload['mode'] ?? 'n/a'));
        $this->components->twoColumnDetail('Title', (string) data_get($payload, 'worked_example.title', data_get($payload, 'example.title', 'n/a')));

        return $exit;
    }

    private function positiveIntOption(string $name, int $default): int
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_INT);

        return is_int($value) && $value > 0 ? $value : $default;
    }

    private function boundedInt(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    /**
     * @return array<string,mixed>
     */
    private function ledgerContext(string $stage, string $node): array
    {
        return [
            'tenant_id' => 'default',
            'operator_id' => 'atlas_worked_example_cli',
            'envelope_id' => 'worked_example:'.$node,
            'correlation_id' => 'worked_example:'.$node,
            'emitter_stage' => $stage,
            'emitter_version' => 'atlas.cognitive.worked_example_cli.v1',
        ];
    }
}
