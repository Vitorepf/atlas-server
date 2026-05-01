<?php

namespace App\Services\Ai\Cli;

class AtlasReplHistory
{
    private const MAX_ENTRIES = 1000;

    private ?string $activePath = null;

    public function load(string $workspace): bool
    {
        if (! function_exists('readline_read_history')) {
            return false;
        }

        $path = $this->pathFor($workspace);
        $this->ensureDirectory(dirname($path));
        $this->activePath = $path;

        if (! is_file($path)) {
            return true;
        }

        return @readline_read_history($path);
    }

    public function save(): bool
    {
        if ($this->activePath === null) {
            return false;
        }
        if (! function_exists('readline_write_history')) {
            return false;
        }

        $this->trim($this->activePath);

        return @readline_write_history($this->activePath);
    }

    public function pathFor(string $workspace): string
    {
        $home = $this->home();
        $hash = substr(sha1($workspace), 0, 16);

        return $home.'/.atlas/history/'.$hash.'.history';
    }

    private function home(): string
    {
        return (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir());
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            @mkdir($path, 0700, true);
        }
    }

    private function trim(string $path): void
    {
        if (! is_file($path)) {
            return;
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return;
        }
        $lines = preg_split('/\R/', rtrim($contents, "\r\n")) ?: [];
        if (count($lines) <= self::MAX_ENTRIES) {
            return;
        }
        $kept = array_slice($lines, -self::MAX_ENTRIES);
        @file_put_contents($path, implode("\n", $kept)."\n");
    }
}
