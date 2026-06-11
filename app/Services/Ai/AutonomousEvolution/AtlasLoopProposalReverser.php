<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopProposal;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * G5 (materialização) — a alça de REVERSE do loop: todo proposal certificado passa
 * a carregar um artefato de rollback PROVADO, não só o diff de ida.
 *
 * Mecânica (tudo em workspace isolado, nunca a working tree, nunca main):
 *   commit A = estado base → aplica o diff certificado → commit B →
 *   reverse_patch = `git diff B A` → PROVA round-trip: aplica o reverse sobre B e
 *   exige byte-igualdade com A (sha256). Só então o artefato é persistido em
 *   storage/atlas/loop/reverse/<proposal>.patch + recibo no ledger.
 *
 * Assim, quando o operador decidir mesclar um branch promovido, o rollback é um
 * `git apply` do artefato — o ethos apply+reverse do applier, agora no loop.
 */
final class AtlasLoopProposalReverser
{
    public const SCHEMA_VERSION = 'atlas.ai.loop_proposal_reverser.v1';

    public function __construct(
        private readonly AtlasLoopProposalMaterializer $materializer,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function reverse(AtlasLoopProposal $proposal, string $baseDir): array
    {
        $mat = $this->materializer->materialize($proposal, $baseDir);
        if (($mat['materialized'] ?? false) !== true) {
            return $this->refuse('materialize_failed:'.(string) ($mat['reason'] ?? 'unknown'));
        }

        $workspace = (string) $mat['isolated_path'];
        $target = (string) $mat['target_path'];

        // O materializer deixou o forward APLICADO mas não commitado; sela o commit B
        // para que o reverse seja um diff entre dois estados commitados (B → A).
        // Commit e diff ESCOPADOS ao target — o atlas.patch auxiliar do materializer
        // nunca pode vazar para dentro do artefato de rollback.
        $this->git($workspace, ['add', '--', $target]);
        $this->git($workspace, ['-c', 'user.email=loop@atlas', '-c', 'user.name=atlas', 'commit', '-q', '-m', 'forward', '--no-gpg-sign']);

        $reversePatch = $this->gitOutput($workspace, ['diff', 'HEAD', 'HEAD^', '--', $target]);
        if (trim($reversePatch) === '') {
            return $this->refuse('reverse_diff_empty', $workspace);
        }

        // PROVA round-trip: aplica o reverse sobre o estado B e exige byte-igualdade
        // com o conteúdo base original. Sem prova ⇒ sem artefato.
        $baseHash = hash('sha256', (string) @file_get_contents(rtrim($baseDir, '/').'/'.$target));
        file_put_contents($workspace.'/atlas-reverse.patch', $reversePatch);
        if (! $this->git($workspace, ['apply', '--whitespace=nowarn', 'atlas-reverse.patch'])) {
            return $this->refuse('reverse_apply_failed', $workspace);
        }
        $roundtripHash = hash('sha256', (string) @file_get_contents($workspace.'/'.$target));
        if ($roundtripHash !== $baseHash) {
            return $this->refuse('roundtrip_mismatch', $workspace);
        }

        $artifactDir = storage_path('atlas/loop/reverse');
        if (! is_dir($artifactDir)) {
            @mkdir($artifactDir, 0o755, true);
        }
        $artifactPath = $artifactDir.'/'.(string) $proposal->id.'.patch';
        file_put_contents($artifactPath, $reversePatch);
        $patchHash = hash('sha256', $reversePatch);

        $this->recordReceipt($proposal, $artifactPath, $patchHash);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'reversed' => true,
            'reason' => null,
            'roundtrip_verified' => true,
            'reverse_patch_path' => $artifactPath,
            'reverse_patch_hash' => $patchHash,
            'isolated_path' => $workspace,
            'target_path' => $target,
            // Pétreo: o reverse é um ARTEFATO; nada toca a working tree nem main.
            'never_merged' => true,
            'applies_with' => 'git apply '.$artifactPath,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function refuse(string $reason, ?string $workspace = null): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'reversed' => false,
            'reason' => $reason,
            'roundtrip_verified' => false,
            'reverse_patch_path' => null,
            'reverse_patch_hash' => null,
            'isolated_path' => $workspace,
            'target_path' => null,
            'never_merged' => true,
            'applies_with' => null,
        ];
    }

    private function recordReceipt(AtlasLoopProposal $proposal, string $artifactPath, string $patchHash): void
    {
        try {
            app(\App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger::class)->record(
                \App\Services\Ai\Kernel\Evidence\LedgerEventType::DecisionIssued,
                [
                    'schema_version' => self::SCHEMA_VERSION,
                    'decision' => 'loop_reverse_artifact',
                    'proposal_hash' => (string) ($proposal->proposal_hash ?? ''),
                    'target_path' => (string) $proposal->target_path,
                    'reverse_patch_path' => $artifactPath,
                    'reverse_patch_hash' => $patchHash,
                    'roundtrip_verified' => true,
                ],
                [
                    'operator_id' => 'atlas_loop_reverser',
                    'emitter_stage' => 'atlas.ai.loop_reverse',
                    'emitter_version' => 'loop-reverser-v1',
                ],
            );
        } catch (Throwable) {
            // Recibo é best-effort; a prova round-trip não depende dele.
        }
    }

    /**
     * @param  list<string>  $argv
     */
    private function git(string $cwd, array $argv): bool
    {
        $p = new Process(array_merge(['git'], $argv), $cwd, null, null, 60.0);
        $p->run();

        return $p->isSuccessful();
    }

    /**
     * @param  list<string>  $argv
     */
    private function gitOutput(string $cwd, array $argv): string
    {
        $p = new Process(array_merge(['git'], $argv), $cwd, null, null, 60.0);
        $p->run();

        return $p->isSuccessful() ? $p->getOutput() : '';
    }
}
