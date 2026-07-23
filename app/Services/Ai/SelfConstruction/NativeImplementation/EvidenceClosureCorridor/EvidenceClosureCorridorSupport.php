<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeImplementation\EvidenceClosureCorridor;

use App\Services\Ai\SelfConstruction\Support\FinalOperatorClosureCorridorHashSupport;

/**
 * GOD-DEBULK leaf helper shared by the EvidenceClosureCorridor section classes.
 *
 * Holds the small pure helpers that more than one section needs. Bodies are
 * verbatim from the façade so every emitted value / *_hash stays byte-identical;
 * `stableHash` routes through the same {@see FinalOperatorClosureCorridorHashSupport}
 * owner the façade already uses.
 */
final class EvidenceClosureCorridorSupport
{
    /** @param array<string, mixed> $payload */
    public function stableHash(array $payload): string
    {
        return (new FinalOperatorClosureCorridorHashSupport)->stableHash($payload);
    }

    public function placeholderFields(string $command): array
    {
        preg_match_all('/<[^>]+>|@\/path\/to\/[^\s]+/', $command, $matches);

        return array_values(array_unique(array_map(static fn (string $value): string => trim($value), $matches[0] ?? [])));
    }

    public function completionAuditWithTerminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json --json';
    }

    public function completionAuditWithCanonicalTerminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json --json';
    }
}
