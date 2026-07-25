<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence\Support;

use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Str;

/**
 * Pure operator learning classification helpers (full-pass peel).
 */
final class OperatorLearningClassifySupport
{
    public static function sanitizeClaim(string $claim): string
    {
        $claim = trim(preg_replace('/\s+/', ' ', $claim) ?? '');

        return Str::limit($claim, 1000, '');
    }

    public static function normalizeTaxonomy(string $taxonomy, string $claim): string
    {
        $taxonomy = strtoupper(trim($taxonomy));
        if (preg_match('/^(SYS|OP|COL)-\d{3}$/', $taxonomy) === 1) {
            return $taxonomy;
        }

        $lower = Str::lower($claim);
        if (str_contains($lower, 'autonom') || str_contains($lower, 'approval') || str_contains($lower, 'aprov')) {
            return 'COL-157';
        }
        if (str_contains($lower, 'curto') || str_contains($lower, 'longo') || str_contains($lower, 'tom') || str_contains($lower, 'resposta')) {
            return 'COL-156';
        }
        if (str_contains($lower, 'gosto') || str_contains($lower, 'prefiro') || str_contains($lower, 'nao gosto')) {
            return 'OP-124';
        }
        if (str_contains($lower, 'nunca') || str_contains($lower, 'nao mexa') || str_contains($lower, 'bloque')) {
            return 'OP-140';
        }

        return 'OP-071';
    }

    public static function inferSignalKind(string $claim, string $taxonomy): string
    {
        $lower = Str::lower($claim);
        if (str_contains($lower, 'nunca') || str_contains($lower, 'nao mexa')) {
            return 'operator_boundary';
        }
        if (str_starts_with($taxonomy, 'COL-')) {
            return 'collaboration_preference';
        }

        return 'operator_preference';
    }

    public static function inferPrivacy(string $claim): string
    {
        $lower = Str::lower($claim);
        foreach (['senha', 'token', 'secret', 'key', 'credential', 'credencial'] as $needle) {
            if (str_contains($lower, $needle)) {
                return 'secret';
            }
        }
        foreach (['saude', 'familia', 'relacionamento', 'dinheiro', 'documento'] as $needle) {
            if (str_contains($lower, $needle)) {
                return 'sensitive';
            }
        }

        return 'normal';
    }

    public static function inferRisk(string $claim, string $privacy): string
    {
        if (in_array($privacy, ['sensitive', 'secret'], true)) {
            return 'high';
        }

        $lower = Str::lower($claim);
        foreach (['delet', 'apagar', 'overwrite', 'comprar', 'vender', 'publicar', 'enviar'] as $needle) {
            if (str_contains($lower, $needle)) {
                return 'high';
            }
        }

        return 'low';
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function normalizeFromList(string $value, array $allowed, string $default): string
    {
        $value = Str::lower(trim($value));

        return in_array($value, $allowed, true) ? $value : $default;
    }

    public static function confidence(mixed $value, string $risk, string $privacy, string $inferenceType = 'explicit', string $tier = ''): float
    {
        $confidence = AiValueNormalizer::finiteFloatOrNull($value) ?? 0.5;
        $confidence = max(0.0, min(1.0, $confidence));

        if ($inferenceType === 'implicit' || in_array($tier, ['single_inference', 'repeated'], true)) {
            $confidence = min($confidence, 0.6);
        }
        if ($risk !== 'low' || $privacy !== 'normal') {
            $confidence = min($confidence, 0.74);
        }

        return $confidence;
    }

    public static function redactIfSensitive(string $claim, string $privacy): string
    {
        if (in_array($privacy, ['sensitive', 'secret'], true)) {
            return '[redacted:'.$privacy.':'.substr(hash('sha256', $claim), 0, 12).']';
        }

        return $claim;
    }

    public static function privacyRaiseOnly(string $a, string $b): string
    {
        $rank = ['normal' => 0, 'private' => 1, 'sensitive' => 2, 'secret' => 3];

        return ($rank[$a] ?? 0) >= ($rank[$b] ?? 0) ? ($a !== '' ? $a : 'normal') : $b;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function rawExcerptHash(array $input): ?string
    {
        $raw = $input['raw_excerpt'] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            return self::nullableString($input['raw_excerpt_hash'] ?? null);
        }

        return hash('sha256', $raw);
    }

    /**
     * @return array<int|string, mixed>
     */
    public static function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    public static function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public static function clean(string $value, string $default): string
    {
        $value = trim($value);

        return $value === '' ? $default : Str::limit($value, 160, '');
    }
}
