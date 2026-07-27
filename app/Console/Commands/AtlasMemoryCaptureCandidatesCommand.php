<?php

namespace App\Console\Commands;

use App\Services\Ai\Memory\AtlasMemoryCandidateGateService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;
use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;

class AtlasMemoryCaptureCandidatesCommand extends Command
{
    use ReadsNonEmptyStringOption;

    use EmitsCanonicalJson;

    protected $signature = 'atlas:memory:capture-candidates
        {--source= : Real source path to extract from}
        {--title= : Candidate title}
        {--summary= : Candidate summary}
        {--body= : Candidate body with rationale and provenance}
        {--type=technical_context : Atlas memory type}
        {--scope-type=global : Atlas memory scope type}
        {--scope-id= : Atlas memory scope id}
        {--path=* : Existing repo-relative paths cited by the candidate}
        {--domain= : Canonical domain tag}
        {--segment-source=excerpt : Injection-boundary source for the candidate body}
        {--confidence=0.75 : Candidate confidence}
        {--priority=70 : Candidate priority}
        {--apply : Run automatic gate and admit passing candidates now}
        {--json : Print machine-readable JSON}';

    protected $description = 'Capture Atlas memory candidates outside atlas_memory_entries and auto-admit those passing quality gates.';

    public function handle(AtlasMemoryCandidateGateService $gate): int
    {
        if (! DatabaseTableAvailability::has('atlas_memory_candidates')) {
            $this->error('Tabela atlas_memory_candidates ainda nao existe. Rode migrations.');

            return self::FAILURE;
        }

        $result = $gate->capture([
            'source' => $this->stringOption('source'),
            'title' => $this->stringOption('title'),
            'summary' => $this->stringOption('summary'),
            'body' => $this->stringOption('body'),
            'type' => $this->stringOption('type'),
            'scope_type' => $this->stringOption('scope-type'),
            'scope_id' => $this->stringOption('scope-id'),
            'paths' => array_values(array_filter((array) $this->option('path'), 'is_string')),
            'domain' => $this->stringOption('domain'),
            'segment_source' => $this->stringOption('segment-source'),
            'confidence' => (float) $this->option('confidence'),
            'priority' => (int) $this->option('priority'),
        ], (bool) $this->option('apply'));

        $payload = [
            'ok' => true,
            'candidate' => $this->candidateRow($result['candidate']),
            'admitted_memory_entry_id' => $result['entry']?->id,
            'quality' => $result['quality'],
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Memory candidate</>', (string) $payload['candidate']['id']);
        $this->components->twoColumnDetail('Status', (string) $payload['candidate']['status']);
        $this->components->twoColumnDetail('Missing checks', implode(', ', (array) $payload['quality']['missing_checks']));
        if ($payload['admitted_memory_entry_id']) {
            $this->components->twoColumnDetail('Admitted entry', (string) $payload['admitted_memory_entry_id']);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function candidateRow(mixed $candidate): array
    {
        return [
            'id' => (string) $candidate->id,
            'status' => (string) $candidate->status,
            'memory_type' => (string) $candidate->memory_type,
            'scope_type' => (string) $candidate->scope_type,
            'scope_id' => $candidate->scope_id,
            'title' => (string) $candidate->title,
            'missing_checks' => $candidate->missing_checks ?? [],
            'memory_entry_id' => $candidate->memory_entry_id,
            'injection_boundary' => (array) data_get($candidate->candidate_payload, 'injection_boundary', []),
        ];
    }

}
