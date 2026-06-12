<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopProposal;
use Symfony\Component\Process\Process;

/**
 * Materializes a certified loop proposal's diff into an ISOLATED workspace so a
 * loop win becomes inspectable + re-provable as a real applied artifact — closing
 * the gap that proposals carried a `diff_text` no command ever applied.
 *
 * The pétreo invariant is preserved by construction: it applies the diff to a
 * fresh throwaway workspace, NEVER to the working tree and NEVER to main. The
 * merge-to-source step is intentionally NOT implemented here — it is the operator's
 * sovereignty decision (the loop's never-merge guarantee). This service stops
 * exactly at that line: it proves the diff applies cleanly in isolation and hands
 * back the materialized path; promoting it to source stays a separate, operator-
 * gated act.
 */
final class AtlasLoopProposalMaterializer
{
    public const SCHEMA_VERSION = 'atlas.ai.loop_proposal_materializer.v1';

    /**
     * @return array{schema_version:string,materialized:bool,reason:?string,isolated_path:?string,target_path:?string,applied:bool,never_merged:bool,merge_to_source:bool}
     */
    public function materialize(AtlasLoopProposal $proposal, string $baseDir): array
    {
        $diff = (string) $proposal->diff_text;
        $target = trim((string) $proposal->target_path);

        if ($proposal->status !== AtlasLoopProposal::STATUS_CERTIFIED) {
            return $this->refuse('proposal_not_certified_for_review');
        }
        if (trim($diff) === '' || $target === '') {
            return $this->refuse('proposal_has_no_diff_or_target');
        }

        $baseFile = rtrim($baseDir, '/').'/'.$target;
        if (! is_file($baseFile)) {
            return $this->refuse('base_target_not_found');
        }

        $dir = sys_get_temp_dir().'/atlas-loop-materialized-'.bin2hex(random_bytes(5));
        if (! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            return $this->refuse('workspace_create_failed');
        }

        // Reconstruct the target path inside the isolated workspace so the diff's
        // a/<path> b/<path> headers match, then git-apply the certified diff.
        $workFile = $dir.'/'.$target;
        @mkdir(dirname($workFile), 0o755, true);
        file_put_contents($workFile, (string) @file_get_contents($baseFile));

        $this->git($dir, ['init', '-q']);
        $this->git($dir, ['add', '-A']);
        $this->git($dir, ['-c', 'user.email=loop@atlas', '-c', 'user.name=atlas', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);
        // L3-1 (keystone do flywheel): o provider gera o diff num workspace isolado onde o
        // arquivo mora em `src/<basename>`, mas o materializer (e o repo real) o colocam em
        // `<target_path>`. Sem reescrever os headers a/<x> b/<x>, `git apply` falha em 100%
        // das propostas (git_apply_failed) — exatamente o que travava as 82 candidatas em 0
        // merges. Reescrevemos os paths do diff de UM arquivo para o target_path conhecido.
        $diff = $this->rewriteDiffToTarget($diff, $target);
        file_put_contents($dir.'/atlas.patch', $diff);
        $applied = $this->git($dir, ['apply', '--whitespace=nowarn', 'atlas.patch']);
        // Remove the patch artifact: leaving it makes the frozen judge's scope census see
        // an extra untracked file (atlas.patch) and reject the re-proof as out_of_scope —
        // which would make EVERY promotion re-proof fail. The workspace must contain only
        // the applied change. (O-3: discovered when the promotion gate re-proof was wired.)
        @unlink($dir.'/atlas.patch');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'materialized' => $applied,
            'reason' => $applied ? null : 'git_apply_failed',
            'isolated_path' => $dir,
            'target_path' => $target,
            'applied' => $applied,
            // Pétreo: an isolated workspace only; the source tree is never touched.
            'never_merged' => true,
            'merge_to_source' => false,
        ];
    }

