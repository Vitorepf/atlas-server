<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\AtlasSoftwareTwinRuntimeService;
use Illuminate\Console\Command;
use App\Support\YesNo;

final class AtlasSoftwareTwinCommand extends Command
{
    protected $signature = 'atlas:software-twin
        {action=twin : twin|impact|context-envelope|quality-score|snapshot|simulate}
        {--target= : Path, symbol or runtime target}
        {--task= : Task text for context envelope}
        {--kind=doc : simulate: proposed artifact kind (doc|symbol)}
        {--slug= : simulate: proposed doc slug}
        {--graph-id= : simulate: proposed doc graph_id}
        {--owner= : simulate: proposed owner/area}
        {--capability=* : simulate: proposed capability (repeatable)}
        {--governs=* : simulate: proposed governs needle (repeatable)}
        {--implementation-state= : simulate: proposed implementation_state}
        {--evidence-ref=* : simulate: proposed evidence_ref "kind: ref" (repeatable)}
        {--symbol= : simulate: proposed symbol name (kind=symbol)}
        {--extends= : simulate: existing target the proposal names/extends (blast radius)}
        {--json-input= : simulate: full proposal as a JSON blob (overrides flags)}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless ready}';

    protected $description = 'Operate ASTR, the Atlas Software Twin Runtime, as a read-only living system twin.';

    public function handle(AtlasSoftwareTwinRuntimeService $service): int
    {
        $action = (string) $this->argument('action');
        $target = (string) ($this->option('target') ?: '');
        $payload = match ($action) {
            'twin' => $service->twin($target),
            'impact' => $service->impact($target),
            'context-envelope' => $service->contextEnvelope((string) ($this->option('task') ?: $target ?: 'software twin context envelope'), $target),
            'quality-score' => $service->qualityScore(),
            'snapshot' => $service->snapshot($target),
            'simulate' => $service->simulate($this->proposal()),
            default => null,
        };

        if ($payload === null) {
            $this->error('Unknown action. Expected twin, impact, context-envelope, quality-score, snapshot or simulate.');

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return $this->exitCode($payload);
        }

        $this->components->twoColumnDetail('Atlas Software Twin', (string) $payload['status']);
        $this->components->twoColumnDetail('Action', (string) ($payload['action'] ?? $action));
        if (isset($payload['prediction']['verdict'])) {
            $this->components->twoColumnDetail('Verdict', (string) $payload['prediction']['verdict']);
        }
        $this->components->twoColumnDetail('Writes', YesNo::format($payload['writes']));
        $this->components->twoColumnDetail('Hash', (string) $payload['certification_hash']);

        return $this->exitCode($payload);
    }

    /**
     * Build the proposed-artifact payload for simulate, from a --json-input blob
     * (authoritative if present and valid) or from the discrete flags.
     *
     * @return array<string,mixed>
     */
    private function proposal(): array
    {
        $jsonInput = (string) ($this->option('json-input') ?: '');
        if (trim($jsonInput) !== '') {
            $decoded = json_decode($jsonInput, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            $this->warn('Ignoring --json-input: not a valid JSON object.');
        }

        $evidenceRefs = array_values(array_filter(array_map(
            'trim',
            (array) $this->option('evidence-ref'),
        ), static fn (string $ref): bool => $ref !== ''));

        return array_filter([
            'kind' => (string) ($this->option('kind') ?: 'doc'),
            'slug' => (string) ($this->option('slug') ?: ''),
            'graph_id' => (string) ($this->option('graph-id') ?: ''),
            'owner' => (string) ($this->option('owner') ?: ''),
            'capabilities' => array_values((array) $this->option('capability')),
            'governs' => array_values((array) $this->option('governs')),
            'implementation_state' => (string) ($this->option('implementation-state') ?: ''),
            'evidence_refs' => $evidenceRefs,
            'symbol_name' => (string) ($this->option('symbol') ?: ''),
            'extends' => (string) ($this->option('extends') ?: ''),
        ], static fn ($value): bool => $value !== '' && $value !== []);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function exitCode(array $payload): int
    {
        if ((bool) $this->option('strict') && isset($payload['prediction']['verdict'])) {
            return $payload['prediction']['verdict'] === 'clean' ? self::SUCCESS : self::FAILURE;
        }

        return (bool) $this->option('strict') && $payload['status'] !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
