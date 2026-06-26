<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Support\AiStringListNormalizer;
use Closure;

/**
 * VERIFICATION-ATOM NORMALIZER concern, extracted from the god-class
 * {@see AtlasLoopIntentVerifierFactory}.
 *
 * Owns the full atom-normalizing cluster: the top-level verificationAtoms()
 * collector, the type-discriminator dispatch (atomHandlers), the per-type
 * normalize*Atom() helpers, normalizeEventTrigger(), normalizeWhere(),
 * isSafeSqlIdentifier() and normalizeCountOperator().
 *
 * Capabilities that STAY in the factory (literal / literalList and the
 * payload-helper / inference methods) are passed in as Closures — the SAME
 * closure-binding pattern used by AtlasLoopRefillerSupplyLaneCoordinator.
 */
final class AtlasLoopVerificationAtomNormalizer
{
    /**
     * @param  Closure(mixed): mixed  $literal
     * @param  Closure(mixed): list<mixed>  $literalList
     * @param  Closure(string, array<string,mixed>): ?array<string,mixed>  $eventAtomFromPayload
     * @param  Closure(string, array<string,mixed>): ?array<string,mixed>  $jobAtomFromPayload
     * @param  Closure(string, array<string,mixed>): ?array<string,mixed>  $dbStateAtomFromPayload
     * @param  Closure(string): ?array<string,mixed>  $inferMethodReturnAtom
     * @param  Closure(string): ?array<string,mixed>  $inferCommandOutputAtom
     */
    public function __construct(
        private readonly Closure $literal,
        private readonly Closure $literalList,
        private readonly Closure $eventAtomFromPayload,
        private readonly Closure $jobAtomFromPayload,
        private readonly Closure $dbStateAtomFromPayload,
        private readonly Closure $inferMethodReturnAtom,
        private readonly Closure $inferCommandOutputAtom,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return list<array<string,mixed>>
     */
    public function verificationAtoms(array $payload, string $intent): array
    {
        $atoms = [];
        foreach (is_array($payload['verification_atoms'] ?? null) ? $payload['verification_atoms'] : [] as $atom) {
            if (is_array($atom)) {
                $normalized = $this->normalizeAtom($atom);
                if ($normalized !== null) {
                    $atoms[] = $normalized;
                }
            }
        }

        $method = trim((string) ($payload['method'] ?? $payload['expected_method'] ?? ''));
        if ($method !== '' && array_key_exists('returns', $payload)) {
            $atoms[] = $this->normalizeAtom([
                'type' => 'method_return',
                'method' => $method,
                'expected' => $payload['returns'],
                'constructor_args' => $payload['constructor_args'] ?? [],
                'method_args' => $payload['method_args'] ?? [],
            ]);
        }
        $command = trim((string) ($payload['command'] ?? $payload['expected_command'] ?? ''));
        if ($command !== '' && (array_key_exists('output_contains', $payload) || array_key_exists('output_exact', $payload))) {
            $atoms[] = $this->normalizeAtom([
                'type' => 'command_output',
                'command' => $command,
                'output_contains' => $payload['output_contains'] ?? null,
                'output_exact' => $payload['output_exact'] ?? null,
                'exit_code' => $payload['exit_code'] ?? 0,
            ]);
        }
        $httpPath = trim((string) ($payload['http_path'] ?? ''));
        if ($httpPath !== '' && (array_key_exists('http_body_contains', $payload) || array_key_exists('http_body_exact', $payload) || array_key_exists('http_status', $payload))) {
            $atoms[] = $this->normalizeAtom([
                'type' => 'http_response',
                'method' => $payload['http_method'] ?? 'GET',
                'path' => $httpPath,
                'status' => $payload['http_status'] ?? 200,
                'body_contains' => $payload['http_body_contains'] ?? null,
                'body_exact' => $payload['http_body_exact'] ?? null,
            ]);
        }
        $event = trim((string) ($payload['event_class'] ?? $payload['expected_event'] ?? ''));
        if ($event !== '') {
            $eventAtom = ($this->eventAtomFromPayload)($event, $payload);
            if ($eventAtom !== null) {
                $atoms[] = $eventAtom;
            }
        }
        $job = trim((string) ($payload['job_class'] ?? $payload['expected_job'] ?? ''));
        if ($job !== '') {
            $jobAtom = ($this->jobAtomFromPayload)($job, $payload);
            if ($jobAtom !== null) {
                $atoms[] = $jobAtom;
            }
        }
        $table = trim((string) ($payload['db_table'] ?? $payload['expected_db_table'] ?? ''));
        if ($table !== '') {
            $dbAtom = ($this->dbStateAtomFromPayload)($table, $payload);
            if ($dbAtom !== null) {
                $atoms[] = $dbAtom;
            }
        }

        if ($atoms === []) {
            $inferred = ($this->inferMethodReturnAtom)($intent) ?? ($this->inferCommandOutputAtom)($intent);
            if ($inferred !== null) {
                $atoms[] = $inferred;
            }
        }

        return array_values(array_filter($atoms));
    }

    /**
     * @param  array<string,mixed>  $atom
     * @return array<string,mixed>|null
     */
    public function normalizeAtom(array $atom): ?array
    {
        $type = (string) ($atom['type'] ?? 'method_return');
        $handlers = $this->atomHandlers();
        $handler = $handlers[$type] ?? $handlers['method_return'];

        return $handler($atom);
    }

    /**
     * Type-discriminator dispatch table for normalizeAtom(). One closure per atom type
     * plus a default `method_return` fallback; the table itself is branch-free and is
     * consulted by lookup only — collapsing the previous if/elseif chain into a single
     * dispatch preserves the exact prior branch inventory in the per-type helpers.
     *
     * @return array<string, callable(array<string,mixed>): ?array>
     */
    private function atomHandlers(): array
    {
        return [
            'command_output' => fn (array $atom): ?array => $this->normalizeCommandOutputAtom($atom),
            'http_response' => fn (array $atom): ?array => $this->normalizeHttpResponseAtom($atom),
            'event_dispatched' => fn (array $atom): ?array => $this->normalizeEventDispatchedAtom($atom),
            'job_dispatched' => fn (array $atom): ?array => $this->normalizeJobDispatchedAtom($atom),
            'db_state' => fn (array $atom): ?array => $this->normalizeDbStateAtom($atom),
            'method_return' => fn (array $atom): ?array => $this->normalizeMethodReturnAtom($atom),
        ];
    }

    /**
     * @param  array<string,mixed>  $atom
     * @return array<string,mixed>|null
     */
    private function normalizeCommandOutputAtom(array $atom): ?array
    {
        $command = trim((string) ($atom['command'] ?? ''));
        if ($command === '') {
            return null;
        }
        $contains = array_key_exists('output_contains', $atom) ? ($this->literal)($atom['output_contains']) : null;
        $exact = array_key_exists('output_exact', $atom) ? ($this->literal)($atom['output_exact']) : null;
        if (! is_string($contains) && ! is_string($exact)) {
            return null;
        }

        return [
            'type' => 'command_output',
            'command' => $command,
            'output_contains' => is_string($contains) ? $contains : null,
            'output_exact' => is_string($exact) ? $exact : null,
            'exit_code' => is_numeric($atom['exit_code'] ?? null) ? (int) $atom['exit_code'] : 0,
        ];
    }

    /**
     * @param  array<string,mixed>  $atom
     * @return array<string,mixed>|null
     */
    private function normalizeHttpResponseAtom(array $atom): ?array
    {
        $path = trim((string) ($atom['path'] ?? ''));
        if ($path === '' || ! str_starts_with($path, '/')) {
            return null;
        }
        $contains = array_key_exists('body_contains', $atom) ? ($this->literal)($atom['body_contains']) : null;
        $exact = array_key_exists('body_exact', $atom) ? ($this->literal)($atom['body_exact']) : null;
        if (! is_string($contains) && ! is_string($exact)) {
            return null;
        }

        return [
            'type' => 'http_response',
            'method' => strtoupper(trim((string) ($atom['method'] ?? 'GET'))) ?: 'GET',
            'path' => $path,
            'status' => is_numeric($atom['status'] ?? null) ? (int) $atom['status'] : 200,
            'body_contains' => is_string($contains) ? $contains : null,
            'body_exact' => is_string($exact) ? $exact : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $atom
     * @return array<string,mixed>|null
     */
    private function normalizeEventDispatchedAtom(array $atom): ?array
    {
        $event = trim((string) ($atom['event_class'] ?? $atom['event'] ?? ''));
        $trigger = is_array($atom['trigger'] ?? null) ? $this->normalizeEventTrigger($atom['trigger']) : null;
        if ($event === '' || $trigger === null) {
            return null;
        }

        return [
            'type' => 'event_dispatched',
            'event_class' => $event,
            'trigger' => $trigger,
        ];
    }

    /**
     * @param  array<string,mixed>  $atom
     * @return array<string,mixed>|null
     */
    private function normalizeJobDispatchedAtom(array $atom): ?array
    {
        $job = trim((string) ($atom['job_class'] ?? $atom['job'] ?? ''));
        $trigger = is_array($atom['trigger'] ?? null) ? $this->normalizeEventTrigger($atom['trigger']) : null;
        if ($job === '' || $trigger === null) {
            return null;
        }

        return [
            'type' => 'job_dispatched',
            'job_class' => ltrim($job, '\\'),
            'trigger' => $trigger,
        ];
    }

    /**
     * @param  array<string,mixed>  $atom
     * @return array<string,mixed>|null
     */
    private function normalizeDbStateAtom(array $atom): ?array
    {
        $table = trim((string) ($atom['table'] ?? ''));
        $trigger = is_array($atom['trigger'] ?? null) ? $this->normalizeEventTrigger($atom['trigger']) : null;
        $setupSql = AiStringListNormalizer::trimmedStrings($atom['setup_sql'] ?? []);
        $where = $this->normalizeWhere($atom['where'] ?? []);
        $operator = $this->normalizeCountOperator((string) ($atom['count_operator'] ?? '>='));
        if (! $this->isSafeSqlIdentifier($table) || $trigger === null || $setupSql === []) {
            return null;
        }

        return [
            'type' => 'db_state',
            'table' => $table,
            'where' => $where,
            'expected_count' => is_numeric($atom['expected_count'] ?? null) ? max(0, (int) $atom['expected_count']) : 1,
            'count_operator' => $operator,
            'setup_sql' => $setupSql,
            'trigger' => $trigger,
        ];
    }

    /**
     * @param  array<string,mixed>  $atom
     * @return array<string,mixed>|null
     */
    private function normalizeMethodReturnAtom(array $atom): ?array
    {
        $method = trim((string) ($atom['method'] ?? ''));
        if ($method === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method)) {
            return null;
        }

        return [
            'type' => 'method_return',
            'method' => $method,
            'expected' => ($this->literal)($atom['expected'] ?? null),
            'constructor_args' => ($this->literalList)($atom['constructor_args'] ?? []),
            'method_args' => ($this->literalList)($atom['method_args'] ?? []),
            'static' => (bool) ($atom['static'] ?? false),
        ];
    }

    /**
     * @param  array<string,mixed>  $trigger
     * @return array<string,mixed>|null
     */
    private function normalizeEventTrigger(array $trigger): ?array
    {
        $type = (string) ($trigger['type'] ?? 'method_call');
        if ($type === 'method_call') {
            $method = trim((string) ($trigger['method'] ?? ''));
            if ($method === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method)) {
                return null;
            }

            return [
                'type' => 'method_call',
                'method' => $method,
                'constructor_args' => ($this->literalList)($trigger['constructor_args'] ?? []),
                'method_args' => ($this->literalList)($trigger['method_args'] ?? []),
                'static' => (bool) ($trigger['static'] ?? false),
            ];
        }
        if ($type === 'http_request') {
            $path = trim((string) ($trigger['path'] ?? ''));
            if ($path === '' || ! str_starts_with($path, '/')) {
                return null;
            }

            return [
                'type' => 'http_request',
                'method' => strtoupper(trim((string) ($trigger['method'] ?? 'GET'))) ?: 'GET',
                'path' => $path,
                'status' => is_numeric($trigger['status'] ?? null) ? (int) $trigger['status'] : 200,
            ];
        }
        if ($type === 'artisan_call') {
            $command = trim((string) ($trigger['command'] ?? ''));
            if ($command === '') {
                return null;
            }

            return [
                'type' => 'artisan_call',
                'command' => $command,
                'parameters' => is_array($trigger['parameters'] ?? null) ? $trigger['parameters'] : [],
                'exit_code' => is_numeric($trigger['exit_code'] ?? null) ? (int) $trigger['exit_code'] : 0,
            ];
        }

        return null;
    }

    /**
     * @param  mixed  $where
     * @return array<string,mixed>
     */
    private function normalizeWhere(mixed $where): array
    {
        if (! is_array($where)) {
            return [];
        }
        $normalized = [];
        foreach ($where as $column => $value) {
            if (! is_string($column) || ! $this->isSafeSqlIdentifier($column)) {
                continue;
            }
            $normalized[$column] = ($this->literal)($value);
        }

        return $normalized;
    }

    private function normalizeCountOperator(string $operator): string
    {
        return in_array($operator, ['=', '>=', '<=', '>', '<'], true) ? $operator : '>=';
    }

    private function isSafeSqlIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) === 1;
    }
}
