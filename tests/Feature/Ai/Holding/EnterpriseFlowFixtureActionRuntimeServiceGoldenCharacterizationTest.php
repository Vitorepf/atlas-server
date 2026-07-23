<?php

namespace Tests\Feature\Ai\Holding;

use App\Services\Ai\Holding\AutonomousHoldingEnterpriseBuildoutService;
use App\Services\Ai\Holding\EnterpriseFlowFixtureActionRuntimeService;
use Tests\TestCase;

/**
 * GOD-DEBULK golden characterization net for EnterpriseFlowFixtureActionRuntimeService::run().
 *
 * run() is the ~2248 LOC payload-builder floor of the KEPT-LIVE fixture runtime
 * (consumed by 8 domain commands via EnterpriseFlowFixtureSuiteService::runCompany).
 * The two pre-existing Holding golden suites do NOT reach run() — this net freezes
 * its EXACT fixture-mode output before decomposing the method, so any byte drift in
 * the payload (values OR key order) during the split breaks a hash here.
 *
 * run() in fixture mode is a pure deterministic data generator (fixtureMode=true =>
 * runtime_record is never persisted, no now()/uuid/random on the path), so the whole
 * deep tree is frozen as sha256 of canonical JSON for every (company, action) pair in
 * the runtime_action_catalog of every enterprise company.
 *
 * Regenerate (after an INTENTIONAL behavior change only):
 *   ATLAS_GOLDEN_RECORD=1 DB_CONNECTION=sqlite DB_DATABASE=':memory:' \
 *     php vendor/bin/phpunit tests/Feature/Ai/Holding/EnterpriseFlowFixtureActionRuntimeServiceGoldenCharacterizationTest.php
 * then paste the printed map over RUN_SNAPSHOTS + the printed aggregate/key consts.
 */
class EnterpriseFlowFixtureActionRuntimeServiceGoldenCharacterizationTest extends TestCase
{
    private const PAIR_COUNT = 59;

    private const AGGREGATE_HASH = '36f25fc7c1fa34ed8179adcec0b44f93f7d7cdca207a7d3acc0742f35a31352f';

    private const TOP_KEY_COUNT = 78;

    private const TOP_KEY_SIGNATURE = 'e57f4516481cc7d58c4e860b19ea4353860ce193ce7308ac3c6d346e10884029';

