<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use RuntimeException;
use SplFileInfo;

final class AtlasFrontendDesignSystemInventoryService
{
    public const SCHEMA_VERSION = 'atlas.frontend.design_system_inventory.v1';

    private const MAX_FILES = 220;

    private const MAX_TOKENS = 120;

    /**
     * @return array<string,mixed>
     */
    public function inspect(string $workspace): array
    {
        $workspace = $this->workspace($workspace);
        $package = $this->package($workspace);
        $files = $this->frontendFiles($workspace);
        $tokens = $this->tokenInventory($files);
        $components = $this->componentInventory($workspace, $files);
        $designSystemSignals = $this->designSystemSignals($workspace, $package, $files);
        $status = $components !== [] || $tokens['css_variables'] !== [] || $designSystemSignals['libraries'] !== []
            ? 'ready'
            : 'partial';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'workspace_hash' => hash('sha256', $workspace),
            'source' => self::class,
            'scanned' => [
                'file_count' => count($files),
                'max_files' => self::MAX_FILES,
                'truncated' => count($files) >= self::MAX_FILES,
                'raw_source_returned' => false,
                'absolute_paths_returned' => false,
            ],
            'design_system_signals' => $designSystemSignals,
            'tokens' => $tokens,
            'components' => [
                'count' => count($components),
                'candidates' => $components,
            ],
            'recommended_next_gates' => [
                'company_design_profile',
                'design_system_drift_gate',
                'visual_quality_gate',
                'anti_ai_slop_detector',
            ],
            'warnings' => $status === 'partial' ? [
                ['id' => 'design_system_inventory_sparse', 'reason' => 'Atlas found too little local design-system evidence; require company profile or design refs before broad visual work.'],
            ] : [],
        ];
        $payload['inventory_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    private function workspace(string $workspace): string
    {
        $workspace = trim($workspace) !== '' ? $workspace : base_path();
        $real = realpath($workspace);
        if ($real === false || ! File::isDirectory($real)) {
            throw new RuntimeException('workspace_not_found');
        }

        return $real;
    }

    /**
     * @return array<string,mixed>
     */
    private function package(string $workspace): array
    {
        $path = $workspace.'/package.json';
        if (! File::isFile($path)) {
            return [];
        }

        $decoded = json_decode(File::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<int,SplFileInfo>
     */
    private function frontendFiles(string $workspace): array
    {
        $allowed = ['css', 'scss', 'sass', 'less', 'tsx', 'jsx', 'ts', 'js', 'vue', 'svelte', 'astro', 'html'];
        $skipDirectories = ['node_modules', 'vendor', 'storage', 'cache', '.git', 'dist', 'build', 'coverage'];
        $files = [];
        $directories = [$workspace];

        while ($directories !== [] && count($files) < self::MAX_FILES) {
            $directory = array_shift($directories);
            if (! is_string($directory) || ! is_dir($directory)) {
                continue;
            }
            $entries = @scandir($directory);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $directory.'/'.$entry;
                if (is_dir($path)) {
                    if (! in_array($entry, $skipDirectories, true)) {
                        $directories[] = $path;
                    }

                    continue;
                }
                $file = new SplFileInfo($path);
                if (! in_array(strtolower($file->getExtension()), $allowed, true)) {
                    continue;
                }
                $files[] = $file;
                if (count($files) >= self::MAX_FILES) {
                    break;
                }
            }
        }

        return $files;
    }

    /**
     * @param  array<int,SplFileInfo>  $files
     * @return array<string,mixed>
     */
    private function tokenInventory(array $files): array
    {
        $cssVariables = [];
        $utilityClasses = [];
        $semanticNames = [];

        foreach ($files as $file) {
            $source = File::get($file->getPathname());
            preg_match_all('/--[a-zA-Z0-9_-]+/', $source, $matches);
            foreach ($matches[0] ?? [] as $token) {
                $cssVariables[] = $token;
            }
            preg_match_all('/(?:class|className)=["\']([^"\']+)["\']/', $source, $classMatches);
            foreach ($classMatches[1] ?? [] as $classList) {
                foreach (preg_split('/\s+/', trim($classList)) ?: [] as $className) {
                    if ($className !== '' && preg_match('/\A(?:bg|text|border|p|m|gap|grid|flex|rounded|shadow|font|leading|tracking|w|h|min|max)-/', $className) === 1) {
                        $utilityClasses[] = $className;
                    }
                }
            }
            preg_match_all('/\b(?:primary|secondary|accent|muted|surface|background|foreground|danger|success|warning|brand)[A-Za-z0-9_-]*\b/', $source, $semanticMatches);
            foreach ($semanticMatches[0] ?? [] as $name) {
                $semanticNames[] = $name;
            }
        }

        return [
            'css_variables' => $this->tokenRefs($cssVariables),
            'utility_classes' => $this->tokenRefs($utilityClasses),
            'semantic_names' => $this->tokenRefs($semanticNames),
        ];
    }

    /**
     * @param  array<int,string>  $values
     * @return array<int,array<string,mixed>>
     */
    private function tokenRefs(array $values): array
    {
        $counts = array_count_values(array_values(array_filter($values, fn (string $value): bool => trim($value) !== '')));
        arsort($counts);

        return collect($counts)
            ->take(self::MAX_TOKENS)
            ->map(fn (int $count, string $name): array => [
                'name_hash' => hash('sha256', $name),
                'sample' => $name,
                'count' => $count,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int,SplFileInfo>  $files
     * @return array<int,array<string,mixed>>
     */
    private function componentInventory(string $workspace, array $files): array
    {
        $components = [];
        foreach ($files as $file) {
            $relative = $this->relativePath($workspace, $file->getPathname());
            if (preg_match('/(^|\/)(components|ui|views|screens|pages|app)\//', $relative) !== 1) {
                continue;
            }
            if (! in_array(strtolower($file->getExtension()), ['tsx', 'jsx', 'vue', 'svelte', 'astro'], true)) {
                continue;
            }
            $components[] = [
                'path' => $relative,
                'path_hash' => hash('sha256', $relative),
                'content_hash' => hash_file('sha256', $file->getPathname()),
                'kind' => $this->componentKind($relative),
            ];
        }

        return array_slice($components, 0, 80);
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<int,SplFileInfo>  $files
     * @return array<string,mixed>
     */
    private function designSystemSignals(string $workspace, array $package, array $files): array
    {
        $dependencies = array_merge(
            (array) ($package['dependencies'] ?? []),
            (array) ($package['devDependencies'] ?? []),
        );
        $libraries = [];
        foreach ([
            'tailwind' => 'tailwindcss',
            'shadcn' => 'class-variance-authority',
            'radix' => '@radix-ui/react-slot',
            'chakra' => '@chakra-ui/react',
            'mui' => '@mui/material',
            'ant_design' => 'antd',
            'lucide' => 'lucide-react',
            'storybook' => 'storybook',
        ] as $library => $dependency) {
            if (array_key_exists($dependency, $dependencies)) {
                $libraries[] = $library;
            }
        }

        $configFiles = [];
        foreach (['tailwind.config.js', 'tailwind.config.ts', 'components.json', '.storybook/main.ts', '.storybook/main.js'] as $candidate) {
            if (File::isFile($workspace.'/'.$candidate)) {
                $configFiles[] = [
                    'path' => $candidate,
                    'content_hash' => hash_file('sha256', $workspace.'/'.$candidate),
                ];
            }
        }

        return [
            'libraries' => array_values(array_unique($libraries)),
            'config_files' => $configFiles,
            'style_file_count' => collect($files)->filter(fn (SplFileInfo $file): bool => in_array(strtolower($file->getExtension()), ['css', 'scss', 'sass', 'less'], true))->count(),
            'component_file_count' => collect($files)->filter(fn (SplFileInfo $file): bool => in_array(strtolower($file->getExtension()), ['tsx', 'jsx', 'vue', 'svelte', 'astro'], true))->count(),
        ];
    }

    private function componentKind(string $relative): string
    {
        return match (true) {
            str_contains($relative, '/screens/'), str_contains($relative, '/pages/'), str_starts_with($relative, 'app/') => 'screen',
            str_contains($relative, '/ui/') => 'primitive',
            default => 'component',
        };
    }

    private function relativePath(string $workspace, string $path): string
    {
        return ltrim(str_replace('\\', '/', substr($path, strlen($workspace))), '/');
    }
}
