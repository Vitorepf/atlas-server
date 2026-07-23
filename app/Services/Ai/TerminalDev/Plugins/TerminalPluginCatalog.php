<?php

namespace App\Services\Ai\TerminalDev\Plugins;

use Illuminate\Support\Facades\File;

final class TerminalPluginCatalog
{
    /**
     * @return list<array{name:string,path:string,source:string,has_skills:bool,has_hooks:bool,has_mcp:bool}>
     */
    public function list(string $workspace): array
    {
        $out = [];
        foreach ($this->roots($workspace) as $root => $source) {
            if (! File::isDirectory($root)) {
                continue;
            }
            foreach (File::directories($root) as $dir) {
                $name = basename($dir);
                $out[] = [
                    'name' => $name,
                    'path' => $dir,
                    'source' => $source,
                    'has_skills' => File::isDirectory($dir.'/skills'),
                    'has_hooks' => File::isFile($dir.'/hooks/hooks.json') || File::isDirectory($dir.'/hooks'),
                    'has_mcp' => File::isFile($dir.'/.mcp.json') || File::isFile($dir.'/mcp.json'),
                ];
            }
        }

        return $out;
    }

    /**
     * @return array<string,string>
     */
    private function roots(string $workspace): array
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '';
        $roots = [];
        if ($home !== '') {
            $roots[rtrim((string) $home, '/').'/.atlas/plugins'] = 'user';
        }
        $roots[rtrim($workspace, '/').'/.atlas/plugins'] = 'project';
        foreach ((array) config('atlas_terminal.plugins_paths', []) as $p) {
            if (is_string($p) && $p !== '') {
                $roots[str_replace('~', (string) $home, $p)] = 'config';
            }
        }

        return $roots;
    }
}
