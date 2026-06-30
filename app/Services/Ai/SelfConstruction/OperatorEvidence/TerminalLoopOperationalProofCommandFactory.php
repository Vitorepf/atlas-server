<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\OperatorEvidence;

/**
 * Builds terminal-loop operational-proof command strings for the Atlas
 * Self-Construction operator evidence submission readiness service.
 *
 * Extracted from AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService
 * to reduce the god-class. All methods are pure — no instance state.
 */
final class TerminalLoopOperationalProofCommandFactory
{
    public const PHP_BIN = '/opt/homebrew/bin/php';

    public static function terminalLoopOperationalProofCommand(): string
    {
        return self::PHP_BIN.' artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --json';
    }

    public static function terminalLoopOperationalProofBindingPersistCommand(): string
    {
        return self::PHP_BIN.' artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --persist-terminal-loop-operational-proof-binding --json';
    }

    public static function completionAuditWithTerminalLoopOperationalProofCommand(): string
    {
        return self::PHP_BIN.' artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json --json';
    }

    public static function completionAuditWithCanonicalTerminalLoopOperationalProofCommand(): string
    {
        return self::PHP_BIN.' artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@'.self::terminalLoopOperationalProofBindingArtifactPath().' --json';
    }

    public static function terminalLoopOperationalProofBindingArtifactPath(): string
    {
        return 'storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json';
    }

    /**
     * Deterministic catalogue of all proof commands and their artifact paths for copy-paste safety.
     * No string duplication — each command is assembled exactly once by its dedicated method.
     *
     * @return list<array{label:string, command:string, artifact?:string}>
     */
    public static function proofCommands(): array
    {
        return [
            ['label' => 'status', 'command' => self::terminalLoopOperationalProofCommand()],
            ['label' => 'persist_binding', 'command' => self::terminalLoopOperationalProofBindingPersistCommand(), 'artifact' => self::terminalLoopOperationalProofBindingArtifactPath()],
            ['label' => 'audit_with_placeholder', 'command' => self::completionAuditWithTerminalLoopOperationalProofCommand()],
            ['label' => 'audit_with_canonical_path', 'command' => self::completionAuditWithCanonicalTerminalLoopOperationalProofCommand(), 'artifact' => self::terminalLoopOperationalProofBindingArtifactPath()],
        ];
    }
}
