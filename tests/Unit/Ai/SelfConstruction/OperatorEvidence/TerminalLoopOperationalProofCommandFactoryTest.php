<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\OperatorEvidence;

use App\Services\Ai\SelfConstruction\OperatorEvidence\TerminalLoopOperationalProofCommandFactory;
use Tests\TestCase;

class TerminalLoopOperationalProofCommandFactoryTest extends TestCase
{
    public function test_proof_command_contains_status_flag(): void
    {
        $cmd = TerminalLoopOperationalProofCommandFactory::terminalLoopOperationalProofCommand();

        self::assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-status', $cmd);
        self::assertStringContainsString('--json', $cmd);
    }

    public function test_binding_persist_command_contains_persist_flag(): void
    {
        $cmd = TerminalLoopOperationalProofCommandFactory::terminalLoopOperationalProofBindingPersistCommand();

        self::assertStringContainsString('--persist-terminal-loop-operational-proof-binding', $cmd);
    }

    public function test_completion_audit_command_contains_audit_flag(): void
    {
        $cmd = TerminalLoopOperationalProofCommandFactory::completionAuditWithTerminalLoopOperationalProofCommand();

        self::assertStringContainsString('--atlas-self-construction-os-completion-audit-status', $cmd);
        self::assertStringContainsString('terminal-loop-operational-proof-binding.json', $cmd);
    }

    public function test_completion_audit_canonical_command_uses_canonical_path(): void
    {
        $cmd = TerminalLoopOperationalProofCommandFactory::completionAuditWithCanonicalTerminalLoopOperationalProofCommand();

        self::assertStringContainsString('--atlas-self-construction-os-completion-audit-status', $cmd);
        self::assertStringContainsString('storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json', $cmd);
    }

    public function test_artifact_path_is_canonical(): void
    {
        $path = TerminalLoopOperationalProofCommandFactory::terminalLoopOperationalProofBindingArtifactPath();

        self::assertSame('storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json', $path);
    }

    public function test_all_methods_are_deterministic(): void
    {
        self::assertSame(
            TerminalLoopOperationalProofCommandFactory::terminalLoopOperationalProofCommand(),
            TerminalLoopOperationalProofCommandFactory::terminalLoopOperationalProofCommand(),
        );
    }

    public function test_all_commands_contain_php_artisan(): void
    {
        self::assertStringContainsString('php artisan', TerminalLoopOperationalProofCommandFactory::terminalLoopOperationalProofCommand());
        self::assertStringContainsString('php artisan', TerminalLoopOperationalProofCommandFactory::terminalLoopOperationalProofBindingPersistCommand());
        self::assertStringContainsString('php artisan', TerminalLoopOperationalProofCommandFactory::completionAuditWithTerminalLoopOperationalProofCommand());
        self::assertStringContainsString('php artisan', TerminalLoopOperationalProofCommandFactory::completionAuditWithCanonicalTerminalLoopOperationalProofCommand());
    }
}
