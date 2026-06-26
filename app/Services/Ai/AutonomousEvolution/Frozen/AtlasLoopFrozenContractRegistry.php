<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Frozen;


use App\Services\Ai\SelfConstruction\Support\RecursivelyCanonicalizesArrays;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class AtlasLoopFrozenContractRegistry
{
    use RecursivelyCanonicalizesArrays;

    /** @var array<string, array{assertions_sha256:string,registered_at:string,test_path:string}>|null */
    private ?array $contracts = null;

    public function __construct(
        private readonly ?string $manifestPath = null,
        private readonly ?string $autonomousEvolutionRoot = null,
    ) {}

    /**
     * @return array{test_path:string,assertions_sha256:string,registered_at:string}|null
     */
    public function get(string $fqcn): ?array
    {
        $contracts = $this->contracts();

        return $contracts[$fqcn] ?? null;
    }

    /**
     * @return list<string>
     */
    public function missing(): array
    {
        $contracts = $this->contracts();
        $registered = array_fill_keys(array_keys($contracts), true);
        $missing = [];

        foreach ($this->discoverLoopClasses() as $fqcn) {
            if (! isset($registered[$fqcn])) {
                $missing[] = $fqcn;
            }
        }

        sort($missing);

        return $missing;
    }

    public function toJson(): string
    {
        return $this->encode($this->contracts())."\n";
    }

    /**
     * @return array<string, array{assertions_sha256:string,registered_at:string,test_path:string}>
     */
    private function contracts(): array
    {
        if ($this->contracts !== null) {
            return $this->contracts;
        }

        $path = $this->manifestPath ?? __DIR__.'/contracts.manifest.json';
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Frozen contracts manifest must decode to an object map.');
        }

        $contracts = [];
        foreach ($decoded as $fqcn => $entry) {
            if (! is_string($fqcn) || ! is_array($entry)) {
                throw new RuntimeException('Frozen contracts manifest contains an invalid entry.');
            }

            $contracts[$fqcn] = $this->canonicalize([
                'assertions_sha256' => (string) ($entry['assertions_sha256'] ?? ''),
                'registered_at' => (string) ($entry['registered_at'] ?? ''),
                'test_path' => (string) ($entry['test_path'] ?? ''),
            ]);
        }

        ksort($contracts);
        $this->contracts = $contracts;

        return $this->contracts;
    }

    /**
     * @return list<string>
     */
    private function discoverLoopClasses(): array
    {
        $root = $this->autonomousEvolutionRoot ?? dirname(__DIR__);
        if (! is_dir($root)) {
            return [];
        }

        $classes = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = @file_get_contents($file->getPathname());
            if (! is_string($contents)) {
                continue;
            }

            if (preg_match('/namespace\s+([^;]+);/m', $contents, $namespace) !== 1) {
                continue;
            }
            if (preg_match('/final\s+class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $contents, $class) !== 1) {
                continue;
            }

            $classes[] = trim($namespace[1]).'\\'.$class[1];
        }

        $classes = array_values(array_unique($classes));
        sort($classes);

        return $classes;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        $payload = $this->canonicalize($payload);

        return (string) json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $value
     * @return array<string, mixed>|list<mixed>
     */
}