    /**
     * "company_id::action" => sha256 of canonical JSON of run($companyId, $action) (fixture mode).
     *
     * @var array<string,string>
     */
    private const RUN_SNAPSHOTS = [
        'software::product_spec_to_patch' => '1c76b74670be70699e9f3ad7860b58ab13ee26872b45eb813110cfd6f9bba73c',
        'software::autonomous_repair_loop' => 'e68056bcfa94070bbcab2a1a3516cb54f1c570ea59fd1d3dd48c352fcf7b0999',
        'software::release_certification' => 'd62600f4d41572286157482b6f8cc1a369ffc815dab29cc6ca7235def6e63ef5',
        'software::security_review' => 'c44622c16601dabb011c706db34f9a1cadae93404bdef6348329df154942503a',
        'software::dependency_impact_map' => '3c1a761dfa5ad6f19bfe4e7bc7e1bc3f3c178e2b9fa3547d24436591e5a60bf2',
        'software::post_release_learning_loop' => '372ad0330a13fbdf436ceae08a69a1b5a1a4d478de99c2504f8ca272b10cc893',
        'research::deep_research_brief' => 'f235b743a058dedfc0737298a99cf72e2e474ddbb6868d0e56751d845fc056a4',
        'research::citation_graph_review' => '06d532eb9d0ff32bc28bfaef3da0229150ae142ba8e99dfa9f3993457a712299',
        'research::contradiction_adjudication' => 'f02f26ad4f7858c464df9bae345ba2090630aff0daa8aea2e697b2b5ded14202',
        'research::executive_synthesis' => '341d07ad0082841150a5ef45d9d8fcce2788ddd946682a0a2f851a503019a154',
        'research::source_watchlist_monitor' => 'eaf117c16445428e7d4301596606e5d6dd7387f94b2d86697b36b9dbf4f02182',
        'research::primary_source_gap_review' => 'cbf20cd1fe415388498c13881a46be9c9065d4d8918d8737c8bc7999d9131661',
        'strategy::venture_thesis' => 'c622d41c6c94153a585054670a03d738378278eafe47b96ded6843178a2da7b1',
        'strategy::market_map' => 'ee0fb11f9323e0e268fc3c9e3b5766cc5922dabc2316881c5dd23c82c015e2d2',
        'strategy::gtm_system_design' => '7ff7f8b3672876e8143417fd634fce3788058ffeded71063eb41e3aa76dee7bb',
        'strategy::portfolio_experiment_review' => '8a1578c9b316e91d2188a5fd6ba7baf5be25128d33a5f52e3c0ed75586fcf680',
        'strategy::competitive_wargame' => '504eac406707c7e110611114a15e1b1fddac8858ec33aa7e9ad910a274fff309',
        'strategy::board_decision_dossier' => '9ef22f26a8eef1ed6d9f8ccb10a389742f57f5bf5401d12e6509db8dd52f6a82',
        'finance::market_research_brief' => '0a47cfe5a0f81337d677ebe1df4850406d9612fe0e3bdd96bdce1739978c2a69',
        'finance::financial_model_audit' => 'ff4b534e3912a9212a20b209a8efcb7158e5807b164d9a3e121fa14f1ba5d0a8',
        'finance::portfolio_deep_dive' => '98af6c3a3e6167db8654de8a4cf2f2ca42a5d1a8739f1731086d2ced8fad088f',
        'finance::data_room_due_diligence' => '2543ab980c9c575cdd1a4c10e48e95e51767b7ecfc344ef704bfd4051ec10242',
        'finance::compliance_obligation_mapping' => 'ffd21b001d93acf13673667359dfc34d821f8610217882c2080037ea4bc4b726',
        'finance::investment_committee_memo' => '43568980de5e112897efc459f7f13a5012e2e1a9ea64feef301dece315fd37b7',
        'marketing::growth_strategy' => 'ab4f59f12a5a605ec48b8b9fbf3d0e5da29869ab05822a05c7f294a4299bd5c0',
        'marketing::brand_positioning_system' => '2e7c2b3af7eac01cd07d3d980fa824287ff6ab52d65d81170ce8909238892fad',
        'marketing::creative_production_brief' => '43859c37bac9dde8e204a50a7a18459754932c8b0941a129a5211e1d6d550d9e',
        'marketing::funnel_experiment_loop' => '31e018be7a687af1fc120c8c6400ea00edc1632c724fc3940b0fac85c85afcfe',
        'marketing::lifecycle_campaign_system' => '431d1fdd9d39789619bb6f202d56d7a87202e90de4aa8a1197c704d3b8bf02c2',
        'marketing::channel_mix_review' => '249c1a6b13b43b68ec155eadd5eb8f0f3093fe4fd5e5c8ac1c09a43b5eac9ed1',
        'marketing::voice_of_customer_synthesis' => 'bbf7e7c107867a60171db2e5be4d7eedaeb8be512809c06061095a066df72243',
        'cyber::security_posture_review' => '2a69aa502763a0014697dd845cf573a2f5b2a07e5808ce3ee7313854bf36f8e8',
        'cyber::appsec_triage' => '97ae588af9916c94b8d779841b926c192d492cb9e050c56b0d13bae94069e034',
        'cyber::grc_obligation_map' => 'd14d883ac1a6014fbb81a431b55afa01db08fbe2d5b62b89791a78c761367970',
        'cyber::detection_authoring_review' => '455d4f8ecd18d632653f5f415ba914a82f0317b3552a24acb003fe0b3959b279',
        'cyber::remediation_program' => '6521cb521d86c935a2c1da5a64e4e91e60da53918328a0b82c0a593de861331d',
        'cyber::incident_response_readiness' => 'a70c263129ee6b1d2bc7e9091da152c9ac7f05803cb6bbbdcb17885d0a66a0c8',
        'cyber::attack_surface_delta_review' => '8be8d717bb489932f6d0b290827fa393565189d70625f47112c2ceb6015fe6c0',
        'personal_development::life_operating_review' => '0d0ef10ef180f95fff3fe44496b953add1839efe5269d928f9f84e4dc6899f60',
        'personal_development::learning_curriculum_design' => '13962aaaaf9b54fcdf30d872e2dd30d2018f78b463e7705c49e35ea8d6c31948',
        'personal_development::habit_system_iteration' => '493a788f931a16e6161632fe366c6071f7648556b28f655f00fe1ae7dbe582d6',
        'personal_development::reflection_synthesis' => 'dbaf7008b0c06b8382493700ffce08be23d99c7024a251e3368c2de40b358e9a',
        'personal_development::privacy_safe_memory_review' => '22564352162f4fd29effa45c2675a318305c63a20934a25fac1763d6ab64b4db',
        'personal_development::skill_gap_diagnosis' => 'bfc681e2c803f81319ac4301a6c82516c114afce8cea717a59ed978a34deeb72',
        'personal_development::practice_session_review' => 'c469bfedb8f650565edc03bfe0b6da991e0663aa905a9763d1f436fb172553d6',
        'automation::automation_opportunity_intake' => '8b3adbc61955a4fa6e7ef47021c92859256b4fe090e8f31a8e99edc87315ede1',
        'automation::tool_selection_benchmark' => '94928dd6a9cf4a2728458d9c0fcd72f1df081e51e57655c1135253bba38da9ec',
        'automation::browser_workflow_design' => '658dd3b0bce634e21282c815f4917510ab613a1b03ef4d1f620f07db0a7f88ad',
        'automation::api_workflow_design' => '0d0a56ff60e681a397d732b52377a8e6061c5309c32f8e22f83496ebf19e80bb',
        'automation::tool_reliability_loop' => 'bf3b772c973b1bfc6b10bce6824b44898659058691007351116bbd68eccf88ea',
        'automation::mcp_adapter_design' => '4590525ab20e2ab5e047f7cc1bac99ec3b65232d983d0cc03fc3a6793a548b37',
        'automation::automation_replay_review' => '157dfeba1dcbb0dcfd793059b449dc9269d8f6c8f402289521d41b7cc729b26a',
        'operations::operational_readiness_review' => '74baa12d1feee9502629d9dfd905a44fdb8cb28531e7727d72ff7cdfd769cff7',
        'operations::incident_command_packet' => 'df13ee2378456f7b64abc498a5056b25984b5b8114e96082eb8e4cb72387de76',
        'operations::runbook_system_update' => 'ed18e384dc7e7ad222fc7f2260e2ee4d345fb8c1cdbf927c7407bc039d67a1a6',
        'operations::capacity_and_slo_review' => '9188564ee1f850fab40bc14da4d2388c926f8d61ddb96841eb1f1ed7a017e16d',
        'operations::postmortem_action_loop' => '77bcaabed0e993b50d8e7583437f9874c7c3f1e1d104236bb205e19826775deb',
        'operations::alert_noise_reduction' => '083f9ee8726d72eebf44eea75c34cf1ad65f3565e9bda6702e1bd354d038ed8f',
        'operations::change_readiness_review' => 'fc4fe118c8739e805c83ab088f9c10ff2aff2b251335be277c45b9fc82fcf0cd',
    ];

