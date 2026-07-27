# CODEMAP — app/Services/Ai/Skills

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AiSkill | `App\Services\Ai\Skills\AiSkill::version` |
| AiSkillStore | `App\Services\Ai\Skills\AiSkillStore::ensureStructure` |
| AtlasSkillEvolutionRuntimeService | `App\Services\Ai\Skills\AtlasSkillEvolutionRuntimeService::propose` |
| HermesSkillProvisionGate | `App\Services\Ai\Skills\Governance\HermesSkillProvisionGate::evaluate` |
| SkillBundleStore | `App\Services\Ai\Skills\SkillBundleStore::clear` |
| SkillDiscoveryService | `App\Services\Ai\Skills\SkillDiscoveryService::discoverAll` |
| SkillManifest | `App\Services\Ai\Skills\SkillManifest::trustLevel` |
| SkillPackPromotionGate | `App\Services\Ai\Skills\Governance\SkillPackPromotionGate::evaluate` |

Façades: 8.
