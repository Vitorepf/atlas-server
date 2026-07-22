<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$aiRoot = $root.'/app/Services/Ai';

if (! is_dir($aiRoot)) {
    fwrite(STDERR, "GOD_DEBULK_AUDIT_FAIL missing_ai_root={$aiRoot}\n");
    exit(1);
}

$files = [];
$folders = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($aiRoot, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $contents = file_get_contents($path);
    if ($contents === false) {
        fwrite(STDERR, "GOD_DEBULK_AUDIT_FAIL unreadable={$path}\n");
        exit(1);
    }

    $lines = $contents === '' ? 0 : substr_count($contents, "\n") + (! str_ends_with($contents, "\n") ? 1 : 0);
    $relative = substr($path, strlen($root) + 1);
    $aiRelative = substr($path, strlen($aiRoot) + 1);
    $parts = explode('/', $aiRelative, 2);
    $folder = count($parts) === 1 ? '(root)' : $parts[0];

    $files[] = ['path' => $relative, 'lines' => $lines];
    $folders[$folder]['files'] = ($folders[$folder]['files'] ?? 0) + 1;
    $folders[$folder]['lines'] = ($folders[$folder]['lines'] ?? 0) + $lines;
}

usort($files, static fn (array $left, array $right): int => $right['lines'] <=> $left['lines'] ?: strcmp($left['path'], $right['path']));

$gt5k = array_values(array_filter($files, static fn (array $file): bool => $file['lines'] > 5000));
$gt2k = array_values(array_filter($files, static fn (array $file): bool => $file['lines'] > 2000));

uasort($folders, static fn (array $left, array $right): int => $right['lines'] <=> $left['lines'] ?: $right['files'] <=> $left['files']);
$folderRows = [];
foreach ($folders as $folder => $counts) {
    $folderRows[] = ['folder' => $folder, 'files' => $counts['files'], 'lines' => $counts['lines']];
}
usort($folderRows, static fn (array $left, array $right): int => $right['lines'] <=> $left['lines'] ?: strcmp($left['folder'], $right['folder']));

echo 'godfiles_gt_5k='.count($gt5k)."\n";
echo 'godfiles_gt_2k='.count($gt2k)."\n";

foreach ($gt5k as $file) {
    echo "GT5K lines={$file['lines']} path={$file['path']}\n";
}

foreach (array_slice($folderRows, 0, 10) as $folder) {
    echo "TOP_FIRST_LEVEL_AI folder={$folder['folder']} php_files={$folder['files']} lines={$folder['lines']}\n";
}

echo "GOD_DEBULK_AUDIT_OK\n";
