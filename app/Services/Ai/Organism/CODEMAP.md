# CODEMAP — app/Services/Ai/Organism

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AbstractDomainActuator | `App\Services\Ai\Organism\AbstractDomainActuator::actuate` |
| ActuationReceiptStore | `App\Services\Ai\Organism\ActuationReceiptStore::record` |
| AtlasOrganismActuationGate | `App\Services\Ai\Organism\AtlasOrganismActuationGate::assertCannotAct` |
| AtlasOrganismMissionService | `App\Services\Ai\Organism\AtlasOrganismMissionService::commission` |
| AtlasOrganismRegistry | `App\Services\Ai\Organism\AtlasOrganismRegistry::register` |
| AtlasOrganismService | `App\Services\Ai\Organism\AtlasOrganismService::propose` |
| DefaultTradingHonestyJudge | `App\Services\Ai\Organism\Finance\DefaultTradingHonestyJudge::evaluate` |
| DomainProposal | `App\Services\Ai\Organism\DomainProposal::fromArray` |
| EvidenceLedgerActuationReceiptStore | `App\Services\Ai\Organism\EvidenceLedgerActuationReceiptStore::record` |
| FinanceDomainProposer | `App\Services\Ai\Organism\Finance\FinanceDomainProposer::propose` |
| FinanceDomainValidator | `App\Services\Ai\Organism\Finance\FinanceDomainValidator::validate` |
| MarketingDomainProposer | `App\Services\Ai\Organism\Marketing\MarketingDomainProposer::propose` |
| MarketingDomainValidator | `App\Services\Ai\Organism\Marketing\MarketingDomainValidator::validate` |
| OpenBrainContextPackAnchor | `App\Services\Ai\Organism\OpenBrainContextPackAnchor::anchor` |
| OrganismBrainAnchor | `App\Services\Ai\Organism\OrganismBrainAnchor::anchor` |
| OrganismProposalRecorder | `App\Services\Ai\Organism\OrganismProposalRecorder::record` |
| RealityGraphProposalRecorder | `App\Services\Ai\Organism\RealityGraphProposalRecorder::record` |

Façades: 17.
