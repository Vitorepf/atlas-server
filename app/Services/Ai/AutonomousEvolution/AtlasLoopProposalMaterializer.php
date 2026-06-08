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
        file_put_contents($dir.'/atlas.patch', $diff);
        $applied = $this->git($dir, ['apply', '--whitespace=nowarn', 'atlas.patch']);

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
