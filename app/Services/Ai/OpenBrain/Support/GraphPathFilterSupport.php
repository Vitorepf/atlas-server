<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrain\Support;

use App\Services\Ai\AtlasOpenBrainWriteBackService;

/**
 * Pure helpers for {@see \App\Services\Ai\AtlasOpenBrainContextPackService}
 * graph-path filtering (full-pass peel): label sanitize, session-echo detection,
 * documentation-mission path drop, and runtime fingerprint hash.
 * No DB, no workspace identity, no I/O.
 */
final class GraphPathFilterSupport
{
    /** Hard cap for an untrusted reality-graph node label rendered into the model context. */
    public const GRAPH_LABEL_MAX_CHARS = 160;

    /**
     * Reality-graph node labels are UNTRUSTED display text — a label can be a verbatim past operator
     * prompt (the session-capture mission node seeds its label from the first user prompt). Collapse
     * all whitespace to a single line and hard-cap, so no multi-line / oversized raw text is ever
     * replayed into the model context through the graph section.
     */
    public static function sanitizeGraphLabel(string $raw): string
    {
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $raw));

        return mb_substr($collapsed, 0, self::GRAPH_LABEL_MAX_CHARS);
    }

    /**
     * Session ECHO labels are old operator prompts or control markers that have no architectural
     * value as cross-layer graph paths. Empty labels are not echo: real nodes may render by id.
     */
    public static function isSessionArtifactLabel(string $label): bool
    {
        $label = mb_strtolower(trim($label));
        if ($label === '') {
            return false;
        }
        if (in_array($label, ['session capture', '[request interrupted by user]', '[request interrupted by user for tool use]', 'continue from where you left off.'], true)) {
            return true;
        }

        foreach ([
            'você é um', 'voce e um', 'vc é um', 'vc e um',
            'que merda', 'xingando',
            'você não', 'voce nao', 'vc não', 'vc nao', 'não entendeu', 'nao entendeu',
            'me confirma', 'me fala mais', 'faça uma', 'faca uma', 'precisamos fazer',
            'vc pode', 'você pode', 'voce pode', 'preciso que',
            'o que eu quero', 'tem um codex rodando',
            'pelo o que entendi', 'basicamente pegar uma area', 'evoluir ela',
            'my request for codex', 'continue from where you left off',
        ] as $marker) {
            if (str_contains($label, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $path
     * @param  array<int,array<string,mixed>>  $chain
     */
    public static function isSessionArtifactPath(array $path, array $chain): bool
    {
        foreach (['target', 'seed'] as $field) {
            if (self::isSessionArtifactLabel((string) ($path[$field] ?? ''))) {
                return true;
            }
        }

        foreach ($chain as $node) {
            // PROVENANCE beats heuristics: a mission minted by the AOBG write-back
            // (session capture) carries meta.origin — its label is raw session text,
            // never an operator decision, regardless of what the text looks like.
            if (($node['origin'] ?? '') === AtlasOpenBrainWriteBackService::MISSION_ORIGIN_SESSION_CAPTURE) {
                return true;
            }
            if (self::isSessionArtifactLabel((string) ($node['label'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $path
     * @param  array<int,array<string,mixed>>  $chain
     */
    public static function isDocumentationMissionPath(string $task, array $path, array $chain): bool
    {
        if (preg_match('/\b(doc|docs|document|documentation|backlog|kb|knowledge|canonical|canonica|canônica)\b/iu', $task) === 1) {
            return false;
        }

        foreach ($chain as $node) {
            if (($node['source_kind'] ?? null) !== 'mission') {
                continue;
            }

            $text = mb_strtolower((string) ($node['label'] ?? '').' '.(string) ($path['target'] ?? ''));
            if (preg_match('/\b(doc|docs|document|documentation|backlog|knowledge|canonical|canonica|canônica)\b/iu', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stable runtime identity hash over schema + version + feature flags.
     * Pure: features are sorted locally; schema/version are explicit inputs
     * (service passes its RUNTIME_* constants).
     *
     * @param  array<int,string>  $features
     */
    public static function runtimeFingerprint(array $features, string $schemaVersion, string $runtimeVersion): string
    {
        sort($features);

        return hash('sha256', (string) json_encode([
            'schema_version' => $schemaVersion,
            'runtime_version' => $runtimeVersion,
            'feature_flags' => $features,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
