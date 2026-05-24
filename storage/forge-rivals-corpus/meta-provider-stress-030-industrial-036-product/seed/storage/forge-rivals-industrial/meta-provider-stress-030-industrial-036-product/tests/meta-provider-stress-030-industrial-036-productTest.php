<?php

declare(strict_types=1);

$source = __DIR__.'/../src/meta-provider-stress-030-industrial-036-product.php';
$manifest = dirname(__DIR__).'/fixture.json';

if (! is_file($source)) {
    fwrite(STDERR, "missing source fixture\n");
    exit(1);
}

if (! is_file($manifest)) {
    fwrite(STDERR, "missing fixture manifest\n");
    exit(1);
}

$sourceText = (string) file_get_contents($source);
$manifestPayload = json_decode((string) file_get_contents($manifest), true);

if (! str_contains($sourceText, 'meta-provider-stress-030-industrial-036-product')) {
    fwrite(STDERR, "source fixture case id mismatch\n");
    exit(1);
}

if (! is_array($manifestPayload) || ($manifestPayload['case_id'] ?? null) !== 'meta-provider-stress-030-industrial-036-product') {
    fwrite(STDERR, "manifest case id mismatch\n");
    exit(1);
}

echo "industrial fixture meta-provider-stress-030-industrial-036-product ok\n";