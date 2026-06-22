<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopProposal;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * IN-CAMPAIGN persistence for the deterministic dead-code work-type. The work-type
 * ({@see AtlasLoopDeterministicDeadCodeWorkType}) MILLS→AUTHORS→CERTIFIES a removal with no provider; this
 * thin producer PERSISTS each certified removal as a propose-only `AtlasLoopProposal` row for a campaign —
 * the same pattern the live {@see AtlasLoopOriginationProducer} uses (a producer writes proposals; the
 * campaign's review/merge pipeline consumes them). So a campaign can MILL→CERTIFY real value with ZERO
 * provider calls.
 *
 * SAFETY: rows are written `status = STATUS_CERTIFIED` (= 'certified_for_review' ⇒ they enter OPERATOR
 * REVIEW, never auto-merge), and the quality blob carries NO `_acceptance_contract` — so even if auto-merge
 * were ON (it is OFF), the drain re-prove fails CLOSED and retires the row rather than merging it. A dead-code
 * removal legitimately fails the anti-farm `diff_earned` floor (reverting dead code never turns tests RED),
 * which is exactly why it must NOT ride the value-gate — its acceptance is the deterministic
 * `evaluateDeadCodeRemoval` cert the work-type already ran, surfaced for the operator to merge.
 */
final class AtlasLoopDeadCodeProducer
{
    private readonly AtlasLoopDeterministicWorkType $workType;

    public function __construct(?AtlasLoopDeterministicWorkType $workType = null)
    {
        $this->workType = $workType ?? new AtlasLoopDeterministicDeadCodeWorkType;
    }

    /**
     * Produce + PERSIST a certified removal for one file. Returns the new proposal id, or null (fail-closed:
     * nothing dead / author or cert refused / persistence error).
     */
    public function persistCertifiedRemoval(string $campaignId, string $repoRoot, string $relPath): ?string
    {
        $result = $this->workType->produceCertifiedRemoval($repoRoot, $relPath);
        if ($result === null) {
            return null;
        }

        try {
            $summary = implode(', ', array_map(
                static fn ($m): string => is_array($m)
                    ? trim(((string) ($m['kind'] ?? '')).' '.((string) ($m['name'] ?? '')))
                    : (string) $m,
                $result['removed'],
            ));

            $proposal = AtlasLoopProposal::create([
                'campaign_id' => $campaignId,
                'task_id' => null,
                'schema_version' => 'atlas.loop.proposal.v1',
                'status' => AtlasLoopProposal::STATUS_CERTIFIED,
                'objective' => mb_substr('Deterministic removal: '.$summary, 0, 250),
                'provider' => 'deterministic',
                'target_path' => $result['rel_path'],
                'diff_text' => $this->unifiedDiff($result['original'], $result['proposed'], $result['rel_path']),
                'proposal_hash' => substr(hash('sha256', 'deterministic|'.$result['rel_path'].'|'.$result['proposed']), 0, 40),
                'metric' => null,
                // NO _acceptance_contract => never auto-merges (drain re-prove fails closed); operator-review only.
                'quality' => [
                    '_deterministic_removal' => [
                        'schema' => 'atlas.loop.deterministic_removal.v1',
                        'provider_used' => false,
                        'removed' => $result['removed'],
                        'cert_reasons' => $result['gate_reasons'] ?? [],
                    ],
                ],
                'acceptance_hash' => '',
            ]);

            return (string) $proposal->id;
        } catch (Throwable) {
            return null; // never let a persistence blip surface as a partial/garbage row
        }
    }

    /**
     * Sweep a directory and persist a certified removal proposal for every file with provably-dead members.
     *
     * @return array{scanned:int, persisted:int, proposal_ids:list<string>, provider_used:false}
     */
    public function persistSweep(string $campaignId, string $repoRoot, string $relDir, int $limit = 50): array
    {
        $root = rtrim($repoRoot, '/');
        $absDir = $root.'/'.trim($relDir, '/');
        $scanned = 0;
        $ids = [];

        foreach ($this->phpFilesIn($absDir) as $abs) {
            $scanned++;
            $rel = ltrim(substr($abs, strlen($root)), '/');
            $id = $this->persistCertifiedRemoval($campaignId, $repoRoot, $rel);
            if ($id !== null) {
                $ids[] = $id;
                if (count($ids) >= $limit) {
                    break;
                }
            }
        }

        return ['scanned' => $scanned, 'persisted' => count($ids), 'proposal_ids' => $ids, 'provider_used' => false];
    }

    /**
     * @return iterable<string>
     */
    private function phpFilesIn(string $absDir): iterable
    {
        if (! is_dir($absDir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    private function unifiedDiff(string $original, string $proposed, string $label): string
    {
        $a = (string) tempnam(sys_get_temp_dir(), 'atlas_dc_a_');
        $b = (string) tempnam(sys_get_temp_dir(), 'atlas_dc_b_');
        file_put_contents($a, $original);
        file_put_contents($b, $proposed);
        try {
            // diff -u exits 1 when files differ (expected); we want the output either way.
            $p = new Process(['diff', '-u', '--label', 'a/'.$label, '--label', 'b/'.$label, $a, $b]);
            $p->run();
            $out = $p->getOutput();
        } catch (Throwable) {
            $out = '';
        } finally {
            @unlink($a);
            @unlink($b);
        }

        return $out;
    }
}
