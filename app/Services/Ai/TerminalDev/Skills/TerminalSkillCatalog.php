<?php

namespace App\Services\Ai\TerminalDev\Skills;

use App\Services\Ai\Skills\SkillDiscoveryService;
use App\Services\Ai\Skills\SkillManifest;
use Illuminate\Support\Facades\File;

/**
 * Extends Atlas skill discovery with optional .claude / .grok skill dirs (compat).
 */
final class TerminalSkillCatalog
{
    public function __construct(
        private readonly SkillDiscoveryService $discovery,
    ) {}

    /**
     * @return list<array{name:string,description:string,path:string,source_tier:string,body:?string}>
     */
    public function list(string $workspace, bool $includeBody = false): array
    {
        $workspace = realpath($workspace) ?: $workspace;
        $out = [];

        try {
            foreach ($this->discovery->discoverAll($workspace, true) as $manifest) {
                $out[$manifest->name] = $this->row($manifest, $includeBody);
            }
        } catch (\Throwable) {
            // fail-open
        }

        if ((bool) config('atlas_terminal.skills.compat_claude', true)) {
            $this->scanMarkdownSkills($workspace.'/.claude/skills', 'claude_project', $out, $includeBody);
            $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '';
            if ($home !== '') {
                $this->scanMarkdownSkills(rtrim((string) $home, '/').'/.claude/skills', 'claude_user', $out, $includeBody);
            }
        }

        if ((bool) config('atlas_terminal.skills.compat_grok', true)) {
            $this->scanMarkdownSkills($workspace.'/.grok/skills', 'grok_project', $out, $includeBody);
            $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '';
            if ($home !== '') {
                $this->scanMarkdownSkills(rtrim((string) $home, '/').'/.grok/skills', 'grok_user', $out, $includeBody);
            }
        }

        ksort($out);

        return array_values($out);
    }

    /**
     * @return array{name:string,description:string,path:string,source_tier:string,body:?string}|null
     */
    public function find(string $workspace, string $name): ?array
    {
        $name = strtolower(trim($name));
        foreach ($this->list($workspace, true) as $row) {
            if (strtolower($row['name']) === $name) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  array<string,array{name:string,description:string,path:string,source_tier:string,body:?string}>  $out
     */
    private function scanMarkdownSkills(string $root, string $tier, array &$out, bool $includeBody): void
    {
        if (! File::isDirectory($root)) {
            return;
        }

        foreach (File::directories($root) as $dir) {
            $skillMd = $dir.'/SKILL.md';
            if (! File::isFile($skillMd)) {
                continue;
            }
            $name = basename($dir);
            if (isset($out[$name])) {
                continue; // higher priority already set (atlas discovery first)
            }
            $raw = File::get($skillMd);
            $description = $this->firstParagraph($raw);
            $out[$name] = [
                'name' => $name,
                'description' => $description,
                'path' => $skillMd,
                'source_tier' => $tier,
                'body' => $includeBody ? $raw : null,
            ];
        }
    }

    private function row(SkillManifest $manifest, bool $includeBody): array
    {
        return [
            'name' => $manifest->name,
            'description' => $manifest->description,
            'path' => $manifest->path,
            'source_tier' => $manifest->sourceTier,
            'body' => $includeBody ? $manifest->body : null,
        ];
    }

    private function firstParagraph(string $raw): string
    {
        $body = preg_replace('/^---.*?---\s*/s', '', $raw) ?? $raw;
        foreach (preg_split('/\n\s*\n/', trim($body)) as $para) {
            $line = trim(strip_tags($para));
            if ($line !== '' && ! str_starts_with($line, '#')) {
                return mb_substr($line, 0, 240);
            }
            if (str_starts_with($line, '# ')) {
                return mb_substr(ltrim($line, '# '), 0, 240);
            }
        }

        return 'skill';
    }
}
