# CODEMAP — app/Services/Semantic

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| ActivationEngine | `App\Services\Semantic\ActivationEngine::createForContext` |
| AtlasVaultCommandInput | `App\Services\Semantic\AtlasVaultCommandInput::syncLimit` |
| AtlasVaultManagedNoteService | `App\Services\Semantic\AtlasVaultManagedNoteService::preview` |
| AtlasVaultSyncService | `App\Services\Semantic\AtlasVaultSyncService::importPath` |
| CaptureSemanticClarifier | `App\Services\Semantic\CaptureSemanticClarifier::handleReady` |
| CognitiveGameService | `App\Services\Semantic\CognitiveGameService::today` |
| CurationProposalService | `App\Services\Semantic\CurationProposalService::scanRecentCaptures` |
| EmbeddingProvenance | `App\Services\Semantic\EmbeddingProvenance::contentHash` |
| EmbeddingService | `App\Services\Semantic\EmbeddingService::embedText` |
| FrontmatterParser | `App\Services\Semantic\FrontmatterParser::parse` |
| SemanticNoteIndexer | `App\Services\Semantic\SemanticNoteIndexer::indexAll` |
| SemanticSearchService | `App\Services\Semantic\SemanticSearchService::search` |
| VaultFileStore | `App\Services\Semantic\VaultFileStore::ensureVaultStructure` |
| VaultGovernanceService | `App\Services\Semantic\VaultGovernanceService::snapshot` |

Façades: 14.
