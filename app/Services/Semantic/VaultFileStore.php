<?php

namespace App\Services\Semantic;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use RuntimeException;

class VaultFileStore
{
    /**
     * @return Collection<int, string>
     */
    public function ensureVaultStructure(): Collection
    {
        $created = collect();

        foreach ($this->directories() as $directory) {
            $path = $this->absolutePath($directory);
            if (! File::isDirectory($path)) {
                File::makeDirectory($path, 0755, true);
                $created->push($directory);
            }
        }

        foreach ($this->templates() as $path => $content) {
            $absolute = $this->absolutePath($path);
            if (! File::exists($absolute)) {
                File::ensureDirectoryExists(dirname($absolute));
                File::put($absolute, $content);
                $created->push($path);
            }
        }

        $manifest = $this->absolutePath('.atlas-vault.json');
        if (! File::exists($manifest)) {
            File::put($manifest, json_encode([
                'name' => 'AtlasVault',
                'created_by' => 'atlas-server',
                'schema' => 'semantic-memory-v1',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
            $created->push('.atlas-vault.json');
        }

        return $created;
    }

    /**
     * @return Collection<int, string>
     */
    public function listMarkdownFiles(): Collection
    {
        $root = $this->rootPath();
        if (! File::isDirectory($root)) {
            return collect();
        }

        return collect(File::allFiles($root))
            ->filter(fn ($file): bool => strtolower($file->getExtension()) === 'md')
            ->map(fn ($file): string => str_replace('\\', '/', $file->getRelativePathname()))
            ->reject(fn (string $path): bool => str_starts_with($path, '.obsidian/'))
            ->reject(fn (string $path): bool => str_starts_with($path, '_templates/'))
            ->values();
    }

    public function read(string $path): string
    {
        $absolute = $this->absolutePath($path);
        if (! File::exists($absolute)) {
            throw new RuntimeException("Vault file not found: {$path}");
        }

        return File::get($absolute);
    }

    public function writeDraft(string $path, string $content): string
    {
        $absolute = $this->absolutePath($path);
        File::ensureDirectoryExists(dirname($absolute));

        if (File::exists($absolute)) {
            $path = $this->uniquePath($path);
            $absolute = $this->absolutePath($path);
            File::ensureDirectoryExists(dirname($absolute));
        }

        $tmp = $absolute.'.tmp.'.bin2hex(random_bytes(4));
        File::put($tmp, $content);
        File::move($tmp, $absolute);

        return $path;
    }

    public function absolutePath(string $relativePath = ''): string
    {
        $root = $this->rootPath();
        $relativePath = $this->normalizeRelativePath($relativePath);
        $path = $relativePath === '' ? $root : $root.DIRECTORY_SEPARATOR.$relativePath;
        $directory = File::isDirectory($path) ? $path : dirname($path);

        $realRoot = realpath($root) ?: $root;
        $realDirectory = realpath($directory) ?: $directory;

        if (! str_starts_with($realDirectory, $realRoot)) {
            throw new RuntimeException('Unsafe vault path.');
        }

        return $path;
    }

    public function rootPath(): string
    {
        $path = (string) config('atlas.semantic_memory.vault_path');
        if ($path === '') {
            throw new RuntimeException('ATLAS_VAULT_PATH is not configured.');
        }

        File::ensureDirectoryExists($path);

        return rtrim($path, DIRECTORY_SEPARATOR);
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?? '';
        $path = ltrim($path, '/');

        if ($path === '' || $path === '.') {
            return '';
        }

        if (str_contains($path, '..')) {
            throw new RuntimeException('Vault path cannot contain parent traversal.');
        }

        return $path;
    }

    private function uniquePath(string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $base = $extension ? substr($path, 0, -strlen($extension) - 1) : $path;
        $extension = $extension ? '.'.$extension : '';

        for ($i = 2; $i < 1000; $i++) {
            $candidate = "{$base}-{$i}{$extension}";
            if (! File::exists($this->absolutePath($candidate))) {
                return $candidate;
            }
        }

        return $base.'-'.bin2hex(random_bytes(4)).$extension;
    }

    /**
     * @return array<int, string>
     */
    private function directories(): array
    {
        return [
            '00-constituicao',
            '01-acervo/livros',
            '01-acervo/filosofia',
            '01-acervo/blackink',
            '01-acervo/saude',
            '01-acervo/comunicacao',
            '01-acervo/investimento',
            '01-acervo/vida',
            '02-modelos-mentais',
            '03-principios',
            '04-hipoteses',
            '05-praticas',
            '06-jogos-cognitivos',
            '07-decisoes-e-identidade',
            '08-sinteses',
            '_inbox',
            '_laboratorio',
            '_arquivo',
            '_templates',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function templates(): array
    {
        return [
            '_templates/modelo-mental.md' => <<<'MD'
---
id:
type: mental_model
title:
status: draft
confidence: low
maturity: draft
domains: []
summary:
when_to_use: []
trigger_signals: []
do_not_use_when: []
practice_prompt:
created_at:
updated_at:
---

## Ideia central

## Por que importa

## Quando usar

## Exemplo real

## Erros comuns

## Como Atlas deve me lembrar

## Links explicados
MD,
            '_templates/hipotese.md' => <<<'MD'
---
id:
type: hypothesis
title:
status: testing
confidence: low
maturity: seed
domains: []
summary:
when_to_use: []
trigger_signals: []
do_not_use_when: []
practice_prompt:
created_at:
updated_at:
---

## Hipotese

## Por que acredito nisso

## O que poderia provar que esta errado

## Como testar

## Dados necessarios

## Resultado

## Decisao
MD,
        ];
    }
}
