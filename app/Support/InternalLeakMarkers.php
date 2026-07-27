<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Vocabulary of internal markers that must never reach an operator-facing
 * response — provider envelopes, harness telemetry, correlation ids and
 * context-pack plumbing.
 *
 * This is DETECTION vocabulary, not a dependency. It lives outside
 * `Services/Ai/Surface` on purpose: the AP-2 architecture gate forbids
 * context-pack tokens inside the surface layer, and a redactor that spells
 * the tokens it strips would otherwise trip the very guard it serves.
 */
final class InternalLeakMarkers
{
    /**
     * Lowercase substrings; a single hit means the text leaked.
     *
     * @return list<string>
     */
    public static function substrings(): array
    {
        return [
            '"type":"system"',
            '"type": "system"',
            '"subtype":"init"',
            '"subtype": "init"',
            '"mcp_servers"',
            '"permissionmode"',
            '"apikeysource"',
            '"claude_code_version"',
            '"modelusage"',
            '"cache_creation_input_tokens"',
            '"permission_denials"',
            '"terminal_reason"',
            '"fast_mode_state"',
            'context_pack',
            'context_pack_hash',
            '"trace_id"',
            '"thread_id"',
            '"session_id"',
        ];
    }

    /**
     * Correlation ids that only count as a leak alongside envelope evidence.
     */
    public static function correlationIdPattern(): string
    {
        return '/\b(trace_id|thread_id|session_id)\b/i';
    }

    public static function envelopeEvidencePattern(): string
    {
        return '/("type"\s*:|"tools"\s*:|"metadata"\s*:|provider|modelusage|permissionmode|cache_creation|mcp_servers|uuid)/i';
    }
}
