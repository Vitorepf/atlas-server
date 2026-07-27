# CODEMAP — app/Services/Ai/Compression

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AtlasCcrStore | `App\Services\Ai\Compression\AtlasCcrStore::store` |
| CompressionAiProvider | `App\Services\Ai\Compression\CompressionAiProvider::key` |
| CompressionPipeline | `App\Services\Ai\Compression\CompressionPipeline::enabled` |
| ContentRouter | `App\Services\Ai\Compression\ContentRouter::register` |
| DiffCompressor | `App\Services\Ai\Compression\Compressors\DiffCompressor::contentType` |
| LogCompressor | `App\Services\Ai\Compression\Compressors\LogCompressor::contentType` |
| SearchCompressor | `App\Services\Ai\Compression\Compressors\SearchCompressor::contentType` |
| SmartCrusherJsonCompressor | `App\Services\Ai\Compression\Compressors\SmartCrusherJsonCompressor::contentType` |
| TextCompressor | `App\Services\Ai\Compression\Compressors\TextCompressor::contentType` |
| VolatileTokenRelocator | `App\Services\Ai\Compression\Support\VolatileTokenRelocator::relocate` |

Façades: 10.
