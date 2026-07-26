<?php

declare(strict_types=1);

namespace App\Services\Ai\Hermes;

/**
 * Pure helpers for Atlas `atlas_apply_patch` native function-call transport (R104-TRANSPORT).
 *
 * Declares the canonical tool schema Atlas expects the provider/model surface to honor,
 * and lifts structured tool-call facts from provider text when the transport cannot
 * emit OpenAI-shaped metadata natively (Hermes CLI/ACP text channel).
 *
 * Capability facts never come from model-name suffixes — only from transport attestors.
 */
final class HermesNativeFunctionCallSupport
{
    public const TOOL_NAME = 'atlas_apply_patch';

    /**
     * Canonical tool declaration Atlas packages for native FC channel runs.
     *
     * @return array{name:string,description:string,parameters:array<string,mixed>}
     */
    public static function atlasApplyPatchDeclaration(): array
    {
        return [
            'name' => self::TOOL_NAME,
            'description' => 'Apply one scoped file change. Atlas packages the server-side patch_plan from these arguments; do not emit a nested patch_plan JSON envelope.',
            'parameters' => [
                'type' => 'object',
                'required' => ['path', 'mode', 'next'],
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Repo-relative path inside the authorized claim scope.',
                    ],
                    'mode' => [
                        'type' => 'string',
                        'enum' => ['create', 'modify'],
                    ],
                    'next' => [
                        'type' => 'string',
                        'description' => 'Full next file contents.',
                    ],
                ],
            ],
        ];
    }

    /**
     * Compact declare block for prompt/transport surfaces that only accept text tools.
     */
    public static function declarePromptBlock(): string
    {
        $tool = self::atlasApplyPatchDeclaration();

        return "[atlas_declared_tools]\n"
            .json_encode([$tool], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
            ."If and only if a scoped file change is required, reply with ONLY this JSON:\n"
            .'{"tool_calls":[{"name":"'.self::TOOL_NAME.'","arguments":{"path":"<repo-relative>","mode":"create|modify","next":"<full file contents>"}}]}'
            ."\nDo not emit a model-authored patch_plan JSON envelope.";
    }

    /**
     * Lift structured tool_calls from free text (OpenAI-shaped or compact Atlas JSON).
     *
     * @return list<array{type:string,function:array{name:string,arguments:array<string,mixed>}}>
     */
    public static function parseToolCallsFromText(string $content): array
    {
        $json = trim($content);
        if ($json === '') {
            return [];
        }
        if (! str_starts_with($json, '{')) {
            $start = strpos($json, '{');
            $end = strrpos($json, '}');
            if ($start === false || $end === false || $end <= $start) {
                return [];
            }
            $json = substr($json, $start, $end - $start + 1);
        }
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }

        $rawCalls = $decoded['tool_calls'] ?? $decoded['function_calls'] ?? null;
        if (! is_array($rawCalls)) {
            if (is_array($decoded['function_call'] ?? null) || is_array($decoded['tool_call'] ?? null)) {
                $rawCalls = [$decoded['function_call'] ?? $decoded['tool_call']];
            } else {
                return [];
            }
        }

        $calls = [];
        foreach (array_is_list($rawCalls) ? $rawCalls : [$rawCalls] as $call) {
            if (! is_array($call)) {
                continue;
            }
            $function = is_array($call['function'] ?? null) ? $call['function'] : $call;
            $name = trim((string) ($function['name'] ?? $call['name'] ?? ''));
            $arguments = $function['arguments'] ?? $function['args'] ?? $call['arguments'] ?? $call['args'] ?? null;
            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true);
            }
            if ($name === '' || ! is_array($arguments)) {
                continue;
            }
            $calls[] = [
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'arguments' => $arguments,
                ],
            ];
        }

        return $calls;
    }

    /**
     * @param  list<mixed>  $toolCalls
     * @return list<array{type:string,function:array{name:string,arguments:array<string,mixed>}}>
     */
    public static function normalizeToolCallsList(array $toolCalls): array
    {
        $out = [];
        foreach ($toolCalls as $call) {
            if (! is_array($call)) {
                continue;
            }
            $function = is_array($call['function'] ?? null) ? $call['function'] : $call;
            $name = trim((string) ($function['name'] ?? $call['name'] ?? ''));
            $arguments = $function['arguments'] ?? $function['args'] ?? $call['arguments'] ?? $call['args'] ?? null;
            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true);
            }
            if ($name === '' || ! is_array($arguments)) {
                continue;
            }
            $out[] = [
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'arguments' => $arguments,
                ],
            ];
        }

        return $out;
    }
}
