<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

/**
 * Compiles free-text (or structured) intent into a canonical Objective envelope.
 * Policy-pure: no I/O.
 */
final class AaeosIntentCompiler
{
    public const SCHEMA = 'atlas.aaeos.objective.v1';

    /**
     * @param  array<string,mixed>  $hints
     * @return array{
     *   schema:string,
     *   objective:string,
     *   source:string,
     *   interactive:bool,
     *   multi_packet:bool,
     *   self_evolve:bool,
     *   irreversible:bool,
     *   business_ambiguous:bool,
     *   modules_hint:int,
     *   raw_length:int
     * }
     */
    public function compile(string $raw, array $hints = []): array
    {
        $objective = trim($raw);
        $source = strtolower(trim((string) ($hints['source'] ?? 'human')));
        $interactive = array_key_exists('interactive', $hints)
            ? (bool) $hints['interactive']
            : in_array($source, ['human', 'dev', 'cli', 'ask', 'session'], true);

        $multi = array_key_exists('multi_packet', $hints)
            ? (bool) $hints['multi_packet']
            : (bool) preg_match('/\b(obra|multi[- ]?packet|sdd|enterprise|multi[- ]?agent)\b/i', $objective);

        $selfEvolve = array_key_exists('self_evolve', $hints)
            ? (bool) $hints['self_evolve']
            : in_array($source, ['brain', 'autonomos', 'night', 'queue'], true)
                || (bool) preg_match('/\b(self[- ]?evolv|night[- ]?shift|autonom)/i', $objective);

        $irreversible = array_key_exists('irreversible', $hints)
            ? (bool) $hints['irreversible']
            : (bool) preg_match('/\b(prod(uction)?\s*wipe|drop\s+database|force[- ]?push|legal|compliance|billing|payment)\b/i', $objective);

        $bizAmbiguous = array_key_exists('business_ambiguous', $hints)
            ? (bool) $hints['business_ambiguous']
            : ($objective === '' || (bool) preg_match('/\b(maybe|somehow|whatever|tbd|figure\s+out\s+what)\b/i', $objective));

        $modules = (int) ($hints['modules_hint'] ?? 0);
        if ($modules <= 0 && preg_match_all('/\b(app\/|module|service|package)\b/i', $objective, $m)) {
            $modules = max(1, count($m[0]));
        }

        return [
            'schema' => self::SCHEMA,
            'objective' => $objective !== '' ? $objective : '(empty_objective)',
            'source' => $source !== '' ? $source : 'unknown',
            'interactive' => $interactive,
            'multi_packet' => $multi,
            'self_evolve' => $selfEvolve,
            'irreversible' => $irreversible,
            'business_ambiguous' => $bizAmbiguous,
            'modules_hint' => max(0, $modules),
            'raw_length' => strlen($raw),
        ];
    }
}