    /**
     * L3-1 · Materialização em workspace COMPLETO (runnable).
     *
     * A materialização mínima (um arquivo) é incapaz de rodar uma acceptance real: o
     * frozen judge auto-congela o arquivo referenciado pelos commands, então qualquer
     * mudança de implementação dá `frozen_path_tampered` e NADA mergeia. A re-prova do
     * merge-livre precisa de um workspace onde o teste-spec CONGELADO exercita a impl
     * MUDADA. Este método clona o repo (hardlinks, barato), liga vendor/.env por symlink
     * (não-rastreados, fora do clone) e aplica o diff normalizado.
     *
     * @return array{schema_version:string,materialized:bool,reason:?string,isolated_path:?string,target_path:?string,applied:bool,never_merged:bool,merge_to_source:bool}
     */
    public function materializeFull(AtlasLoopProposal $proposal, string $baseDir): array
    {
        $diff = (string) $proposal->diff_text;
        $target = trim((string) $proposal->target_path);
        $baseDir = rtrim($baseDir, '/');

        if ($proposal->status !== AtlasLoopProposal::STATUS_CERTIFIED) {
            return $this->refuse('proposal_not_certified_for_review');
        }
        if (trim($diff) === '' || $target === '') {
            return $this->refuse('proposal_has_no_diff_or_target');
        }
        if (! is_dir($baseDir.'/.git')) {
            return $this->refuse('base_not_a_git_tree');
        }

        $dir = sys_get_temp_dir().'/atlas-loop-full-'.bin2hex(random_bytes(5));
        if (! $this->git($baseDir, ['clone', '--local', '--quiet', '.', $dir])) {
            return $this->refuse('clone_failed');
        }

        // Liga os não-rastreados que o teste precisa (vendor/.env), sem copiá-los.
        foreach (['vendor', '.env'] as $dep) {
            $src = $baseDir.'/'.$dep;
            if (file_exists($src) && ! file_exists($dir.'/'.$dep)) {
                @symlink($src, $dir.'/'.$dep);
            }
        }

        $normalized = $this->rewriteDiffToTarget($diff, $target);
        file_put_contents($dir.'/atlas.patch', $normalized);
        $applied = $this->git($dir, ['apply', '--whitespace=nowarn', 'atlas.patch']);
        @unlink($dir.'/atlas.patch');

        if (! $applied) {
            (new Process(['rm', '-rf', $dir]))->run();

            return $this->refuse('git_apply_failed');
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'materialized' => true,
            'reason' => null,
            'isolated_path' => $dir,
            'target_path' => $target,
            'applied' => true,
            'never_merged' => true,
            'merge_to_source' => false,
        ];
    }

    /**
     * Reescreve os headers de path de um diff unificado de UM arquivo para apontar ao
     * target_path conhecido. O provider emite paths workspace-relativos (`src/X.php`);
     * o materializer precisa deles em `<target_path>`. Conservador por construção:
     *
     *  - só reescreve quando o diff toca UM único arquivo (um `diff --git`); diffs
     *    multi-arquivo são deixados intactos (não corromper);
     *  - preserva `/dev/null` (lado de criação/remoção) sem reescrever;
     *  - no-op idempotente quando o path do diff já é o target_path.
     */
    public function rewriteDiffToTarget(string $diff, string $target): string
    {
        $target = ltrim(trim($target), '/');
        if ($target === '' || trim($diff) === '') {
            return $diff;
        }

        // Conta os arquivos tocados: mais de um → não reescreve (segurança).
        if (preg_match_all('/^diff --git /m', $diff) > 1) {
            return $diff;
        }

        $lines = preg_split('/\R/', $diff);
        if ($lines === false) {
            return $diff;
        }

        $out = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, 'diff --git ')) {
                $out[] = 'diff --git a/'.$target.' b/'.$target;

                continue;
            }
            if (str_starts_with($line, '--- ')) {
                $out[] = str_contains($line, '/dev/null') ? $line : '--- a/'.$target;

                continue;
            }
            if (str_starts_with($line, '+++ ')) {
                $out[] = str_contains($line, '/dev/null') ? $line : '+++ b/'.$target;

                continue;
            }
            if (str_starts_with($line, 'rename from ')) {
                $out[] = 'rename from '.$target;

                continue;
            }
            if (str_starts_with($line, 'rename to ')) {
                $out[] = 'rename to '.$target;

                continue;
            }
            $out[] = $line;
        }

        $rewritten = implode("\n", $out);

        // Garante trailing newline (git apply exige).
        return rtrim($rewritten, "\n")."\n";
    }

    /**
     * @return array{schema_version:string,materialized:bool,reason:?string,isolated_path:?string,target_path:?string,applied:bool,never_merged:bool,merge_to_source:bool}
     */
    private function refuse(string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'materialized' => false,
            'reason' => $reason,
            'isolated_path' => null,
            'target_path' => null,
            'applied' => false,
            'never_merged' => true,
            'merge_to_source' => false,
        ];
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
}
