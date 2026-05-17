<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Http;

use App\Http\Controllers\AtlasDev\RunController;
use App\Http\Controllers\AtlasDev\Support\CompactSddUnavailableException;
use App\Http\Controllers\AtlasDev\Support\RunExecutionResult;
use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Http\Requests\AtlasDev\RunRequest;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\RunIndex\AtlasDevRunIndexRepository;
use App\Services\Ai\Programming\AtlasDev\Runtime\RunWorkerDispatcher;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use App\Services\Ai\Programming\AtlasDev\Security\ConfirmationTokenResult;
use App\Services\Ai\Programming\AtlasDev\Security\ConfirmationTokenService;
use App\Services\Ai\Programming\AtlasDev\SeniorLoop\SeniorEngineerLoopExecutionReporter;
use Illuminate\Config\Repository as ConfigRepository;
use Symfony\Component\HttpFoundation\InputBag;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;

/**
 * F-03 HTTP layer regression: RunController must translate a
 * {@see CompactSddUnavailableException} from the executor into a 422 response
 * with the typed COMPACT_SDD_MISSING / COMPACT_SDD_INVALID code AND must
 * never invoke the executor's downstream collaborators once the receipt
 * cannot be attested.
 *
 * Lives under Feature/ because it covers a controller HTTP response, but it
 * uses unit-style stubs (no DB) — the broader Feature test bootstrap is
 * currently blocked by an in-flight infra change unrelated to F-03.
 */
final class RunCompactSddMissingTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private string $tmpStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-f03-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);
        config()->set('atlas_dev.efficient.run_enabled', true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        parent::tearDown();
    }

    public function test_executor_compact_sdd_missing_maps_to_422_with_typed_code(): void
    {
        [$runId, $providedHash, $token] = $this->seedRunWithoutCompactSdd();

        $controller = $this->makeController(
            executor: new ThrowingExecutor(CompactSddUnavailableException::missing($runId)),
        );

        $response = $controller->__invoke($this->makeRunRequest($runId, $providedHash, $token));

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('COMPACT_SDD_MISSING', $body['error']['code']);
        $this->assertSame('missing', $body['error']['reason']);
        $this->assertSame($runId, $body['error']['run_id']);
    }

    public function test_executor_compact_sdd_invalid_maps_to_422_with_invalid_code(): void
    {
        [$runId, $providedHash, $token] = $this->seedRunWithoutCompactSdd();

        $controller = $this->makeController(
            executor: new ThrowingExecutor(
                CompactSddUnavailableException::invalid($runId, "task_kind 'not_a_real_kind' is not in VerificationReceipt::ALLOWED_TASK_KINDS"),
            ),
        );

        $response = $controller->__invoke($this->makeRunRequest($runId, $providedHash, $token));

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('COMPACT_SDD_INVALID', $body['error']['code']);
        $this->assertSame('invalid', $body['error']['reason']);
        $this->assertStringContainsString('task_kind', $body['error']['detail']);
    }

    /**
     * Persist envelope + task_contract + prompt_projection so the controller
     * advances to the executor call. CompactSDD intentionally NOT seeded —
     * the test substitutes the executor with one that throws the right
     * exception so the controller's mapping is what gets exercised.
     *
     * @return array{0:string,1:string,2:string} run_id, task_contract_hash, token
     */
    private function seedRunWithoutCompactSdd(): array
    {
        $runId = 'dev-ctrl-'.bin2hex(random_bytes(3));
        $storage = new ReceiptStorage($this->tmpStorage);

        $envelope = $this->envelopeFixture();
        $taskContract = $this->taskContractFixture();
        $promptProjection = $this->buildSendableProjection(envelope: $envelope);

        $storage->writeAtomic($runId, ArtifactNames::OPERATION_ENVELOPE, $envelope->toCanonicalArray());
        $storage->writeAtomic($runId, ArtifactNames::TASK_CONTRACT, $taskContract->toCanonicalArray());
        $storage->writeAtomic($runId, ArtifactNames::PROMPT_PROJECTION, $promptProjection->toCanonicalArray());
        // NOTE: COMPACT_SDD intentionally absent — the executor stub will
        // throw, exercising the controller's 422 mapping.

        $this->app->instance(ReceiptStorage::class, $storage);

        return [$runId, $taskContract->taskContractHash, 'fake-token'];
    }

    private function makeController(RunExecutor $executor): RunController
    {
        $config = new ConfigRepository([
            'atlas_dev' => [
                'efficient' => [
                    'run_enabled' => true,
                    'desktop_enabled' => true,
                    'run_dispatch_mode' => 'inline',
                ],
            ],
        ]);
        $tokens = new AlwaysOkTokenService;
        $storage = $this->app->make(ReceiptStorage::class);

        return new RunController(
            $executor,
            $storage,
            $tokens,
            $config,
            $this->app->make(AtlasDevRunIndexRepository::class),
            new NullRunWorkerDispatcher,
            $this->app->make(SeniorEngineerLoopExecutionReporter::class),
        );
    }

    private function makeRunRequest(string $runId, string $providedHash, string $token): RunRequest
    {
        $request = RunRequest::create(
            '/ai/interactions/atlas-dev/run',
            'POST',
            [
                'run_id' => $runId,
                'task_contract_hash' => $providedHash,
                'confirmation_token' => $token,
                'operator_confirmed' => true,
            ],
        );
        $request->setLaravelSession($this->app['session']->driver());
        $request->setJson(new InputBag([
            'run_id' => $runId,
            'task_contract_hash' => $providedHash,
            'confirmation_token' => $token,
            'operator_confirmed' => true,
        ]));
        $request->headers->set('content-type', 'application/json');

        return $request;
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path)) {
                $this->rmrf($path);
            } else {
                @chmod($path, 0o600);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

final class NullRunWorkerDispatcher implements RunWorkerDispatcher
{
    public function dispatch(string $runId, string $taskContractHash, ?string $expectedCompactSddHash = null): ?int
    {
        return null;
    }
}

/**
 * Executor stub that throws a CompactSddUnavailableException so the
 * RunController's HTTP mapping is the only thing under test.
 */
final class ThrowingExecutor implements RunExecutor
{
    public int $calls = 0;

    public function __construct(private readonly CompactSddUnavailableException $exception) {}

    public function execute(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        string $runId,
        ?string $expectedCompactSddHash = null,
    ): RunExecutionResult {
        $this->calls++;
        throw $this->exception;
    }
}

/**
 * Token service stub: always returns OK so the controller advances to the
 * executor call.
 */
final class AlwaysOkTokenService extends ConfirmationTokenService
{
    public function __construct() {}

    public function validateAndConsume(string $runId, string $taskContractHash, string $token): ConfirmationTokenResult
    {
        return ConfirmationTokenResult::ok('stub-token-id');
    }
}
