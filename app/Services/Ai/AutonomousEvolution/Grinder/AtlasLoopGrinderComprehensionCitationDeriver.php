<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Grinder;

use App\Services\Ai\Support\AiStringListNormalizer;

/**
 * Grounding-citation helpers for the Atlas loop task grinder.
 *
 * Extracted from AtlasLoopTaskGrinder to reduce the god-class. Pure static
 * methods; no instance state.
 */
final class AtlasLoopGrinderComprehensionCitationDeriver
{
    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $explorerTask
     * @return list<string>
     */
    public static function semanticAllowedFiles(array $payload, array $explorerTask): array
    {
        $files = [];
        foreach ([$payload['allowed_files'] ?? [], $explorerTask['allowed_files'] ?? []] as $source) {
            foreach (AiStringListNormalizer::trimmedStrings($source) as $file) {
                $files[] = $file;
            }
        }

        return AiStringListNormalizer::uniqueStrings($files);
    }

    /**
     * COMPREHENSION GROUNDING GATE inputs — the concrete symbols the proposal's
     * stated objective rests on. Each declared file becomes a cited symbol via
     * its class-name shape: basename minus the `.php` extension. Empty => the
     * gate fails OPEN (grounded=true).
     *
     * Only CLASS-SHAPED basenames (StudlyCase) are citations: a non-class file
     * (routes/api.php, config/atlas.php, snake_case scripts) never declared a
     * symbol, so treating its basename as one made the gate flag every honest
     * HTTP-route/config task as "hallucinated" ('api' resolves nowhere) and
     * drop its certified proposal. The gate's own contract says it refutes
     * only positively-fabricated citations — a path fragment is not one.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $explorerTask
     * @return list<string>
     */
    public static function comprehensionCitations(array $payload, array $explorerTask): array
    {
        $citations = [];
        foreach (self::semanticAllowedFiles($payload, $explorerTask) as $file) {
            $base = basename(trim($file));
            if (str_ends_with($base, '.php')) {
                $base = substr($base, 0, -4);
            }
            $base = trim($base);
            if ($base !== '' && preg_match('/^[A-Z][A-Za-z0-9]*$/', $base) === 1) {
                $citations[] = $base;
            }
        }

        return AiStringListNormalizer::uniqueStrings($citations);
    }
}