# Loop — Proxy/Goodhart Pattern Catalog

Canonical, frozen catalog of every NAMED proxy/Goodhart pattern the Loop refuses. The catalog is the
single source of pattern_id strings used by `AtlasLoopAntiGoodhartUnifiedRefusal`. New patterns are
APPENDED; existing entries are never deleted or renamed.

## behaviour-preserving-refactor

- **Family:** refactor
- **Detector:** `App\Services\Ai\AutonomousEvolution\AtlasLoopRealWorkScorecardService`
- **Fact keys:** `edit_kind`, `mutation_kills`, `characterization_diff`

An extract-method/rename/move-class edit that the frozen judge ratifies but mutation testing kills 0
candidates and characterization tests are byte-identical: the edit bites nothing.

## whitespace-only

- **Family:** cosmetic
- **Detector:** `App\Services\Ai\AutonomousEvolution\AtlasLoopRealWorkScorecardService`
- **Fact keys:** `edit_kind`, `non_whitespace_delta_chars`

The non-whitespace diff is zero characters. A polish that costs cycles to certify and refuses to fail
any frozen check — pure proxy success.

## comment-only

- **Family:** cosmetic
- **Detector:** `App\Services\Ai\AutonomousEvolution\AtlasLoopRealWorkScorecardService`
- **Fact keys:** `edit_kind`, `non_comment_delta_chars`

Edit removes the comment-token bytes and yields zero non-comment delta — a doc adjustment masquerading
as code work.

## cyclomatic-proxy

- **Family:** metric_proxy
- **Detector:** `App\Services\Ai\AutonomousEvolution\AtlasLoopRealWorkScorecardService`
- **Fact keys:** `metric_kind`, `metric_delta`, `mutation_kills`

The PRIMARY load-bearing pattern called out in `loop-material-fuel-gap`. The edit shrinks cyclomatic
complexity but the frozen mutation operators kill 0 candidates — the metric moved, the program did
not. Refused at the anti-farm floor.

## characterization-test-farm

- **Family:** farm
- **Detector:** `App\Services\Ai\AutonomousEvolution\AtlasLoopAntiFarmFloor`
- **Fact keys:** `wired_proof`, `production_caller`, `characterization_diff`

A test was added that exercises the target but the target's PRODUCTION caller is `self` or
`tests/...` — the wired_proof is real, the production_path_proven is not. The floor's
`wired_into_non_production_path` reason names this exactly.

## self-edit-in-own-judge

- **Family:** self_serve
- **Detector:** `App\Services\Ai\AutonomousEvolution\AtlasLoopWorkspaceMaterializerSupport2`
- **Fact keys:** `target_path`, `forbidden_core`

An attempted self-edit that would weaken the judge that judges it — first observed and pinned by
`loop-os-slice0-provenance-finding`. The sandbox floor's `assertOutsideLiveSource()` rejects the
write before it lands.

## paraphrase-atom

- **Family:** paraphrase
- **Detector:** `App\Services\Ai\AutonomousEvolution\AtlasLoopAtomParaphraseAudit`
- **Fact keys:** `restated_objective`, `acceptance_unchanged`

A previously rejected objective is restated (rewording, synonym substitution) without the underlying
acceptance changing — the paraphrase audit detects the canonical-form match and refuses to mint a new
task for the restated atom.
