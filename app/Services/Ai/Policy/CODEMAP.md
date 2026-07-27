# CODEMAP — Policy

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AiRuntimeBudgetService | `App\Services\Ai\Policy\AiRuntimeBudgetService::payload` |
| ApprovalRequestService | `App\Services\Ai\Policy\ApprovalRequestService::request` |
| AtlasAiPolicyService | `App\Services\Ai\Policy\AtlasAiPolicyService::effectiveProfile` |
| AtlasAiRuntimeSettings | `App\Services\Ai\Policy\AtlasAiRuntimeSettings::effective` |
| AtlasDomainProfilePolicyService | `App\Services\Ai\Policy\AtlasDomainProfilePolicyService::updateDomain` |
| AtlasDomainProfileRegistry | `App\Services\Ai\Policy\AtlasDomainProfileRegistry::catalog` |
| AtlasEffectivePolicyComposer | `App\Services\Ai\Policy\AtlasEffectivePolicyComposer::compose` |
| BudgetEnvelopeService | `App\Services\Ai\Policy\BudgetEnvelopeService::open` |
| PermissionGateService | `App\Services\Ai\Policy\PermissionGateService::evaluate` |
| PolicyCanon | `App\Services\Ai\Policy\PolicyCanon::riskExceeds` |
| PolicyControlPlaneService | `App\Services\Ai\Policy\PolicyControlPlaneService::snapshot` |
| PolicyProfileRegistryService | `App\Services\Ai\Policy\PolicyProfileRegistryService::defaults` |
| PolicyReadinessService | `App\Services\Ai\Policy\PolicyReadinessService::report` |
| RiskAssessmentService | `App\Services\Ai\Policy\RiskAssessmentService::assess` |
| SafetyDecisionService | `App\Services\Ai\Policy\SafetyDecisionService::decide` |

Façades: 15.
