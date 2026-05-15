<?php

declare(strict_types=1);

namespace App\Http\Controllers\Evidence;

/**
 * Vulnerable initial state — accepts raw filename.
 */
final class EvidenceDownloadController
{
    public function show(string $filename): string
    {
        // Vulnerable: no validation, no canonicalization.
        return (string) file_get_contents(storage_path('evidence/'.$filename));
    }
}
