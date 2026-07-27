# CODEMAP — app/Services/Ai/ConversationOps

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AiSessionManager | `App\Services\Ai\ConversationOps\AiSessionManager::ensureActive` |
| AiSessionStateService | `App\Services\Ai\ConversationOps\AiSessionStateService::updateForUserInput` |
| AiThreadDeletionService | `App\Services\Ai\ConversationOps\AiThreadDeletionService::delete` |
| AiThreadResolver | `App\Services\Ai\ConversationOps\AiThreadResolver::resolve` |
| AtlasConversationOperationsCertificationService | `App\Services\Ai\ConversationOps\AtlasConversationOperationsCertificationService::certify` |
| AtlasConversationOperationsService | `App\Services\Ai\ConversationOps\AtlasConversationOperationsService::healthReport` |

Façades: 6.
