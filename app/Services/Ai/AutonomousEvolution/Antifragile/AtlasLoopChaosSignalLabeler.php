<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Antifragile;

/**
 * Converts negative loop events into attributable, shape-keyed learning signals.
 *
 * The labeler is intentionally fact-only: without a shape_token it refuses to mint a lesson, because that
 * would launder a generic failure into false learning.
 */
final class AtlasLoopChaosSignalLabeler
{
    public const SCHEMA_VERSION = 'atlas.loop.chaos_signal_labeler.v1';

    /** @var list<string> */
    public const EVENT_KINDS = ['judge_rejected', 'canary_failed', 'given_back', 'provider_degraded'];

    /**
     * @param  array{kind?:mixed, shape_token?:mixed, provider?:mixed, reason?:mixed}  $event
     * @return array{schema:string, label:string, attributable:bool, shape_token:?string, lesson:?string}
     */
    public function label(array $event): array
    {
        $kind = $this->kind((string) ($event['kind'] ?? ''));
        $shapeToken = $this->text($event['shape_token'] ?? null);

        if ($shapeToken === null) {
            return [
                'schema' => self::SCHEMA_VERSION,
                'label' => 'unattributable_'.$kind,
                'attributable' => false,
                'shape_token' => null,
                'lesson' => null,
            ];
        }

        $provider = $this->text($event['provider'] ?? null);
        $reason = $this->text($event['reason'] ?? null);

        return [
            'schema' => self::SCHEMA_VERSION,
            'label' => $kind.':'.$this->labelToken($shapeToken),
            'attributable' => true,
            'shape_token' => $shapeToken,
            'lesson' => $this->lesson($kind, $shapeToken, $provider, $reason),
        ];
    }

    private function kind(string $kind): string
    {
        $kind = strtolower(trim($kind));

        return in_array($kind, self::EVENT_KINDS, true) ? $kind : 'unknown_negative_event';
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function labelToken(string $shapeToken): string
    {
        $token = strtolower((string) preg_replace('/[^A-Za-z0-9_.:-]+/', '-', $shapeToken));
        $token = trim($token, '-');

        return $token !== '' ? $token : substr(hash('sha256', $shapeToken), 0, 12);
    }

    private function lesson(string $kind, string $shapeToken, ?string $provider, ?string $reason): string
    {
        $parts = ["shape_token={$shapeToken}", "kind={$kind}"];
        if ($provider !== null) {
            $parts[] = "provider={$provider}";
        }
        if ($reason !== null) {
            $parts[] = "reason={$reason}";
        }

        return 'Preserve negative event as learning signal: '.implode('; ', $parts).'.';
    }
}
