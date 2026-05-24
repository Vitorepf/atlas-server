<?php

declare(strict_types=1);

$source = __DIR__.'/../src/ceiling-360-097-industrial-085-documentation-v3.php';
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

if (! str_contains($sourceText, 'ceiling-360-097-industrial-085-documentation-v3')) {
    fwrite(STDERR, "source fixture case id mismatch\n");
    exit(1);
}

if (! is_array($manifestPayload) || ($manifestPayload['case_id'] ?? null) !== 'ceiling-360-097-industrial-085-documentation-v3') {
    fwrite(STDERR, "manifest case id mismatch\n");
    exit(1);
}

echo "industrial fixture ceiling-360-097-industrial-085-documentation-v3 ok\n";