# AP-781 · First Live Branch Proof

- **Status:** building
- **Stack:** Atlas Software Company Stewardship Stack.
- **Owner service:** `app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipFirstLiveBranchProofService.php`
- **Test:** `tests/Unit/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipFirstLiveBranchProofServiceTest.php`
- **Composes:** AP-769 branch merge governor · AP-780 branch review packet.

## Purpose

AP-781 proves the minimum real branch loop that the operator can inspect in
GitKraken before any merge:

```text
repo main
  -> real isolated git worktree
  -> real local branch atlas/area-focus/<area>/live-proof-*
  -> real docs-only commit
  -> AP-769 branch governance
  -> AP-780 operator review packet
  -> no merge, no push, no deploy
```

This is intentionally narrower than AP-768 first full cycle. AP-768 stitches the
software-company runtime. AP-781 proves the branch visibility and review surface
needed by the enterprise branch/version/merge stack before 24/7 loops can be
trusted.

## Non-Negotiable Guarantees

- Creates a real local branch visible to GitKraken.
- Creates a real isolated worktree.
- Creates a real commit on the branch.
- Keeps the base branch commit unchanged.
- Emits AP-769 governance metadata.
- Emits one AP-780 review packet.
- Never merges, pushes, deploys, calls a provider, rebases, squashes or
  force-pushes.

## Receipt

Schema: `atlas.software_company_stewardship.first_live_branch_proof.v1`

Required fields:

- `proof_id`
- `repo.base_ref`
- `repo.base_commit_before`
- `repo.base_commit_after`
- `repo.main_untouched`
- `branch.branch_ref`
- `branch.branch_commit`
- `branch.worktree_path`
- `branch.proof_file`
- `governance_report`
- `branch_review_packet`
- `claim_policy`

`repo.main_untouched` must be true for the proof to be accepted.

## Operator Use

After AP-781 returns `status=proven`, the operator opens GitKraken and reviews
`branch.branch_ref` against `repo.base_ref`. Acceptance, rejection, request for
changes or policy-gated fast-forward merge must happen through AP-769/AP-772.

AP-781 itself is proof and review preparation only.
