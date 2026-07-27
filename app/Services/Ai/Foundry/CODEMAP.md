# CODEMAP — Foundry

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| EarnedAutonomyGateService | `App\Services\Ai\Foundry\Rsi\EarnedAutonomy\EarnedAutonomyGateService::decide` |
| FoundryEvidenceHarvesterService | `App\Services\Ai\Foundry\FoundryEvidenceHarvesterService::harvest` |
| FoundryEvidenceVerifierService | `App\Services\Ai\Foundry\FoundryEvidenceVerifierService::setRepoRootForTesting` |
| FoundrySemanticGapFinderService | `App\Services\Ai\Foundry\FoundrySemanticGapFinderService::project` |
| ImmutableInvariantRegistryService | `App\Services\Ai\Foundry\Rsi\ImmutableInvariantRegistryService::setBaseDirForTesting` |
| RsiInvariantGuardService | `App\Services\Ai\Foundry\Rsi\RsiInvariantGuardService::screen` |
| RsiSelfImprovementProposalGate | `App\Services\Ai\Foundry\Rsi\RsiSelfImprovementProposalGate::admit` |

Façades: 7.
