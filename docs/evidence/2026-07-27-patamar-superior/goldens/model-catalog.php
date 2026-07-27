<?php

declare(strict_types=1);

// Golden capture (standalone — never through phpunit) for the model catalog
// duplicated between AiChatModelSection and AtlasCliModelCatalogService.

$root = '/Users/vitorepf/develop/Atlas/atlas-server';
require $root.'/tests/bootstrap.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$callPrivate = static function (object $obj, string $method, array $args = []) {
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);

    return $ref->invokeArgs($obj, $args);
};

$out = [];

$section = (new ReflectionClass(App\Console\Commands\AiChat\AiChatModelSection::class))
    ->newInstanceWithoutConstructor();
$service = app(App\Services\Ai\Cli\AtlasCliModelCatalogService::class);

try {
    $out['section']['catalog'] = $callPrivate($section, 'modelCatalog');
} catch (Throwable $e) {
    $out['section']['catalog'] = 'ERR: '.$e->getMessage();
}
$out['service']['catalog'] = $service->catalog();

$providers = [null, 'claude_cli', 'codex_cli', 'gemini_cli', 'hermes_cli', 'claude_codex', 'unknown-provider'];
foreach ($providers as $p) {
    $key = $p ?? '(null)';
    try {
        $out['section']['display'][$key] = $callPrivate($section, 'providerDisplayName', [$p]);
    } catch (Throwable $e) {
        $out['section']['display'][$key] = 'ERR: '.$e->getMessage();
    }
    try {
        $out['service']['display'][$key] = $callPrivate($service, 'providerDisplayName', [$p]);
    } catch (Throwable $e) {
        $out['service']['display'][$key] = 'ERR: '.$e->getMessage();
    }
}

// Anti-vacuum: the catalog must actually carry rows with the shape callers read.
$rows = $out['service']['catalog'];
if (! is_array($rows) || count($rows) < 3) {
    fwrite(STDERR, 'GOLDEN VACUOUS — catalog has '.(is_array($rows) ? count($rows) : 0)." rows\n");
    exit(1);
}
foreach (['alias', 'provider', 'label', 'tier'] as $field) {
    if (! array_key_exists($field, $rows[0])) {
        fwrite(STDERR, "GOLDEN VACUOUS — catalog row missing [{$field}]\n");
        exit(1);
    }
}
if (count(array_unique($out['service']['display'])) < 2) {
    fwrite(STDERR, "GOLDEN VACUOUS — providerDisplayName does not discriminate\n");
    exit(1);
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
