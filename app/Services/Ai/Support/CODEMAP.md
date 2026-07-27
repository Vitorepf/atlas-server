# CODEMAP — app/Services/Ai/Support

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AiPathMatcher | `App\Services\Ai\Support\AiPathMatcher::isProviderSafeRelativePath` |
| AiPromptAttachmentSupport | `App\Services\Ai\Support\AiPromptAttachmentSupport::attachmentInstructions` |
| AiPromptInstructionSupport | `App\Services\Ai\Support\AiPromptInstructionSupport::persistentContextPromptSection` |
| AiPromptTextSupport | `App\Services\Ai\Support\AiPromptTextSupport::awisPromptList` |
| AiStringListNormalizer | `App\Services\Ai\Support\AiStringListNormalizer::uniqueStrings` |
| AiTextMatcher | `App\Services\Ai\Support\AiTextMatcher::containsAnyNeedle` |
| AiValueNormalizer | `App\Services\Ai\Support\AiValueNormalizer::trimmedStringOrNull` |
| AppendOnlyJsonlStore | `App\Services\Ai\Support\AppendOnlyJsonlStore::read` |
| CliInvocationModel | `App\Services\Ai\Support\CliInvocationModel::resolve` |
| ControlPlaneStatusSection | `App\Services\Ai\Support\ControlPlaneStatusSection::project` |
| DatabaseTableAvailability | `App\Services\Ai\Support\DatabaseTableAvailability::has` |
| JsonFileStore | `App\Services\Ai\Support\JsonFileStore::readArray` |
| SchemaDriftAuditor | `App\Services\Ai\Support\SchemaDriftAuditor::audit` |
| SchemaVersionedJsonBlockParser | `App\Services\Ai\Support\SchemaVersionedJsonBlockParser::parse` |

Façades: 14.
