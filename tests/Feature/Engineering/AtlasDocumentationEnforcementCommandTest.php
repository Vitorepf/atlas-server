<?php

declare(strict_types=1);

use App\Services\Engineering\AtlasDocumentationEnforcementService;
use App\Services\Engineering\AtlasDocumentationProviderBootstrapProbe;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasDocumentationEnforcementCommandTest extends TestCase
{
    public function test_command_outputs_canonical_json(): void
    {
        $this->bindProviderBootstrapProbe($this->providerBootstrapPayload('ready'));

        $exit = Artisan::call('atlas:documentation:enforce', [
            '--task' => 'elevar documentacao governanca enforcement para nota 10',
            '--feature' => 'documentation enforcement runtime for AI implementation sessions',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasDocumentationEnforcementService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertContains($payload['status'], ['ready', 'review', 'blocked']);
        $this->assertArrayHasKey('certification_hash', $payload);
        $this->assertFalse($payload['writes']);
        $this->assertStringStartsWith('php artisan atlas:documentation:enforce', $payload['required_before_code'][0]);
    }

    public function test_strict_command_returns_failure_unless_status_is_ready(): void
    {
        $this->bindProviderBootstrapProbe($this->providerBootstrapPayload('blocked'));

        Artisan::call('atlas:documentation:enforce', [
            '--task' => 'strict readiness probe',
            '--feature' => 'documentation enforcement runtime',
            '--json' => true,
        ]);
        $nonStrict = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $exit = Artisan::call('atlas:documentation:enforce', [
            '--task' => 'strict readiness probe',
            '--feature' => 'documentation enforcement runtime',
            '--json' => true,
            '--strict' => true,
        ]);
        $strict = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($nonStrict['status'], $strict['status']);
        $this->assertSame($nonStrict['status'] === 'ready' ? 0 : 1, $exit);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function bindProviderBootstrapProbe(array $payload): void
    {
        $this->app->instance(
            AtlasDocumentationProviderBootstrapProbe::class,
            new class($payload) extends AtlasDocumentationProviderBootstrapProbe
            {
                /**
                 * @param  array<string,mixed>  $payload
                 */
                public function __construct(private readonly array $payload) {}

                /**
                 * @return array<string,mixed>
                 */
                public function report(string $task, string $feature, string $workspace): array
                {
                    return $this->payload;
                }
            },
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function providerBootstrapPayload(string $status): array
    {
        $check = [
            'command' => 'php artisan atlas:ai:session-bootstrap --task="<task>" --strict --json',
            'status' => $status,
            'exit_code' => $status === 'ready' ? 0 : 1,
            'payload_status' => $status,
            'schema_version' => null,
        ];

        return [
            'status' => $status,
            'session_bootstrap' => $check,
            'feature_placement' => array_merge($check, [
                'command' => 'php artisan atlas:ai:place-feature "<feature>" --strict --json',
            ]),
            'fail_closed' => true,
        ];
    }
}
