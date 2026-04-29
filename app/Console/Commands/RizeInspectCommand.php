<?php

namespace App\Console\Commands;

use App\Services\Digital\RizeApiClient;
use Illuminate\Console\Command;

class RizeInspectCommand extends Command
{
    protected $signature = 'atlas:rize:inspect {--raw : Print the raw GraphQL introspection payload}';

    protected $description = 'Inspect the authenticated Rize GraphQL query fields.';

    public function handle(RizeApiClient $client): int
    {
        $fields = $client->inspectQueryFields();

        if ($this->option('raw')) {
            $this->line(json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['field', 'args', 'type'],
            collect($fields)
                ->map(fn (array $field): array => [
                    $field['name'] ?? '',
                    collect($field['args'] ?? [])
                        ->map(fn (array $arg): string => ($arg['name'] ?? '').': '.$this->typeName($arg['type'] ?? []))
                        ->join(', '),
                    $this->typeName($field['type'] ?? []),
                ])
                ->sortBy(fn (array $row): string => $row[0])
                ->values()
                ->all(),
        );

        return self::SUCCESS;
    }

    private function typeName(array $type): string
    {
        $kind = $type['kind'] ?? null;
        $name = $type['name'] ?? null;
        $ofType = $type['ofType'] ?? null;

        if ($name) {
            return (string) $name;
        }

        if ($kind === 'NON_NULL' && is_array($ofType)) {
            return $this->typeName($ofType).'!';
        }

        if ($kind === 'LIST' && is_array($ofType)) {
            return '['.$this->typeName($ofType).']';
        }

        return is_string($kind) ? $kind : 'unknown';
    }
}
