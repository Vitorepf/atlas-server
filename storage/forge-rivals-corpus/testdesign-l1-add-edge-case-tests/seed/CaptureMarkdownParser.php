<?php

declare(strict_types=1);

namespace App\Domain\Captures;

/**
 * Read-only fixture. The arm MUST NOT modify this file; the goal of
 * the case is to add edge-case tests around the existing behaviour.
 *
 * Contract:
 *   - parse('') → ['body' => '', 'json' => null]
 *   - parse('   ') → ['body' => '', 'json' => null]
 *   - parse('hello') → ['body' => 'hello', 'json' => null]
 *   - parse with a fenced ```json block → json field contains the decoded value
 *   - invalid json inside fenced block → ['body' => '<body>', 'json' => ['error' => 'invalid_json']]
 */
final class CaptureMarkdownParser
{
    /** @return array{body:string,json:array<string,mixed>|null} */
    public static function parse(string $markdown): array
    {
        $body = trim($markdown);
        if (preg_match('/```json\s*\n(.*?)\n```/s', $body, $m)) {
            $body = trim((string) preg_replace('/```json\s*\n.*?\n```/s', '', $body));
            try {
                $json = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
                $json = is_array($json) ? $json : ['value' => $json];
            } catch (\JsonException) {
                $json = ['error' => 'invalid_json'];
            }
        } else {
            $json = null;
        }

        return ['body' => $body, 'json' => $json];
    }
}
