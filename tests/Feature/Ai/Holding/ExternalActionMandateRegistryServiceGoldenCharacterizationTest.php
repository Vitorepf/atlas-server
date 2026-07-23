<?php

namespace Tests\Feature\Ai\Holding;

use App\Services\Ai\Holding\ExternalActionMandateRegistryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\Concerns\CreatesAtlasToolRuntimeTables;
use Tests\TestCase;

/**
 * GOD-DEBULK golden characterization net for ExternalActionMandateRegistryService.
 *
 * Freezes CURRENT behavior before the method-family split:
 *  - the full public API surface (names + signatures + constants) via reflection fingerprint;
 *  - a deterministic value-snapshot (sha256 of canonical JSON, time frozen via
 *    Carbon::setTestNow, uuids normalized) for every public method callable
 *    against sqlite :memory: + the mandate fixture suite;
 *  - the register -> preflight -> request-approval -> decide -> status workflow.
 *
 * Regenerate snapshots (after an INTENTIONAL behavior change only):
 *   ATLAS_GOLDEN_RECORD=/tmp/golden.json php vendor/bin/phpunit \
 *     tests/Feature/Ai/Holding/ExternalActionMandateRegistryServiceGoldenCharacterizationTest.php
 * then paste the printed maps over the consts below.
 */
class ExternalActionMandateRegistryServiceGoldenCharacterizationTest extends TestCase
{
    use CreatesAtlasToolRuntimeTables;

    private const FROZEN_NOW = '2026-07-22 12:00:00';

    private const PUBLIC_METHOD_COUNT = 121;

    private const PUBLIC_API_FINGERPRINT = 'b2174e37845b6336af7b3d7a9ad7bcfac48f5227da0d7052759b6a22ded1d7ee';

