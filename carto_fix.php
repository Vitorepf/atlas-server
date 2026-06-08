<?php
$BASE = __DIR__;
require $BASE.'/vendor/autoload.php';
$app = require $BASE.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$WRITE = in_array('--write', $argv, true);

$reader    = $app->make(App\Services\Vault\RepoVaultReader::class);
$assembler = $app->make(App\Services\Vault\GraphAssembler::class);
$graph = $assembler->assemble();
$fallbackIds = array_column($graph['audit']['essential_fields']['active_repo_fallback'], 'graph_id');
$index = $reader->index();

$ESSENTIAL = ['human_name','canonical_name','technical_name','cartography_type','canonical_source'];

/** strip trailing " (....)" or " — ...." parenthetical/dash qualifier from a title */
$cleanName = function (string $s): string {
    $s = preg_replace('/\s*\((?:Patamar|TEOS|Pilar|patamar)[^)]*\)\s*$/u', '', $s) ?? $s;
    $s = preg_replace('/\s+[—-]\s+.*$/u', '', $s) ?? $s; // drop em-dash subtitle
    return trim($s);
};

/** find a real code symbol (Service/Command/etc.) from path-bearing frontmatter fields */
$techFromPaths = function (array $fm): ?string {
    $buckets = [];
    foreach (['related_paths','repo_paths','evidence'] as $k) {
        foreach ((array)($fm[$k] ?? []) as $p) { if (is_string($p)) $buckets[] = $p; }
    }
    // evidence_refs stored as "symbol: X" strings by the minimal parser
    foreach ((array)($fm['evidence_refs'] ?? []) as $p) {
        if (is_string($p) && preg_match('/^symbol:\s*(.+)$/', trim($p), $m)) return trim($m[1]);
    }
    $php = array_values(array_filter($buckets, fn($p) => preg_match('#^app/.+\.php$#', $p)));
    $rank = function (string $p): int {
        $b = basename($p, '.php');
        if (str_ends_with($b,'Service')) return 3;
        if (str_ends_with($b,'Command')) return 2;
        return 1;
    };
    usort($php, fn($a,$b) => $rank($b) <=> $rank($a));
    return $php ? basename($php[0], '.php') : null;
};

$pascal = fn(string $gid): string => str_replace(' ', '', ucwords(str_replace('-', ' ', $gid)));

$needsQuote = fn(string $v): bool => (bool) preg_match('/[^\w\-.\/ ]/u', $v) || str_contains($v, ': ');
$emit = function (string $k, string $v) use ($needsQuote): string {
    if ($needsQuote($v)) { return $k.': "'.str_replace(['\\','"'], ['\\\\','\\"'], $v).'"'; }
    return $k.': '.$v;
};

$report = [];
foreach ($fallbackIds as $gid) {
    $entry = $index[$gid] ?? null;
    if (!$entry) { $report[] = "!! no index entry: $gid"; continue; }
    $fm = $entry['frontmatter'];
    $rel = $entry['relative_path'];

    $title = (string)($fm['title'] ?? '');
    $gtitle = (string)($fm['graph_title'] ?? '');
    $name = $gtitle !== '' ? $gtitle : $cleanName($title);
    $tech = $techFromPaths($fm) ?? ($name !== '' ? $name : $pascal($gid));
    $type = (string)($fm['graph_kind'] ?? '');
    if ($type === '') {
        $type = match (true) {
            str_contains($gid,'surface') => 'surface',
            str_contains($gid,'fase')    => 'runbook',
            default => 'module',
        };
    }

    $proposed = [
        'human_name'      => $name !== '' ? $name : $pascal($gid),
        'canonical_name'  => $name !== '' ? $name : $pascal($gid),
        'technical_name'  => $tech,
        'cartography_type'=> $type,
        'canonical_source'=> $rel,
    ];

    $missing = [];
    foreach ($ESSENTIAL as $f) {
        $have = isset($fm[$f]) && trim((string)$fm[$f]) !== '';
        if (!$have) $missing[$f] = $proposed[$f];
    }

    $report[] = sprintf("%-58s +[%s]", $gid, implode(',', array_keys($missing)));
    foreach ($missing as $k=>$v) $report[] = "      ".$emit($k,$v);

    if ($WRITE && $missing) {
        $raw = file_get_contents($entry['path']);
        $lines = preg_split('/\n/', $raw);
        $out = [];
        $done = false;
        foreach ($lines as $ln) {
            $out[] = $ln;
            if (!$done && preg_match('/^graph_id:\s*'.preg_quote($gid,'/').'\s*$/', rtrim($ln,"\r"))) {
                foreach ($missing as $k=>$v) $out[] = $emit($k,$v);
                $done = true;
            }
        }
        if (!$done) { $report[] = "   !! graph_id anchor not found, SKIPPED $gid"; continue; }
        file_put_contents($entry['path'], implode("\n", $out));
    }
}

echo implode("\n", $report)."\n";
echo "\nTOTAL fallback docs: ".count($fallbackIds)."  WRITE=".($WRITE?'YES':'no(dry-run)')."\n";
