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

    public function test_all_commands_start_with_homebrew_php_artisan(): void
    {
        $prefix = TerminalLoopOperationalProofCommandFactory::PHP_BIN.' artisan';
        self::assertStringStartsWith($prefix, TerminalLoopOperationalProofCommandFactory::terminalLoopOperationalProofCommand());
        self::assertStringStartsWith($prefix, TerminalLoopOperationalProofCommandFactory::terminalLoopOperationalProofBindingPersistCommand());
        self::assertStringStartsWith($prefix, TerminalLoopOperationalProofCommandFactory::completionAuditWithTerminalLoopOperationalProofCommand());
        self::assertStringStartsWith($prefix, TerminalLoopOperationalProofCommandFactory::completionAuditWithCanonicalTerminalLoopOperationalProofCommand());
    }

    public function test_proof_commands_returns_four_deterministic_entries(): void
    {
        $cmds = TerminalLoopOperationalProofCommandFactory::proofCommands();
        self::assertCount(4, $cmds);
        $labels = array_column($cmds, 'label');
        self::assertContains('status', $labels);
        self::assertContains('persist_binding', $labels);
        self::assertContains('audit_with_placeholder', $labels);
        self::assertContains('audit_with_canonical_path', $labels);
    }

    public function test_proof_commands_every_command_starts_with_homebrew_php(): void
    {
        $prefix = TerminalLoopOperationalProofCommandFactory::PHP_BIN.' artisan';
        foreach (TerminalLoopOperationalProofCommandFactory::proofCommands() as $entry) {
            self::assertStringStartsWith($prefix, $entry['command'], "command '{$entry['label']}' must use homebrew php");
        }
    }

    public function test_proof_commands_artifact_entries_reference_canonical_path(): void
    {
        $canonicalPath = TerminalLoopOperationalProofCommandFactory::terminalLoopOperationalProofBindingArtifactPath();
        foreach (TerminalLoopOperationalProofCommandFactory::proofCommands() as $entry) {
            if (isset($entry['artifact'])) {
                self::assertSame($canonicalPath, $entry['artifact']);
            }
        }
    }

    public function test_proof_commands_is_deterministic(): void
    {
        self::assertSame(
            TerminalLoopOperationalProofCommandFactory::proofCommands(),
            TerminalLoopOperationalProofCommandFactory::proofCommands(),
        );
    }
}
