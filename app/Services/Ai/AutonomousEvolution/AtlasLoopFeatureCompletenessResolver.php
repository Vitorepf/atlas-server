<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * ACDE lever F2 — FEATURE COMPLETENESS CHECKLIST resolver.
 *
 * A compiled intent verifier ({@see AtlasLoopIntentVerifierFactory}) carries N executable verification atoms,
 * but the frozen test runs them as ONE pass/fail monolith — so a delivery dossier can only say "the verifier
 * is green", never WHICH falsifiable criteria it satisfies. This resolver turns each atom into ONE explicit,
 * human-falsifiable criterion (deterministically derived from the atom's discriminating fields), so the
 * dossier reports per-criterion completeness instead of a single opaque bit.
 *
 * PURE + deterministic: atoms in → criteria out. No DB, no config, no provider, no self-grading — every
 * criterion is a restatement of the machine-checkable atom the verifier already enforces, never an opinion.
 */
final class AtlasLoopFeatureCompletenessResolver
{
    /**
     * One falsifiable criterion per verification atom, in atom order.
     *
     * @param  list<array<string,mixed>>  $atoms  the packet's `verification_atoms`
     * @return list<array{index:int, type:string, criterion:string}>
     */
    public function resolve(array $atoms): array
    {
        $checklist = [];
        $index = 0;
        foreach ($atoms as $atom) {
            if (! is_array($atom)) {
                continue;
            }
            $type = (string) ($atom['type'] ?? '');
            $criterion = $this->criterionFor($type, $atom);
            if ($criterion === '') {
                continue;
            }
            $checklist[] = ['index' => $index, 'type' => $type, 'criterion' => $criterion];
            $index++;
        }

        return $checklist;
    }

    /**
     * @param  array<string,mixed>  $atom
     */
    private function criterionFor(string $type, array $atom): string
    {
        return match ($type) {
            'method_return' => $this->methodReturnCriterion($atom),
            'command_output' => $this->commandOutputCriterion($atom),
            'http_response' => $this->httpResponseCriterion($atom),
            'event_dispatched' => $this->dispatchCriterion('Event', (string) ($atom['event_class'] ?? ''), $atom),
            'job_dispatched' => $this->dispatchCriterion('Job', (string) ($atom['job_class'] ?? ''), $atom),
            'db_state' => $this->dbStateCriterion($atom),
            default => '',
        };
    }

    /**
     * @param  array<string,mixed>  $atom
     */
    private function methodReturnCriterion(array $atom): string
    {
        $method = trim((string) ($atom['method'] ?? ''));
        if ($method === '') {
            return '';
        }
        $kind = ((bool) ($atom['static'] ?? false)) ? 'static method' : 'method';

        return "Public {$kind} `{$method}()` returns ".$this->valueText($atom['expected'] ?? null).'.';
    }

    /**
     * @param  array<string,mixed>  $atom
     */
    private function commandOutputCriterion(array $atom): string
    {
        $command = trim((string) ($atom['command'] ?? ''));
        if ($command === '') {
            return '';
        }
        $exit = (int) ($atom['exit_code'] ?? 0);
        $out = is_string($atom['output_exact'] ?? null)
            ? 'output equals '.$this->valueText($atom['output_exact'])
            : (is_string($atom['output_contains'] ?? null) ? 'output contains '.$this->valueText($atom['output_contains']) : 'output is unconstrained');

        return "Command `{$command}` exits {$exit} and {$out}.";
    }

    /**
     * @param  array<string,mixed>  $atom
     */
    private function httpResponseCriterion(array $atom): string
    {
        $path = trim((string) ($atom['path'] ?? ''));
        if ($path === '') {
            return '';
        }
        $method = strtoupper(trim((string) ($atom['method'] ?? 'GET'))) ?: 'GET';
        $status = (int) ($atom['status'] ?? 200);
        $body = is_string($atom['body_exact'] ?? null)
            ? ' and body equals '.$this->valueText($atom['body_exact'])
            : (is_string($atom['body_contains'] ?? null) ? ' and body contains '.$this->valueText($atom['body_contains']) : '');

        return "{$method} {$path} responds {$status}{$body}.";
    }

    /**
     * @param  array<string,mixed>  $atom
     */
    private function dispatchCriterion(string $label, string $class, array $atom): string
    {
        $class = trim($class);
        if ($class === '') {
            return '';
        }
        $trigger = is_array($atom['trigger'] ?? null) ? $this->triggerText($atom['trigger']) : '';
        $when = $trigger === '' ? '' : " when {$trigger}";

        return "{$label} `{$class}` is dispatched{$when}.";
    }

    /**
     * @param  array<string,mixed>  $atom
     */
    private function dbStateCriterion(array $atom): string
    {
        $table = trim((string) ($atom['table'] ?? ''));
        if ($table === '') {
            return '';
        }
        $operator = (string) ($atom['count_operator'] ?? '>=');
        $count = (int) ($atom['expected_count'] ?? 1);
        $trigger = is_array($atom['trigger'] ?? null) ? $this->triggerText($atom['trigger']) : '';
        $when = $trigger === '' ? '' : " after {$trigger}";
        $where = is_array($atom['where'] ?? null) && $atom['where'] !== [] ? ' (matching '.$this->whereText($atom['where']).')' : '';

        return "Table `{$table}`{$where} has row count {$operator} {$count}{$when}.";
    }

    /**
     * @param  array<string,mixed>  $trigger
     */
    private function triggerText(array $trigger): string
    {
        $type = (string) ($trigger['type'] ?? 'method_call');

        return match ($type) {
            'http_request' => (strtoupper(trim((string) ($trigger['method'] ?? 'GET'))) ?: 'GET').' '.trim((string) ($trigger['path'] ?? '/')),
            'artisan_call' => 'artisan `'.trim((string) ($trigger['command'] ?? '')).'` runs',
            default => 'calling `'.trim((string) ($trigger['method'] ?? '')).'()`',
        };
    }

    /**
     * @param  array<string,mixed>  $where
     */
    private function whereText(array $where): string
    {
        $parts = [];
        foreach ($where as $column => $value) {
            $parts[] = (string) $column.'='.$this->valueText($value);
        }

        return implode(', ', $parts);
    }

    private function valueText(mixed $value): string
    {
        return match (true) {
            $value === true => 'true',
            $value === false => 'false',
            $value === null => 'null',
            is_int($value) || is_float($value) => (string) $value,
            is_string($value) => "'".$value."'",
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => '<value>',
        };
    }
}
