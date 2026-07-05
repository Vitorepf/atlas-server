<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Repair;

/**
 * Engineering Kernel mechanism (OBRA #4 S3): o corpus do failure-brain — cada diagnóstico + a
 * estratégia escolhida + o outcome real persistem em JSONL append-only. É a matéria-prima das
 * priors do RepairBrain (S4 consome: classe X + estratégia Y funcionou => Y sobe na ordem).
 * O corpus que estava VAZIO desde o AP-819 passa a crescer a cada repair.
 */
final class FailureBrainCorpus
{
    public const SCHEMA = 'atlas.engineering_kernel.failure_brain.v1';

    public function __construct(private readonly ?string $path = null) {}

    /**
     * @param  array{failure_signature?:string, origin?:string, class:string, strategy:string,
     *                decided_by?:string, outcome?:string}  $entry
     * @return array<string,mixed>
     */
    public function record(array $entry): array
    {
        $body = [
            'schema_version' => self::SCHEMA,
            'failure_signature' => (string) ($entry['failure_signature'] ?? ''),
            'origin' => (string) ($entry['origin'] ?? 'unknown'),
            'class' => (string) ($entry['class'] ?? FailureTaxonomy::UNKNOWN),
            'strategy' => (string) ($entry['strategy'] ?? ''),
            'decided_by' => (string) ($entry['decided_by'] ?? ''),
            'outcome' => (string) ($entry['outcome'] ?? 'pending'),
            'recorded_at' => now()->toIso8601String(),
        ];

        $file = $this->file();
        $dir = dirname($file);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($file, json_encode($body, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);

        return $body;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $file = $this->file();
        if (! is_file($file)) {
            return [];
        }
        $entries = [];
        foreach (explode("\n", trim((string) file_get_contents($file))) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    private function file(): string
    {
        if ($this->path !== null && $this->path !== '') {
            return $this->path;
        }
        try {
            return storage_path('atlas/engineering_kernel/failure_brain.jsonl');
        } catch (\Throwable) {
            return sys_get_temp_dir().'/atlas-failure-brain.jsonl';
        }
    }
}
