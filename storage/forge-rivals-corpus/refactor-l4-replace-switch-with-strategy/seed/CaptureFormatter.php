<?php

declare(strict_types=1);

namespace App\Domain\Captures\Format;

final class CaptureFormatter
{
    /**
     * SEED: 6-branch switch. The arm must replace this with a
     * StrategyRegistry-backed dispatch while keeping byte-for-byte
     * output for every existing format.
     *
     * @param  array<string,mixed>  $capture
     */
    public function format(array $capture, string $format): string
    {
        $body = (string) ($capture['body'] ?? '');
        switch ($format) {
            case 'json':
                return (string) json_encode($capture, JSON_THROW_ON_ERROR);
            case 'csv':
                return implode(',', array_map(fn ($v) => (string) $v, array_values($capture)));
            case 'md':
                return '## '.$body;
            case 'html':
                return '<p>'.htmlspecialchars($body, ENT_QUOTES).'</p>';
            case 'xml':
                return '<capture><body>'.htmlspecialchars($body, ENT_QUOTES).'</body></capture>';
            case 'plain':
                return $body;
            default:
                throw new \InvalidArgumentException("unknown_format:{$format}");
        }
    }
}