    private function deepHash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @return list<array{0:string,1:string}> ordered [companyId, action] pairs
     */
    private function pairs(AutonomousHoldingEnterpriseBuildoutService $buildout): array
    {
        $pairs = [];
        foreach ($buildout->report()['companies'] as $company) {
            $companyId = (string) $company['company_id'];
            $actions = (array) data_get(
                $buildout->companyPacket($companyId),
                'enterprise_flow_action_runtime_stack.runtime_action_catalog',
                [],
            );
            foreach ($actions as $action) {
                $pairs[] = [$companyId, (string) $action['action']];
            }
        }

        return $pairs;
    }

    public function test_every_run_output_is_frozen_deep(): void
    {
        $buildout = app(AutonomousHoldingEnterpriseBuildoutService::class);
        $runtime = app(EnterpriseFlowFixtureActionRuntimeService::class);

        $pairs = $this->pairs($buildout);
        $this->assertCount(self::PAIR_COUNT, $pairs);

        $recorded = [];
        $aggregate = '';
        foreach ($pairs as [$companyId, $action]) {
            $key = $companyId.'::'.$action;
            $output = $runtime->run($companyId, $action);

            $this->assertSame(self::TOP_KEY_COUNT, count($output), $key);
            $this->assertSame(
                self::TOP_KEY_SIGNATURE,
                hash('sha256', json_encode(array_keys($output), JSON_THROW_ON_ERROR)),
                'top-level key shape drifted at '.$key,
            );

            $hash = $this->deepHash($output);
            $recorded[$key] = $hash;
            $aggregate .= $key.'|'.$hash."\n";

            if (getenv('ATLAS_GOLDEN_RECORD') === false || getenv('ATLAS_GOLDEN_RECORD') === '') {
                $this->assertArrayHasKey($key, self::RUN_SNAPSHOTS, 'unexpected new fixture pair '.$key);
                $this->assertSame(self::RUN_SNAPSHOTS[$key], $hash, 'run() output drifted at '.$key);
            }
        }

        $this->assertSame(self::PAIR_COUNT, count($recorded));
        $this->assertSame(self::AGGREGATE_HASH, hash('sha256', $aggregate));

        if (getenv('ATLAS_GOLDEN_RECORD') !== false && getenv('ATLAS_GOLDEN_RECORD') !== '') {
            $lines = [];
            foreach ($recorded as $key => $hash) {
                $lines[] = sprintf("        '%s' => '%s',", $key, $hash);
            }
            fwrite(STDERR, "\n// AGGREGATE_HASH = ".hash('sha256', $aggregate)."\n");
            fwrite(STDERR, "private const RUN_SNAPSHOTS = [\n".implode("\n", $lines)."\n];\n");
        }
    }

    public function test_snapshot_map_covers_exactly_the_live_fixture_pairs(): void
    {
        $buildout = app(AutonomousHoldingEnterpriseBuildoutService::class);

        $liveKeys = array_map(
            static fn (array $pair): string => $pair[0].'::'.$pair[1],
            $this->pairs($buildout),
        );

        sort($liveKeys);
        $frozenKeys = array_keys(self::RUN_SNAPSHOTS);
        sort($frozenKeys);

        $this->assertSame($frozenKeys, $liveKeys, 'RUN_SNAPSHOTS drifted from the live fixture action catalog');
    }
}
