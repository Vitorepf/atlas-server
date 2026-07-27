# CODEMAP — app/Services/Ai/Rivals

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| Adjudicator | `App\Services\Ai\Rivals\Core\Adjudicator::adjudicate` |
| ArmRegistry | `App\Services\Ai\Rivals\Core\ArmRegistry::makeArm` |
| AtlasBenchSuiteAdapter | `App\Services\Ai\Rivals\Adapters\AtlasBenchSuiteAdapter::suiteId` |
| AtlasUpliftRunner | `App\Services\Ai\Rivals\Core\AtlasUpliftRunner::compare` |
| AtomicWriter | `App\Services\Ai\Rivals\Support\AtomicWriter::write` |
| BenchmarkRepoManager | `App\Services\Ai\Rivals\Benchmarks\BenchmarkRepoManager::root` |
| BenchmarkSuiteAdapter | `App\Services\Ai\Rivals\Contracts\BenchmarkSuiteAdapter::suiteId` |
| BundleManifest | `App\Services\Ai\Rivals\Core\BundleManifest::build` |
| CampaignManifest | `App\Services\Ai\Rivals\Core\CampaignManifest::campaign` |
| EnterpriseReportBuilder | `App\Services\Ai\Rivals\Core\EnterpriseReportBuilder::familyLabel` |
| EventStream | `App\Services\Ai\Rivals\Support\EventStream::append` |
| EvidencePackBuilder | `App\Services\Ai\Rivals\Core\EvidencePackBuilder::build` |
| FaseABatteryOrchestrator | `App\Services\Ai\Rivals\Core\FaseABatteryOrchestrator::dryRun` |
| FaseAClosureReceipt | `App\Services\Ai\Rivals\Core\FaseAClosureReceipt::build` |
| FrozenUnitManifest | `App\Services\Ai\Rivals\Core\FrozenUnitManifest::fromPlan` |
| LocalFakeSuiteAdapter | `App\Services\Ai\Rivals\Adapters\LocalFakeSuiteAdapter::suiteId` |
| ModelRegistry | `App\Services\Ai\Rivals\Core\ModelRegistry::all` |
| NativeExecutionBundleImporter | `App\Services\Ai\Rivals\Core\NativeExecutionBundleImporter::import` |
| NativeExecutionManifest | `App\Services\Ai\Rivals\Core\NativeExecutionManifest::fromPlan` |
| Preregistration | `App\Services\Ai\Rivals\Core\Preregistration::fromPlan` |
| ReplayVerifier | `App\Services\Ai\Rivals\Core\ReplayVerifier::verify` |
| ReportBuilder | `App\Services\Ai\Rivals\Core\ReportBuilder::build` |
| ResultLedger | `App\Services\Ai\Rivals\Core\ResultLedger::append` |
| RivalsClaimAuthority | `App\Services\Ai\Rivals\Core\RivalsClaimAuthority::issue` |
| RivalsCurriculumLadder | `App\Services\Ai\Rivals\Core\RivalsCurriculumLadder::claimBlockers` |
| RivalsExcellenceMeasure | `App\Services\Ai\Rivals\Core\RivalsExcellenceMeasure::report` |
| RunAutopsy | `App\Services\Ai\Rivals\Core\RunAutopsy::build` |
| RunLock | `App\Services\Ai\Rivals\Support\RunLock::exclusive` |
| RunPaths | `App\Services\Ai\Rivals\Support\RunPaths::root` |
| RunPlan | `App\Services\Ai\Rivals\Core\RunPlan::make` |
| RunReceipt | `App\Services\Ai\Rivals\Core\RunReceipt::fromArray` |
| RunStateMachine | `App\Services\Ai\Rivals\Core\RunStateMachine::path` |
| StatisticalPolicy | `App\Services\Ai\Rivals\Core\StatisticalPolicy::evaluate` |
| SuiteRegistry | `App\Services\Ai\Rivals\Core\SuiteRegistry::externalSuiteIds` |
| WorldTrialReadiness | `App\Services\Ai\Rivals\Core\WorldTrialReadiness::evaluate` |

Façades: 35.
