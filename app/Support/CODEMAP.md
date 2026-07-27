# CODEMAP — app/Support

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AiAttachmentPayload | `App\Support\AiAttachmentPayload::publicAttachmentsFromPayload` |
| ArrayPercentile | `App\Support\ArrayPercentile::ofSorted` |
| AtlasCloneDir | `App\Support\AtlasCloneDir::copy` |
| AtlasEnvelope | `App\Support\AtlasEnvelope::seal` |
| AtlasPhpBinary | `App\Support\AtlasPhpBinary::path` |
| AtlasSecurity | `App\Support\AtlasSecurity::processEnv` |
| BehaviorCategories | `App\Support\BehaviorCategories::allowed` |
| BehaviorLifecycle | `App\Support\BehaviorLifecycle::allowed` |
| CanonicalValue | `App\Support\CanonicalValue::canonicalize` |
| Clamp01 | `App\Support\Clamp01::of` |
| DatabaseIdsContaining | `App\Support\DatabaseIdsContaining::query` |
| FirstNonEmptyString | `App\Support\FirstNonEmptyString::from` |
| HealthMetricIntegrity | `App\Support\HealthMetricIntegrity::snapshotMetricRules` |
| InternalLeakMarkers | `App\Support\InternalLeakMarkers::substrings` |
| IsNonEmptyString | `App\Support\IsNonEmptyString::check` |
| MemoryLimitBytes | `App\Support\MemoryLimitBytes::parse` |
| Metadata | `App\Support\Metadata::forStorage` |
| NonEmptyStringOrFallback | `App\Support\NonEmptyStringOrFallback::of` |
| PeeledSource | `App\Support\PeeledSource::read` |
| ProjectExecutionHealth | `App\Support\ProjectExecutionHealth::for` |
| RoundOrNull | `App\Support\RoundOrNull::of` |
| RoutesApiSource | `App\Support\RoutesApiSource::paths` |
| StableJson | `App\Support\StableJson::encode` |
| StringOrNull | `App\Support\StringOrNull::trimmed` |
| TerminalMarkdownRenderer | `App\Support\TerminalMarkdownRenderer::render` |
| UtcIsoTimestamp | `App\Support\UtcIsoTimestamp::now` |
| YesNo | `App\Support\YesNo::format` |
| YmdDay | `App\Support\YmdDay::normalize` |

Façades: 28.