    /**
     * method-key => sha256 of canonical JSON of the full return value.
     * Keys execute IN ORDER against one service instance + one sqlite :memory: DB,
     * so later entries legitimately observe earlier entries' writes.
     *
     * @var array<string,string>
     */
    private const CALL_SNAPSHOTS = [
        'register' => 'f667710e016f9c271bb7538f26c87b20dafc6ac031a28478f30ec8331a501fb1',
        'preflight.missing' => '1021ca7b11d2400ce1bacdf661dc30d342a8bff3719d0118bf88f853ab29e385',
        'requestApproval.missing' => '04a5cf723e827bdce4b36d243c295447809adbd67122fde41771602bb31ff7eb',
        'decideApproval.invalid' => '2f448ecddfaabb4064c8ceff0e98172d925e0792ddeeb5b8e15a4e11cc88608b',
        'approvalStatus.missing' => 'babe229f2326a3d12f7f89292e58c326538b17466ca27c2c862e6ed311b6c21a',
        'controlTower' => '5cf68552c224882c640e1f648ab0b0fa2d718fde354a05a70edecd75ec2a60b2',
        'activationCockpit' => 'a00f3097aea71737099bf411b24c17685dd1b15bf2e6166a1d42f51fcc3499f4',
        'premiumActivationStatus' => 'c9445f5b21adef1c8848a21f7f31270ad4838c8caaa0a2c17a7bd21f2d839b22',
        'providerWorkbenchStatus' => '086ae9e8c4d3d2238d43f8b41396dbc1cb873c3a51dbb7bb0894b8c7a2c44111',
        'agentRepositoryAdoptionStatus' => 'f358bde6799f06e452179cdb9981e3d4262fca79a12e5a0cb23a2e219c620547',
        'agentRepositoryOperatingCatalogStatus' => '9e5c850ee878ce9cdfc4ff8123dbced7abc6373ed767be1502331164becc78de',
        'domainDataFabricStatus' => '48da9b82efbf1092deb7dc4772a5c4ad68e0e8b49c8e18097734780184aaeff2',
        'domainDataConnectorOperatingStatus' => 'a6f893ce8f1c6c917e7c222d9028b58feab774533afc90f3ea6f69eb0b0f0bc1',
        'flowLiveReadConnectorProbeStatus' => '646ea120d2216e6f38883800017ddc8ce3562717d5579d66f00736e06890f0c5',
        'externalResearchAdoptionStatus' => '8bd5c930c353b21cfe48843d5fe63b2b1f68ac7b73d45892612008a7c634458a',
        'flowBenchmarkReplayStatus' => '7deccba023dbd3c6fed867802f6edf45b1abf9ad19181ade96689e08ae94bb88',
        'connectorCertificationPreflightStatus' => '66e9d8574f649028ae4f0b8db9b303f1812ed382bcc5cb6f4c1e66f334d17d06',
        'domainAgentToolchainCertificationStatus' => '2a1bf3040d037da26e53f9a24262a334e7a8b25ad5b443b9644e126e2ebee75e',
        'industrySolutionEcosystemStatus' => '8aa67aaf43f082fe6c8351c0f9acb06faba67634df64f4abb443088d1b03e13d',
        'businessOperatingBackboneStatus' => '70974012d53698eb5e59d67f5b5387b99256b97d31eac3841c88b6e02e56ff1f',
        'productionConnectorPreflightStatus' => '466f0b2fb7cbd9a68a36be1c086ec08c0e8f1b67d1d7d61b2e808578a13284b1',
        'flowQualityResearchStatus' => 'c882df0600ebd4915216f01625942a261beafd503caf3e287f84d29216f434b0',
        'companyCommandCenterStatus' => '5b6c9445785ae51e83e5d22f4b201e1cf5f6a20d46e0f48727fd697b97afebbc',
        'flowOperatingPackageStatus' => '202500d34da96b2415158f17cac7045ce0d7bdd9722594f54090a3752a14bf80',
        'verticalSolutionSuiteStatus' => 'f63c8184725dc2cf76440cb7972a628096383fa9d87bcb7311fe2c620587a299',
        'domainBusinessExecutionMeshStatus' => 'cb2f15d42b7e59c8bc9976c6264a17851359310ce7054efdb6eef280571439e7',
        'operationalDressRehearsalStatus' => '4a3718bd71d72f1982f91c7c70ba87220092d449f4d06f79e8e02df84ae99cfd',
        'realExternalExecutionReadinessDossier' => 'a900ff7c34cf1641aa8bd85d1d21341b896b206eb89c71f45f6db01f778a2c2c',
        'realExternalExecutionHandoffPack' => '0738a8c3ba634f64047a3bd89c9730a0f8c1bf693b16e4c4d09c6ff8e47acd61',
        'supervisedExternalExecutionPacketStatus' => '80f24bc16256cfd50b67d73c399b9b3fe42ecdb935165c0a32bf8df107b610f3',
        'supervisedExternalExecutionPacketStatusFromHandoff' => '80f24bc16256cfd50b67d73c399b9b3fe42ecdb935165c0a32bf8df107b610f3',
        'externalWorkerPreflightStatus' => '4c11ebf7feccb6fa05a885ca13afcaf348b32a6b4fe773d33a7182c6870fd53d',
        'externalWorkerPreflightStatusFromPackets' => '4c11ebf7feccb6fa05a885ca13afcaf348b32a6b4fe773d33a7182c6870fd53d',
        'externalWorkerDispatchPlanStatus' => '26a65dc7739cd6c911013e7dc0b836dbb3a1a99fa1549da36de5415fc588418e',
        'externalWorkerDispatchPlanStatusFromPreflight' => '26a65dc7739cd6c911013e7dc0b836dbb3a1a99fa1549da36de5415fc588418e',
        'externalLaunchControlStatus' => '0e662d781d8957ec9e8bb540414f31e461080b2a2ac7235f7a6234e540faa922',
        'externalLaunchControlStatusFromDispatchPlans' => '0e662d781d8957ec9e8bb540414f31e461080b2a2ac7235f7a6234e540faa922',
        'externalReceiptBindingStatus' => 'f751e016f125e45cc15576769a4097f25cb0d22cff30b1a89854c190e65b49e2',
        'externalReceiptBindingStatusFromLaunchControl' => 'f751e016f125e45cc15576769a4097f25cb0d22cff30b1a89854c190e65b49e2',
        'externalSupervisedCutoverDossierStatus' => '756b15fddf5f7d386022f9b9e2da4a3871b884483ea19a81a2501399035754b8',
        'externalSupervisedCutoverDossierStatusFromReceiptBinding' => '756b15fddf5f7d386022f9b9e2da4a3871b884483ea19a81a2501399035754b8',
        'externalSupervisedCutoverWorkOrderStatus' => 'ce99a49ae1711f6153332e581bae8f24eba68e2b066e69071f78057850483b00',
        'externalSupervisedCutoverWorkOrderStatusFromDossiers' => 'ce99a49ae1711f6153332e581bae8f24eba68e2b066e69071f78057850483b00',
        'registerExternalSupervisedCutoverWorkOrders' => '1aefa1d8f50bdee13a721761b611cc41e0954ee3c2c34b6099b1c1e16e0ef5ef',
        'externalSupervisedCutoverWorkOrderPersistedStatus' => '60e5621cb240abf093cfaee77cf1706715a8aded77f430028e8364e903ad321d',
        'bindExternalSupervisedCutoverWorkItemReceipt.rejected' => '5358934f0de7394439cfc01ca969ca225bb33a34db13b830ed2e17dbbe68df87',
        'externalSupervisedCutoverPromotionStatus' => 'ebc5e4255a167a78ff6b984b64bf83abdc52e4e45e37e6ea450792e7779b3647',
        'bindExternalSupervisedCutoverFinalAuthorityReceipt.rejected' => 'a56a88ab6a27eafd04c4f07fd714828fb544b60773073848b5a953c348056939',
        'registerExternalSupervisedCutoverRuntimeInvocation.rejected' => 'cd54d92224172e5108cc646fd4112f2781cfc6666446092b931e62276f270cef',
        'externalSupervisedCutoverRuntimeInvocationStatus' => 'fd22d04cb5bb07bc47bd527a6ccd2d70798a74f347f262d40965ff1ce202a0e9',
        'executeExternalSupervisedCutoverRuntimeRehearsal.rejected' => 'e88d2ce53bc1f70f6614470c2ac9e878bf22ac811fc4831abe6bf721ccb7244c',
        'externalSupervisedCutoverRehearsalPromotionStatus' => '5446b836d5b8f3762c56165b8f07c64e0b0190759d94617bf3da8bd45adb2f78',
        'registerExternalSupervisedCutoverManualHandoff' => '724ed999105fae1ccc8c37039887abe4ae6ab4e896433dc6fdf81f0f0171ad61',
        'externalSupervisedCutoverManualHandoffStatus' => '477144e03d590625df666d48b43ae7572e87f3299cab75e2e002111e1db0adfa',
        'bindExternalSupervisedCutoverManualCloseoutReceipt.rejected' => '54ecaf9d2fde3e92b4e90ddf079b5124e0d772f10203157bb398f613285c16cc',
        'externalSupervisedCutoverManualCloseoutStatus' => 'cad2348754b28d4e8c86064b27b7497ce6d60d73a1e88f6b134af03b5d7c3fef',
        'externalSupervisedCutoverPortfolioReadinessStatus' => '40850e39e6c6a77476332cf3a8350f76fc177cf13f252e38a0f8d7f13af842f8',
        'applyExternalSupervisedCutoverCompanyEvidenceBundle.rejected' => '19d918d529f0a5ecb6326e9db98b78b8784716de1e6f823fc52440514e516df2',
        'applyExternalSupervisedCutoverPortfolioEvidenceBundle.rejected' => 'c5ddb5fcbf46d5ae21293997124a633ff634f571aa8a628559ecab05c68e6b7e',
        'enterpriseCompanyCompletionCertificationStatus' => '0701096790db1b5bfb98a30090b6e9bc8466d25dd13a576cae6aabaa04c22e01',
        'enterpriseHoldingCompletionAuditStatus' => 'cc35b862957584420decfe52fda0c7de2d2cd19cb55a57611ba031f7138b983a',
        'enterpriseVerticalOperationalDepthStatus' => '10cf1a43db034ae1a9fd621053e728de1d999bd1e9a04504fb2fd89d1b18d70f',
        'enterpriseCompanyOperationalExecutionLoopStatus' => '4d507e6c739d8b36bcc90381966009a8e27787e22197bce4e5645b3cf8923a3b',
        'enterpriseCompanyWorkProductAcceptanceEvidenceStatus' => 'e576432d248e0bde6dc5231a9bfc091ef373a8deda627c8fff5f2c9da0283d58',
        'enterpriseCompanyWorkProductRuntimeRegister' => 'c82b17c8c8086d010938f74ad52296a331efe0bbb7d0fcc5f82bd468c04f4c57',
        'enterpriseCompanyWorkProductRuntimeStatus' => 'b9549bda4c91bc1232f059c2f991ce9674b6e0329e40e025225ea45f44d75ae4',
        'enterpriseCompanyOperatingBlueprintRuntimeRegister' => '6a8305de7ed2b367d2216aae1cfe2de10ebad1f880a063377773978cc695df98',
        'enterpriseCompanyOperatingBlueprintRuntimeStatus' => '120814367a65d56b92b7907da2ed666da697d34db6a54b5a71bf28f6b2edad29',
        'enterpriseCompanyBusinessRuntimePersistenceRegister' => 'b780a017b9ffd44e30329d780a493e713fe96ae1b4e8651224cd17519f45b81f',
        'enterpriseCompanyBusinessRuntimePersistenceStatus' => '56635d99cb8540809bae2a756f4b2ee497ba31a4226401bc1b6a02ed69167444',
        'enterpriseCompanyCapabilityRuntimeMeshRegister' => '2085d8cf797815146ba412295a6e15b189d4a598307b7ff4c416dd840fa10bb3',
        'enterpriseCompanyCapabilityRuntimeMeshStatus' => '09cdd73787f38e4baf8c2d6374d5af1c6acb1f12698bf67d70cb0057ce3aa3da',
        'enterpriseCompanySupervisedConnectorExecutionRegister' => '87df9832be82d13dbe50c037c34416d5326db54bcbe22685c95a40dc170a6d69',
        'enterpriseCompanySupervisedConnectorExecutionStatus' => '52eca738d4c686c989b074534137204749f04368992816ea90fd5c23344a1a24',
        'enterpriseCompanyExternalToolActivationWorkOrderRegister' => 'e2a8ed61758e79daa849f38016b1e92eacd05d385b2a5b21049670831160d897',
        'enterpriseCompanyExternalToolActivationWorkOrderStatus' => '144a565d55661eec9ecbbeda8714e537b43a9403d385bd17ae2af50e1f01f226',
        'enterpriseCompanyExternalToolActivationPacketRegister' => '63a376b96c94e25d077a417d2d0794c6c14747a539d6b7922f503c1f30f74462',
        'enterpriseCompanyExternalToolActivationPacketStatus' => '2d59d19fa7f35e959c893e5bb0ef938132bf92616aa91785a7c6e08deb721a4e',
        'enterpriseCompanyVerticalToolOperatingRuntimeRegister' => 'c4fcdea46b69ab6168c558ad893f1654ad72f7dd62a9499c188162a68da691b1',
        'enterpriseCompanyVerticalToolOperatingRuntimeStatus' => '75a00c71f87d227a5631c3ab0310b441b38fbf077c2f3af147a0d46d8267d132',
        'enterpriseCompanyBusinessExecutionControlPlaneRegister' => '1f49eb1de0696f5fd7e5c29cc9f3b9b8a15918b231147248147b034e52820821',
        'enterpriseCompanyBusinessExecutionControlPlaneStatus' => 'de5273c858a5a6d38eebc203703fba62fb9fb55938a592fb675f8f00d23213f3',
        'enterpriseCompanyCommercialServiceCatalogStatus' => 'a2fdf49be674fdedc6a71120d9746d82e050ce5773c8c9f3eaf6a091edb2a971',
        'enterpriseCompanyRevenueDeliveryOperatingMeshStatus' => '32dc3e878fed0750f916595e64f09e2c37d8322bda651e15c7ea1df77f15b2aa',
        'enterpriseCompanyOrgOperatingModelStatus' => '73e09f68ca0ee3f6c7f01dfd36233df6a064bde5e2795dacced8a42507c0ba20',
        'enterpriseCompanyCustomerDeliveryLifecycleStatus' => 'fbbad6fe29705488fab12694d48df62fdb73d75ded7d2347e52f649000f37aa2',
        'enterpriseCompanyQualityComplianceLifecycleStatus' => 'b033e451d68add8d73390a2170eea798a0fc72b5332f36a416751f76ae363ca6',
        'enterpriseCompanyOperatingCycleStatus' => 'b3a54102c36f14bf6e1e0e1097f5d7eab5e825264a10409262ba68ec1b5a2dbc',
        'enterpriseCompanyOperatingCadenceStatus' => '385711c54ba0ce3843b6f535de93ff7804eba0a9d6169c8330f022d01dd9bc3b',
        'enterpriseCompanyOperatingScorecardStatus' => '75b93f8b97a27056aa5b86790ab919a850f229a9a149d5ce3dceacf943924b06',
        'enterpriseCompanyActiveOperatingSystemStatus' => 'ae017b03669802f215b254ada455e22f5c8c0e9cba66a76ad7495fe1bfaa8d8f',
        'enterpriseCompanyCapabilityCatalogStatus' => '4be560fec6733c0945b5c5723f4e3ee3ecd359eecb558eb0988011d513231fdb',
        'enterpriseCompanyIntegrationReadinessStatus' => '35fbe06a1a752ff2f19a4a1d28054f55df8a2a691d7cad4eeed2cc08042303a2',
        'enterpriseDomainWorkloadAgentTemplateStatus' => '03f768241fe51c8e2dd8abb58201ece5c61b3bd56ecc92304bacc9040ef95326',
        'enterpriseCompanyDomainSolutionPackStatus' => '93871aa13d5b605436cdba2242144c1c0cfcc209ae0c2d64c833d51cec4fbe07',
        'enterpriseCompanyAgentOperationsPackStatus' => '800d95272aa77c6fef8c70b3d9747579e91bf4ece136f7d593344375ea057d9e',
        'enterpriseCompanyAgentWorkforceRuntimeRegister' => '6a1b1ed3c9e98e77cdbe8abfad6485e8666703965fab2d85c4c04edbaef34559',
        'enterpriseCompanyAgentWorkforceRuntimeStatus' => '8670a3ed680567066fbddf5c534546a68e4fbb18aac7eac13c86fb23f50cd07b',
        'enterpriseCompanyDomainOperatingModelCertificationStatus' => 'a98063634b0cabdd2a7cbbcd9a97efe6231d9df0857a37b86131f160542aa64c',
        'enterpriseCompanyDomainToolExecutionReadinessStatus' => '3e4b27d729a2d4bd39ca1b16adabdacac1eb1329e8bc839a4b9d351a5eee6d4c',
        'enterpriseCompanyFlowToolExecutionLedgerStatus' => '42cf1ae838646f592b36db736c3b25afe0d1ffe84869b6551ecc73f47cef8f15',
        'enterpriseCompanyFlowToolExecutionRuntimeRegister' => '156faa8c9240b847be64758276e822bde3ec3d762cb2b63172ae5ba1ab4440d2',
        'enterpriseCompanyFlowToolExecutionRuntimeStatus' => '4323461172ddeb36d8f66c91baa3521b52bc298abd01ae2bdf96e89bbb2c23c7',
        'enterpriseCompanyDomainAdapterExecutionEnvelopeRegister' => '2e88a75c0f92512439b32418ffc1dd9e6a210176d41883b6301623dc15ff2bc6',
        'enterpriseCompanyDomainAdapterExecutionEnvelopeStatus' => '65b6dc2a4dfd4d66f5cc2e23ae8aba4b6f4e658fc392073274a9f78ffbae1a2b',
        'enterpriseCompanyProductionReadinessCertificationStatus' => '7870d939bba67fedf15d824c2cf0cedb14b3e7818e1d088ef99db46f91a91cd1',
        'enterpriseCompanyOperatingEvidenceBundleStatus' => 'd5c93c0879d4408e7698fa86c1d0fc39ba11e0527661ec6b5c9b6fa78cfadcd9',
        'registerActivationBacklog' => '6b1555c71c9ee0eea6ae1284b7b2c3025bd7dcdfca7410f292aa62415252a886',
        'activationBacklogStatus' => '82fcba456bb12bc3738e57906eec4cfb1bb9059ec34a76b5c711049efd0be9c2',
        'runActivationBacklog' => '9903551df7df87731d8dc45fc2f83771ff307b10ac1d6cd38701c8f001afc3d5',
        'registerConnectorActivations' => '78a54f9969dff67c37ca27c8465e5155482e223e5b3060571a0e222f761b9753',
        'probeConnectorActivations' => '8a01415e88a55b698653ba4fa12c81b7dcfa1651c5a0f842210fd1687bb53e9f',
        'connectorActivationStatus' => '3f33b324e24ad9889610e7b1cce85d41d4d91e17dbab109de76ef9163fc01a15',
        'liveReadConnectorReadinessStatus' => 'cb8f6b1bfea1d887779c1d64e0889f830cfbf8107786fafa6f15b0918dc56fc4',
        'registerFlowRunQueue' => '80f3c19404dfdd7c00427b0a30cf475dfba5e6a8691f8e71d67c39e4982cae2c',
        'executeFlowRunQueue' => '9e68c6adab4681505f92727ad07ce2548ff2b96bdf23688c9d944b87a57ab0ae',
        'replayFlowRunQueue' => '262f7a23b3c4f25fc5ca09ed06346e36dd359b9b3fbb816fe19039502247c020',
        'flowRunQueueStatus' => '7edc12920670ad4694b47d198d283db9b6114c985089c43b6f9075bb3ef041f4',
        'registerFlowOperationsRunbooks' => '6a2d0ba76cb714e4cbd6a138b5eefa13b3e0633f72c3bb9fd413b3fcf3aaff7e',
        'drillFlowOperationsRunbooks' => 'ac9961e0ea9a0f0ce6742fd628dbf23c581954a4bd6081b039a726cf3ebf5302',
        'flowOperationsRunbookStatus' => '106b61ba8264e4b20a2d4d054e62e84fa55542de6317a6e611ddec29c71acacb',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::FROZEN_NOW);
        // Deterministic uuids: HasUuids models + Str::uuid() chains feed envelope
        // hashes; a counter factory freezes the whole hash chain run-to-run.
        $sequence = 0;
        Str::createUuidsUsing(static function () use (&$sequence): UuidInterface {
            return Uuid::fromString(sprintf('00000000-0000-4000-8000-%012d', ++$sequence));
        });
    }

    protected function tearDown(): void
    {
        Str::createUuidsNormally();
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    public function test_public_api_surface_is_frozen(): void
    {
        $reflection = new ReflectionClass(ExternalActionMandateRegistryService::class);

        $lines = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor()) {
                continue; // DI wiring may change during the split; callers resolve via container.
            }
            $params = [];
            foreach ($method->getParameters() as $param) {
                $type = $param->getType();
                $typeName = $type instanceof ReflectionNamedType
                    ? ($type->allowsNull() && $type->getName() !== 'null' ? '?' : '').$type->getName()
                    : (string) $type;
                $default = $param->isDefaultValueAvailable()
                    ? '='.json_encode($param->getDefaultValue())
                    : '';
                $params[] = $typeName.' $'.$param->getName().$default;
            }
            $return = $method->getReturnType();
            $returnName = $return instanceof ReflectionNamedType ? $return->getName() : (string) $return;
            $lines[] = $method->getName().'('.implode(', ', $params).'): '.$returnName;
        }
        sort($lines);

        $constants = $reflection->getConstants();
        ksort($constants);
        foreach ($constants as $name => $value) {
            $lines[] = 'const '.$name.'='.json_encode($value);
        }

        $fingerprint = hash('sha256', implode("\n", $lines));

        $this->record('public_api', ['fingerprint' => $fingerprint, 'lines' => $lines]);

        $this->assertCount(self::PUBLIC_METHOD_COUNT, array_filter($lines, static fn (string $l): bool => ! str_starts_with($l, 'const ')));
        $this->assertSame(self::PUBLIC_API_FINGERPRINT, $fingerprint, "Public API surface changed:\n".implode("\n", $lines));
    }

    public function test_full_public_surface_value_snapshots(): void
    {
        $this->migrateExternalActionMandates();
        $service = app(ExternalActionMandateRegistryService::class);

        $chained = [];
        $actual = [];
        foreach ($this->callPlan() as $key => $call) {
            $result = $call($service, $chained);
            $chained[$key] = $result;
            $json = $this->canonicalJson($result);
            $actual[$key] = getenv('ATLAS_GOLDEN_DUMP') !== false ? $json : hash('sha256', $json);
        }

        $this->record('call_snapshots', $actual);

        $expected = self::CALL_SNAPSHOTS;
        $this->assertSame(array_keys($expected), array_keys($actual), 'Call plan drifted');
        foreach ($expected as $key => $hash) {
            $this->assertSame($hash, $actual[$key], "Golden snapshot broke for [{$key}]");
        }
    }

    public function test_mandate_approval_workflow(): void
    {
        $this->migrateExternalActionMandates();
        $service = app(ExternalActionMandateRegistryService::class);

        $register = $service->register('finance');
        $this->assertTrue((bool) $register['ok']);
        $this->assertSame(ExternalActionMandateRegistryService::REGISTRY_SCHEMA, $register['schema']);
        $this->assertSame('queued_for_operator_review', $register['status']);
        $this->assertNotEmpty($register['records']);
        $this->assertSame(0, $register['summary']['auto_execute_allowed_count']);
        $this->assertFalse((bool) $register['registry_policy']['external_execution_enabled_by_registry']);

        $hash = (string) $register['records'][0]['mandate_packet_hash'];
        $this->assertSame(64, strlen($hash));

        $preflight = $service->preflight($hash);
        $this->assertTrue((bool) $preflight['ok'], json_encode($preflight['failed_checks'] ?? null));
        $this->assertSame('preflight_green_awaiting_signatures', $preflight['status']);
        $this->assertFalse((bool) $preflight['external_execution_allowed']);

        $request = $service->requestApproval($hash);
        $this->assertTrue((bool) $request['ok']);
        $this->assertSame('awaiting_operator_and_reviewer_signatures', $request['status']);
        $this->assertCount(2, $request['approvals']);

        foreach ($request['approvals'] as $approval) {
            $decision = $service->decideApproval((string) $approval['uuid'], 'approved', 'golden_operator');
            $this->assertTrue((bool) $decision['ok']);
            $this->assertSame('approved', $decision['status']);
            $this->assertFalse((bool) $decision['external_execution_allowed']);
        }

        $status = $service->approvalStatus($hash);
        $this->assertTrue((bool) $status['ok']);
        $this->assertSame('signed_mandate_ready_manual_execution_only', $status['mandate_status']);
        $this->assertTrue((bool) $status['operator_approved']);
        $this->assertTrue((bool) $status['second_reviewer_approved']);
        $this->assertFalse((bool) $status['external_execution_allowed']);
        $this->assertSame('manual_execution_only_even_after_signatures', $status['external_execution_blocker']);
    }

    /**
     * Ordered call plan covering every public method. Methods needing state that
     * cannot be built deterministically here run their (equally frozen) guard or
     * missing-record paths.
     *
     * @return array<string,callable(ExternalActionMandateRegistryService,array<string,mixed>):array<string,mixed>>
     */
    private function callPlan(): array
    {
        $company = static fn (string $method): callable => static fn (ExternalActionMandateRegistryService $s): array => $s->{$method}('finance');

        $plan = [
            'register' => static fn (ExternalActionMandateRegistryService $s): array => $s->register('finance'),
            'preflight.missing' => static fn (ExternalActionMandateRegistryService $s): array => $s->preflight('not-a-real-hash'),
            'requestApproval.missing' => static fn (ExternalActionMandateRegistryService $s): array => $s->requestApproval('not-a-real-hash'),
            'decideApproval.invalid' => static fn (ExternalActionMandateRegistryService $s): array => $s->decideApproval('deadbeef', 'bogus', 'golden_operator'),
            'approvalStatus.missing' => static fn (ExternalActionMandateRegistryService $s): array => $s->approvalStatus('not-a-real-hash'),
        ];

        foreach ([
            'controlTower', 'activationCockpit', 'premiumActivationStatus', 'providerWorkbenchStatus',
            'agentRepositoryAdoptionStatus', 'agentRepositoryOperatingCatalogStatus', 'domainDataFabricStatus',
            'domainDataConnectorOperatingStatus', 'flowLiveReadConnectorProbeStatus', 'externalResearchAdoptionStatus',
            'flowBenchmarkReplayStatus', 'connectorCertificationPreflightStatus', 'domainAgentToolchainCertificationStatus',
            'industrySolutionEcosystemStatus', 'businessOperatingBackboneStatus', 'productionConnectorPreflightStatus',
            'flowQualityResearchStatus', 'companyCommandCenterStatus', 'flowOperatingPackageStatus',
            'verticalSolutionSuiteStatus', 'domainBusinessExecutionMeshStatus', 'operationalDressRehearsalStatus',
            'realExternalExecutionReadinessDossier', 'realExternalExecutionHandoffPack',
            'supervisedExternalExecutionPacketStatus',
        ] as $method) {
            $plan[$method] = $company($method);
        }

        $plan['supervisedExternalExecutionPacketStatusFromHandoff'] = static fn (ExternalActionMandateRegistryService $s, array $c): array => $s->supervisedExternalExecutionPacketStatusFromHandoff($c['realExternalExecutionHandoffPack']);
        $plan['externalWorkerPreflightStatus'] = $company('externalWorkerPreflightStatus');
        $plan['externalWorkerPreflightStatusFromPackets'] = static fn (ExternalActionMandateRegistryService $s, array $c): array => $s->externalWorkerPreflightStatusFromPackets($c['supervisedExternalExecutionPacketStatus']);
        $plan['externalWorkerDispatchPlanStatus'] = $company('externalWorkerDispatchPlanStatus');
        $plan['externalWorkerDispatchPlanStatusFromPreflight'] = static fn (ExternalActionMandateRegistryService $s, array $c): array => $s->externalWorkerDispatchPlanStatusFromPreflight($c['externalWorkerPreflightStatus']);
        $plan['externalLaunchControlStatus'] = $company('externalLaunchControlStatus');
        $plan['externalLaunchControlStatusFromDispatchPlans'] = static fn (ExternalActionMandateRegistryService $s, array $c): array => $s->externalLaunchControlStatusFromDispatchPlans($c['externalWorkerDispatchPlanStatus']);
        $plan['externalReceiptBindingStatus'] = $company('externalReceiptBindingStatus');
        $plan['externalReceiptBindingStatusFromLaunchControl'] = static fn (ExternalActionMandateRegistryService $s, array $c): array => $s->externalReceiptBindingStatusFromLaunchControl($c['externalLaunchControlStatus']);
        $plan['externalSupervisedCutoverDossierStatus'] = $company('externalSupervisedCutoverDossierStatus');
        $plan['externalSupervisedCutoverDossierStatusFromReceiptBinding'] = static fn (ExternalActionMandateRegistryService $s, array $c): array => $s->externalSupervisedCutoverDossierStatusFromReceiptBinding($c['externalReceiptBindingStatus']);
        $plan['externalSupervisedCutoverWorkOrderStatus'] = $company('externalSupervisedCutoverWorkOrderStatus');
        $plan['externalSupervisedCutoverWorkOrderStatusFromDossiers'] = static fn (ExternalActionMandateRegistryService $s, array $c): array => $s->externalSupervisedCutoverWorkOrderStatusFromDossiers($c['externalSupervisedCutoverDossierStatus']);
        $plan['registerExternalSupervisedCutoverWorkOrders'] = $company('registerExternalSupervisedCutoverWorkOrders');
        $plan['externalSupervisedCutoverWorkOrderPersistedStatus'] = $company('externalSupervisedCutoverWorkOrderPersistedStatus');
        $plan['bindExternalSupervisedCutoverWorkItemReceipt.rejected'] = static fn (ExternalActionMandateRegistryService $s): array => $s->bindExternalSupervisedCutoverWorkItemReceipt('nope', 'nope');
        $plan['externalSupervisedCutoverPromotionStatus'] = $company('externalSupervisedCutoverPromotionStatus');
        $plan['bindExternalSupervisedCutoverFinalAuthorityReceipt.rejected'] = static fn (ExternalActionMandateRegistryService $s): array => $s->bindExternalSupervisedCutoverFinalAuthorityReceipt('nope', 'nope', 'nope');
        $plan['registerExternalSupervisedCutoverRuntimeInvocation.rejected'] = static fn (ExternalActionMandateRegistryService $s): array => $s->registerExternalSupervisedCutoverRuntimeInvocation(null);
        $plan['externalSupervisedCutoverRuntimeInvocationStatus'] = $company('externalSupervisedCutoverRuntimeInvocationStatus');
        $plan['executeExternalSupervisedCutoverRuntimeRehearsal.rejected'] = static fn (ExternalActionMandateRegistryService $s): array => $s->executeExternalSupervisedCutoverRuntimeRehearsal(null, 'finance');
        $plan['externalSupervisedCutoverRehearsalPromotionStatus'] = $company('externalSupervisedCutoverRehearsalPromotionStatus');
        $plan['registerExternalSupervisedCutoverManualHandoff'] = static fn (ExternalActionMandateRegistryService $s): array => $s->registerExternalSupervisedCutoverManualHandoff(null, 'finance');
        $plan['externalSupervisedCutoverManualHandoffStatus'] = $company('externalSupervisedCutoverManualHandoffStatus');
        $plan['bindExternalSupervisedCutoverManualCloseoutReceipt.rejected'] = static fn (ExternalActionMandateRegistryService $s): array => $s->bindExternalSupervisedCutoverManualCloseoutReceipt('nope', 'nope', 'nope', 'nope');
        $plan['externalSupervisedCutoverManualCloseoutStatus'] = $company('externalSupervisedCutoverManualCloseoutStatus');
        $plan['externalSupervisedCutoverPortfolioReadinessStatus'] = $company('externalSupervisedCutoverPortfolioReadinessStatus');
        $plan['applyExternalSupervisedCutoverCompanyEvidenceBundle.rejected'] = static fn (ExternalActionMandateRegistryService $s): array => $s->applyExternalSupervisedCutoverCompanyEvidenceBundle('finance', 'nope', 'nope', 'nope');
        $plan['applyExternalSupervisedCutoverPortfolioEvidenceBundle.rejected'] = static fn (ExternalActionMandateRegistryService $s): array => $s->applyExternalSupervisedCutoverPortfolioEvidenceBundle('nope', 'nope', 'nope');

        foreach ([
            'enterpriseCompanyCompletionCertificationStatus', 'enterpriseHoldingCompletionAuditStatus',
            'enterpriseVerticalOperationalDepthStatus', 'enterpriseCompanyOperationalExecutionLoopStatus',
            'enterpriseCompanyWorkProductAcceptanceEvidenceStatus', 'enterpriseCompanyWorkProductRuntimeRegister',
            'enterpriseCompanyWorkProductRuntimeStatus', 'enterpriseCompanyOperatingBlueprintRuntimeRegister',
            'enterpriseCompanyOperatingBlueprintRuntimeStatus', 'enterpriseCompanyBusinessRuntimePersistenceRegister',
            'enterpriseCompanyBusinessRuntimePersistenceStatus', 'enterpriseCompanyCapabilityRuntimeMeshRegister',
            'enterpriseCompanyCapabilityRuntimeMeshStatus', 'enterpriseCompanySupervisedConnectorExecutionRegister',
            'enterpriseCompanySupervisedConnectorExecutionStatus', 'enterpriseCompanyExternalToolActivationWorkOrderRegister',
            'enterpriseCompanyExternalToolActivationWorkOrderStatus', 'enterpriseCompanyExternalToolActivationPacketRegister',
            'enterpriseCompanyExternalToolActivationPacketStatus', 'enterpriseCompanyVerticalToolOperatingRuntimeRegister',
            'enterpriseCompanyVerticalToolOperatingRuntimeStatus', 'enterpriseCompanyBusinessExecutionControlPlaneRegister',
            'enterpriseCompanyBusinessExecutionControlPlaneStatus', 'enterpriseCompanyCommercialServiceCatalogStatus',
            'enterpriseCompanyRevenueDeliveryOperatingMeshStatus', 'enterpriseCompanyOrgOperatingModelStatus',
            'enterpriseCompanyCustomerDeliveryLifecycleStatus', 'enterpriseCompanyQualityComplianceLifecycleStatus',
            'enterpriseCompanyOperatingCycleStatus', 'enterpriseCompanyOperatingCadenceStatus',
            'enterpriseCompanyOperatingScorecardStatus', 'enterpriseCompanyActiveOperatingSystemStatus',
            'enterpriseCompanyCapabilityCatalogStatus', 'enterpriseCompanyIntegrationReadinessStatus',
            'enterpriseDomainWorkloadAgentTemplateStatus', 'enterpriseCompanyDomainSolutionPackStatus',
            'enterpriseCompanyAgentOperationsPackStatus', 'enterpriseCompanyAgentWorkforceRuntimeRegister',
            'enterpriseCompanyAgentWorkforceRuntimeStatus', 'enterpriseCompanyDomainOperatingModelCertificationStatus',
            'enterpriseCompanyDomainToolExecutionReadinessStatus', 'enterpriseCompanyFlowToolExecutionLedgerStatus',
            'enterpriseCompanyFlowToolExecutionRuntimeRegister', 'enterpriseCompanyFlowToolExecutionRuntimeStatus',
            'enterpriseCompanyDomainAdapterExecutionEnvelopeRegister', 'enterpriseCompanyDomainAdapterExecutionEnvelopeStatus',
            'enterpriseCompanyProductionReadinessCertificationStatus', 'enterpriseCompanyOperatingEvidenceBundleStatus',
            'registerActivationBacklog', 'activationBacklogStatus',
        ] as $method) {
            $plan[$method] = $company($method);
        }

        $plan['runActivationBacklog'] = static fn (ExternalActionMandateRegistryService $s): array => $s->runActivationBacklog('finance');
        $plan['registerConnectorActivations'] = $company('registerConnectorActivations');
        $plan['probeConnectorActivations'] = static fn (ExternalActionMandateRegistryService $s): array => $s->probeConnectorActivations('finance');
        $plan['connectorActivationStatus'] = $company('connectorActivationStatus');
        $plan['liveReadConnectorReadinessStatus'] = $company('liveReadConnectorReadinessStatus');
        $plan['registerFlowRunQueue'] = $company('registerFlowRunQueue');
        $plan['executeFlowRunQueue'] = static fn (ExternalActionMandateRegistryService $s): array => $s->executeFlowRunQueue('finance');
        $plan['replayFlowRunQueue'] = static fn (ExternalActionMandateRegistryService $s): array => $s->replayFlowRunQueue('finance');
        $plan['flowRunQueueStatus'] = $company('flowRunQueueStatus');
        $plan['registerFlowOperationsRunbooks'] = $company('registerFlowOperationsRunbooks');
        $plan['drillFlowOperationsRunbooks'] = static fn (ExternalActionMandateRegistryService $s): array => $s->drillFlowOperationsRunbooks('finance');
        $plan['flowOperationsRunbookStatus'] = $company('flowOperationsRunbookStatus');

        return $plan;
    }

    /**
     * Canonical JSON: key order preserved (it IS behavior). Uuids are already
     * deterministic via the frozen Str uuid factory in setUp().
     */
    private function canonicalJson(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';
    }

    private function record(string $section, array $payload): void
    {
        $path = (string) getenv('ATLAS_GOLDEN_RECORD');
        if ($path === '') {
            return;
        }
        $existing = is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
        $existing[$section] = $payload;
        file_put_contents($path, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function migrateExternalActionMandates(): void
    {
        $this->createAtlasToolRuntimeTables();
        foreach ([
            'database/migrations/2026_05_18_010000_create_ai_domain_runtime_tables.php',
            'database/migrations/2026_05_19_120000_create_ai_operator_approvals_table.php',
            'database/migrations/2026_05_20_020000_create_ai_holding_external_action_mandates_table.php',
            'database/migrations/2026_05_20_021000_create_ai_holding_activation_backlog_items_table.php',
            'database/migrations/2026_05_20_022000_add_implementation_cycle_to_ai_holding_activation_backlog_items_table.php',
            'database/migrations/2026_05_20_023000_create_ai_holding_connector_activation_records_table.php',
            'database/migrations/2026_05_20_024000_create_ai_holding_enterprise_flow_run_queue_items_table.php',
            'database/migrations/2026_05_20_026000_add_operating_package_to_ai_holding_enterprise_flow_run_queue_items_table.php',
            'database/migrations/2026_05_20_025000_create_ai_holding_enterprise_flow_operations_runbooks_table.php',
            'database/migrations/2026_05_20_027000_add_operating_package_to_ai_holding_enterprise_flow_operations_runbooks_table.php',
            'database/migrations/2026_05_20_028000_create_ai_holding_external_cutover_work_orders_table.php',
            'database/migrations/2026_05_20_029000_create_ai_holding_external_cutover_work_items_table.php',
            'database/migrations/2026_05_20_030000_add_receipt_binding_to_ai_holding_external_cutover_work_items_table.php',
            'database/migrations/2026_05_20_031000_add_final_authority_bindings_to_ai_holding_external_cutover_work_orders_table.php',
            'database/migrations/2026_05_20_032000_create_ai_holding_external_cutover_runtime_invocations_table.php',
            'database/migrations/2026_05_20_033000_add_rehearsal_execution_to_ai_holding_external_cutover_runtime_invocations_table.php',
            'database/migrations/2026_05_20_034000_add_manual_handoff_packet_to_ai_holding_external_cutover_runtime_invocations_table.php',
            'database/migrations/2026_05_20_035000_add_manual_closeout_to_ai_holding_external_cutover_runtime_invocations_table.php',
        ] as $path) {
            Artisan::call('migrate', ['--path' => $path, '--force' => true]);
        }
    }
}
