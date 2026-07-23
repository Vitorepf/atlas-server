# AAEOS = Org Control Plane ONLY

**Allowed here:** `Control/`, `Spine/`

**Forbidden under this tree:**
- Maturity / docs-as-law / ImplementationTruth / TestExecution
- `Cores/` scorers (live under `AgenticEngineeringOs/Scoring`)
- `Generated/` (dissolved into domain folders)
- Quarantine cemetery (only under `archive/.../Quarantine`)

**CLIs that belong to AAEOS:**
- `atlas:aaeos:cycle`
- `atlas:aaeos:scorecard`
- `atlas:aaeos:certify`

Maturity / department / universal-gates tooling lives under
`App\Services\Ai\AgenticEngineeringOs\` (often CLI `atlas:aeos:*`).

Cemetery: `archive/app/Services/Ai/Aaeos/Quarantine` — **never import, never reanimate**.

See `docs/engineering-knowledge-base/atlas-aaeos-vocabulary.md`.
