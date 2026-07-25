<?php

declare(strict_types=1);

namespace App\Services\Ai\AiWorkerSupport;

/**
 * Pure git --shortstat parser (full-pass peel from AiWorker).
 */
final class AiWorkerGitShortstatSupport
{
    /**
     * @return array{files_touched:int,lines_added:int,lines_removed:int}|null
     */
    public static function parse(string $out): ?array
    {
        $out = trim($out);
        if ($out === '' || ! preg_match('/(\d+)\s+files?\s+changed/', $out, $files)) {
            return null;
        }
        preg_match('/(\d+)\s+insertions?\(\+\)/', $out, $ins);
        preg_match('/(\d+)\s+deletions?\(-\)/', $out, $del);

        return [
            'files_touched' => (int) $files[1],
            'lines_added' => (int) ($ins[1] ?? 0),
            'lines_removed' => (int) ($del[1] ?? 0),
        ];
    }
}
