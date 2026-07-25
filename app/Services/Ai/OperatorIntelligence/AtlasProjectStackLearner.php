<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\Support\ProjectStackLearnSupport;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Fast, ANTI-HALLUCINATION project learning. Atlas learns a project's tech stack, what it
 * is, and how to work in it — but ONLY from real files it can cite. Every fact carries the
 * source file it was read from; nothing is inferred or invented. The stack comes from
 * manifests (composer.json / package.json — deterministic), the purpose + working rules
 * from the project's own docs (README / CLAUDE.md / AGENTS.md — verbatim excerpts).
 *
 * This is the project counterpart to the operator-profile extractor: same discipline
 * (cite-or-drop), different subject (the project, not the operator). It produces a
 * project-scoped knowledge record, never a global one.
 */
final class AtlasProjectStackLearner
{
    public const SCHEMA = 'atlas.project_stack_learning.v1';

    private const DOC_CANDIDATES = ['CLAUDE.md', 'AGENTS.md', 'README.md', 'readme.md', 'docs/README.md'];

    /** Manifest package → human label for framework/runtime detection. */
    private const STACK_SIGNATURES = [
        'composer.json' => [
            'laravel/framework' => 'Laravel',
            'php' => 'PHP',
            'livewire/livewire' => 'Livewire',
            'filament/filament' => 'Filament',
            'symfony/symfony' => 'Symfony',
            'pestphp/pest' => 'Pest',
            'phpunit/phpunit' => 'PHPUnit',
        ],
        'package.json' => [
            'react' => 'React',
            'next' => 'Next.js',
            'vue' => 'Vue',
            'typescript' => 'TypeScript',
            'tailwindcss' => 'Tailwind CSS',
            'vite' => 'Vite',
            '@inertiajs/react' => 'Inertia (React)',
        ],
    ];

    /**
     * @return array<string,mixed>
     */
    public function learn(string $projectPath): array
    {
        $root = rtrim($projectPath, '/');
        $facts = [];
        $stack = [];

        foreach (self::STACK_SIGNATURES as $manifest => $signatures) {
            $deps = $this->manifestDependencies($root.'/'.$manifest);
            [$matchedStack, $matchedFacts] = ProjectStackLearnSupport::matchStack($deps, $signatures, $manifest);
            $stack = array_merge($stack, $matchedStack);
            $facts = array_merge($facts, $matchedFacts);
        }

        $doc = $this->projectDoc($root);
        $summary = $doc['excerpt'] ?? '';
        if ($summary !== '') {
            $facts[] = ['kind' => 'purpose', 'fact' => $summary, 'source' => $doc['source']];
        }

        $databases = $this->detectDatabases($root);
        foreach ($databases as $db => $src) {
            $facts[] = ['kind' => 'infrastructure', 'fact' => $db, 'source' => $src];
        }

        return [
            'schema_version' => self::SCHEMA,
            'project_path' => $root,
            'project_name' => basename($root),
            'stack' => array_values(array_unique($stack)),
            'summary' => $summary,
            'facts' => $facts,
            'fact_count' => count($facts),
            'grounded' => true, // every fact cites a real source file — none inferred
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function manifestDependencies(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }
        try {
            $data = json_decode((string) File::get($path), true);
            if (! is_array($data)) {
                return [];
            }

            return ProjectStackLearnSupport::dependenciesFromManifest($data);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{source:string,excerpt:string}
     */
    private function projectDoc(string $root): array
    {
        foreach (self::DOC_CANDIDATES as $candidate) {
            $path = $root.'/'.$candidate;
            if (! File::exists($path)) {
                continue;
            }
            try {
                $text = (string) File::get($path);
            } catch (Throwable) {
                continue;
            }
            // First substantive paragraph (skip headings/badges/frontmatter) — a verbatim
            // excerpt of what the project SAYS it is, never a paraphrase.
            $excerpt = ProjectStackLearnSupport::excerptFromDocText($text, $candidate);
            if ($excerpt['excerpt'] !== '') {
                return $excerpt;
            }
        }

        return ['source' => '', 'excerpt' => ''];
    }

    /**
     * @return array<string,string>  database → source file
     */
    private function detectDatabases(string $root): array
    {
        $env = $root.'/.env';
        if (! File::exists($env)) {
            return [];
        }
        try {
            return ProjectStackLearnSupport::databasesFromEnv((string) File::get($env));
        } catch (Throwable) {
            return [];
        }
    }
}
