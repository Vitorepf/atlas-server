# AAEOS Quarantine — FROZEN

**Do not reanimate.** ~306 PHP files / ~132k LOC of doc-materialized services.

| Rule | |
|---|---|
| New `use` from app code | **Forbidden** |
| Move class to live root without owner | **Forbidden** |
| Extract useful contract | → markdown/catalog only |
| Live exception (re-homed) | `AtlasSourceConnectorsAndCaptureService` → `App\Services\Ai\Aaeos\Support` |

Catalog: `docs/evidence/2026-07-22-atlas-server-god-debulk/AAEOS-QUARANTINE-CATALOG.md`

Control plane (live): `App\Services\Ai\Aaeos\Control\*`
