# CODEMAP — app/Services/Digital

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| DigitalActivityQuality | `App\Services\Digital\DigitalActivityQuality::enrichPayload` |
| DigitalActivitySnapshotBuilder | `App\Services\Digital\DigitalActivitySnapshotBuilder::rebuild` |
| RizeApiClient | `App\Services\Digital\RizeApiClient::query` |
| RizeApiIngestor | `App\Services\Digital\RizeApiIngestor::sync` |
| RizeWebhookIngestor | `App\Services\Digital\RizeWebhookIngestor::ingest` |

Façades: 5.
