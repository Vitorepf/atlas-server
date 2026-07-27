# CODEMAP — Compaction

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| CompactionLossPolicy | `App\Services\Ai\Compaction\CompactionLossPolicy::classify` |
| CompactionMustKeepExtractor | `App\Services\Ai\Compaction\CompactionMustKeepExtractor::extract` |
| VerifiedL2HierarchicalSummaryService | `App\Services\Ai\Compaction\VerifiedL2HierarchicalSummaryService::generateForCompaction` |

Façades: 3.
