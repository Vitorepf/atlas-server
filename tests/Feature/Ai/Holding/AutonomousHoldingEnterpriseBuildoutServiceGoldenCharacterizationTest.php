<?php

namespace Tests\Feature\Ai\Holding;

use App\Services\Ai\Holding\AutonomousHoldingEnterpriseBuildoutService;
use Tests\TestCase;

/**
 * GOD-DEBULK golden characterization (2026-07-22).
 *
 * Freezes the EXACT current output of AutonomousHoldingEnterpriseBuildoutService
 * before the method-family split. The service is a pure deterministic data
 * generator (the only nondeterministic field is report()['generated_at']),
 * so the whole deep tree — values AND key order — is frozen as sha256 of
 * json_encode. Any behavioral drift during the split breaks these hashes.
 */
class AutonomousHoldingEnterpriseBuildoutServiceGoldenCharacterizationTest extends TestCase
{
    // GOD-DEBULK 3c: report + software deep hashes re-frozen after the software manifest
    // runtime_commands re-pin (atlas:ai:engineering-company quarantined → live kernel CLI;
    // blueprint 91c334a27 §2.3). Receipt hashes unchanged — data drift is command_surfaces only.
    private const REPORT_DEEP_HASH = '9caf526ef23e660127283de1204d45d8e79f679e78d387e816a926134a5548e7';

    private const REPORT_RECEIPT_HASH = '336bc015a6b8e6c24171a1a46058a6bb333a57a44c060a0d44dea9ac91845691';

    private const REPORT_TOP_KEYS = [
        'ok',
        'schema',
        'generated_at',
        'company_count',
        'enterprise_company_count',
        'companies',
        'cross_company_fabric',
        'portfolio_governance_stack',
        'structural_completion_policy',
        'portfolio_operating_model',
        'receipt_hash',
    ];

    /**
     * company_id => [receipt_hash, deep sha256 of the full company packet].
     */
    private const COMPANY_HASHES = [
        'software' => ['c07e1153b09034a54cc374b04c03fd0780cd8051f9b31195ab16e440b2c03f4a', 'a27b84c42b03471c077f6cf673c483ea4dd133be1d435c918793e9407498fd1d'],
        'research' => ['98c1deb645660b6da8d5a166532ee188eda0004ade7e7232095cc95994d919fa', '82c54b1308d577a588bddbd65295580f923a4435a1e3e24a8cc81b408c62dad7'],
        'strategy' => ['e2dcb860612344d1b4cd46aa2827cc76c602376b31361ae0b28fc6c3e193657c', 'aa42f95c6634612634a8e924f67c091079b6af6f248de18a56096205d084b064'],
        'finance' => ['da2e4205c4c7ebe30e5864477f73f941e1b7f04a10d42b6788087bee95a31363', '2238e85491b96418122deeb6a4c9499d97496498302a37adfac58872feaffcb6'],
        'marketing' => ['6550fb5a9960c01833f7ebc35e4f4d500062c54928d02930d2100dfc0350b412', '8a85583799e1fdd175db824a322a5a1c3684a6596087e4c56416ca764cc48324'],
        'cyber' => ['da324fb69f0024ecb83876389997ff1e018b5215f033ebd9a14369c0c08883dd', '6213b9e00d11dc4f8f2d34883f6291e360c0021f1479d89aee73fa17d5f5f221'],
        'personal_development' => ['a0dc4f67800102eea67de269357a47f7e0bda86d1680f0b1d5110f422cbb2308', 'f6b7674224848cdcd1d4328438161c998ef2035f567f99d5b49603b7402d214c'],
        'automation' => ['84a40dedb34244672f1196beec484645110e2998fc5d3425bcc468ba3f8c04c5', '400f2b79b14a6704abd8837ea3c0707c1671fee382bf0dd4d5bc7894ead3bf40'],
        'operations' => ['4018b50223f1acb6c83187448f5a4b873f0b7dc9908234053f1a24f328cf4abe', 'b66d19bf18c4c0f9820acdec981078a0c26badcbbb46f2245071af4dce395db9'],
    ];

    private function deepHash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function test_report_full_deep_tree_is_frozen(): void
    {
        $report = app(AutonomousHoldingEnterpriseBuildoutService::class)->report();

        $this->assertSame(self::REPORT_TOP_KEYS, array_keys($report));
        $this->assertTrue($report['ok']);
        $this->assertSame(AutonomousHoldingEnterpriseBuildoutService::SCHEMA, $report['schema']);
        $this->assertSame(9, $report['company_count']);
        $this->assertSame(9, $report['enterprise_company_count']);
        $this->assertSame(self::REPORT_RECEIPT_HASH, $report['receipt_hash']);

        unset($report['generated_at']);
        $this->assertSame(self::REPORT_DEEP_HASH, $this->deepHash($report));
    }

    public function test_every_company_packet_is_frozen_deep(): void
    {
        $report = app(AutonomousHoldingEnterpriseBuildoutService::class)->report();
        $companies = collect($report['companies'])->keyBy('company_id');

        $this->assertSame(array_keys(self::COMPANY_HASHES), $companies->keys()->all());

        foreach (self::COMPANY_HASHES as $companyId => [$receiptHash, $deepHash]) {
            $company = $companies->get($companyId);
            $this->assertSame(AutonomousHoldingEnterpriseBuildoutService::COMPANY_SCHEMA, $company['schema'], $companyId);
            $this->assertSame($receiptHash, $company['receipt_hash'], $companyId);
            $this->assertSame($deepHash, $this->deepHash($company), $companyId);
        }
    }

    public function test_company_packet_without_report_cache_matches_frozen_hash(): void
    {
        // Fresh instance: exercises the DomainSeedManifests lookup path (no cache).
        $service = app()->make(AutonomousHoldingEnterpriseBuildoutService::class);

        $packet = $service->companyPacket('software');
        $this->assertSame(self::COMPANY_HASHES['software'][0], $packet['receipt_hash']);
        $this->assertSame(self::COMPANY_HASHES['software'][1], $this->deepHash($packet));
    }

    public function test_company_packet_unknown_domain_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown enterprise company domain [does_not_exist].');

        app()->make(AutonomousHoldingEnterpriseBuildoutService::class)->companyPacket('does_not_exist');
    }

    public function test_public_api_surface_is_frozen(): void
    {
        $reflection = new \ReflectionClass(AutonomousHoldingEnterpriseBuildoutService::class);

        $publicMethods = collect($reflection->getMethods(\ReflectionMethod::IS_PUBLIC))
            ->map(fn (\ReflectionMethod $method): string => sprintf(
                '%s(%s): %s',
                $method->getName(),
                collect($method->getParameters())
                    ->map(fn (\ReflectionParameter $parameter): string => (string) $parameter->getType().' $'.$parameter->getName())
                    ->implode(', '),
                (string) ($method->getReturnType() ?? 'mixed'),
            ))
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            '__construct(App\Services\Ai\Finance\Kernel\FinanceEnterpriseAnalysisService $finance): mixed',
            'companyPacket(string $domainId): array',
            'report(): array',
        ], $publicMethods);

        $this->assertSame('atlas.ai.autonomous_holding.enterprise_buildout.v1', AutonomousHoldingEnterpriseBuildoutService::SCHEMA);
        $this->assertSame('atlas.ai.company.enterprise_buildout.v1', AutonomousHoldingEnterpriseBuildoutService::COMPANY_SCHEMA);
    }
}
