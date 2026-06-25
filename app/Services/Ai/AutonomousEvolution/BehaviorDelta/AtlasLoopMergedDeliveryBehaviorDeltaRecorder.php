<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\BehaviorDelta;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * MERGED-DELIVERY BEHAVIOR-Δ RECORDER (Fase 1 · a fonte de verdade do aprendizado).
 *
 * Calcula o Δ DE COMPORTAMENTO REAL que uma entrega mergeada causou: snapshota o escopo no PRIMEIRO
 * PAI do commit de merge (`<sha>^`) vs no próprio commit, materializando cada estado FRESCO do
 * histórico git (via `git archive`, sem tocar o working-tree/HEAD/index), e diffa os dois com o
 * {@see AtlasLoopBehaviorDeltaComputer}. É o elo que faltava: o {@see AtlasLoopBehaviorDeltaSnapshotter}
 * sozinho só lê o working-tree — este recorder o ancora num PAR DE COMMITS imutáveis.
 *
 * RE-DERIVÁVEL, NUNCA auto-reportado: o número é re-computado do commit imutável a cada chamada — o
 * mesmo princípio ungameable do caller-graph re-resolvido no grader pétreo. Por isso `_behavior_delta`
 * persistido em `quality` é só EVIDÊNCIA/cache; o grader re-computa e jamais o lê como autoritativo
 * (`quality` é writable pelo gerador → confiar nele reintroduziria o reward-hack que o grader fecha).
 *
 * Fail-closed e útil: `measured=false, net=0` quando o commit/pai/git não resolve (ex.: root commit
 * sem pai) — uma entrega sem superfície medível pontua zero, não derruba o grade. Faz ZERO mutação de
 * git e SEMPRE limpa os diretórios efêmeros (finally). Pétreo por estar sob `BehaviorDelta/`: parte do
 * termômetro, o réu não edita o próprio medidor.
 */
final class AtlasLoopMergedDeliveryBehaviorDeltaRecorder
{
    public const SCHEMA = 'atlas.loop.merged_delivery_behavior_delta.v1';

    /** @var callable(string,string,string):array<string,mixed> */
    private $snapshotAtCommit;

    private readonly AtlasLoopBehaviorDeltaComputer $computer;

    /**
     * @param  (callable(string,string,string):array<string,mixed>)|null  $snapshotAtCommit  injeção do
     *         capturador as-of-commit (test); default = materialização real via `git archive`.
     */
    public function __construct(
        ?callable $snapshotAtCommit = null,
        ?AtlasLoopBehaviorDeltaComputer $computer = null,
    ) {
        $this->snapshotAtCommit = $snapshotAtCommit
            ?? fn (string $repoRoot, string $ref, string $scopeRoot): array => $this->gitArchiveSnapshot($repoRoot, $ref, $scopeRoot);
        $this->computer = $computer ?? new AtlasLoopBehaviorDeltaComputer();
    }

    /**
     * O Δ de comportamento real do merge `<sha>` sobre o escopo, re-computado do git (pai vs commit).
     *
     * @return array{schema:string, commit:string, parent_ref:string, scope_root:string, measured:bool, net_behavior_delta:int, symbols_added:int, symbols_removed:int, api_signature_changed:int, caller_edges_added:int, caller_edges_removed:int}
     */
    public function record(string $repoRoot, string $mergeCommitSha, string $scopeRoot): array
    {
        $unmeasured = [
            'schema' => self::SCHEMA,
            'commit' => $mergeCommitSha,
            'parent_ref' => $mergeCommitSha.'^',
            'scope_root' => $scopeRoot,
            'measured' => false,
            'net_behavior_delta' => 0,
            'symbols_added' => 0,
            'symbols_removed' => 0,
            'api_signature_changed' => 0,
            'caller_edges_added' => 0,
            'caller_edges_removed' => 0,
        ];

        if (preg_match('/^[0-9a-f]{7,40}$/i', $mergeCommitSha) !== 1 || trim($scopeRoot) === '') {
            return $unmeasured;
        }

        try {
            $before = ($this->snapshotAtCommit)($repoRoot, $mergeCommitSha.'^', $scopeRoot);
            $after = ($this->snapshotAtCommit)($repoRoot, $mergeCommitSha, $scopeRoot);
        } catch (Throwable) {
            return $unmeasured; // pai/ref/git não resolve => fail-closed (ex.: root commit sem pai)
        }

        if (! is_array($before) || ! is_array($after)) {
            return $unmeasured;
        }

        $delta = $this->computer->compute($before, $after);

        return [
            'schema' => self::SCHEMA,
            'commit' => $mergeCommitSha,
            'parent_ref' => $mergeCommitSha.'^',
            'scope_root' => $scopeRoot,
            'measured' => true,
            'net_behavior_delta' => (int) $delta['net_behavior_delta'],
            'symbols_added' => count($delta['symbols_added']),
            'symbols_removed' => count($delta['symbols_removed']),
            'api_signature_changed' => count($delta['api_signature_changed']),
            'caller_edges_added' => (int) $delta['caller_edges_added'],
            'caller_edges_removed' => (int) $delta['caller_edges_removed'],
        ];
    }

    /**
     * Funde o Δ medido na bag `quality` como EVIDÊNCIA (cache), preservando todas as chaves existentes.
     * O grader pétreo NUNCA lê isto como autoritativo — re-computa fresco — então gravar aqui não abre
     * vetor de reward-hack; é só para auditoria/telemetria e para evitar re-computar fora do grade-time.
     *
     * @param  array<string,mixed>  $existingQuality
     * @param  array<string,mixed>  $deltaRecord  saída de {@see record()}
     * @return array<string,mixed>
     */
    public function mergeIntoQuality(array $existingQuality, array $deltaRecord): array
    {
        $existingQuality['_behavior_delta'] = [
            'schema' => self::SCHEMA,
            'commit' => (string) ($deltaRecord['commit'] ?? ''),
            'measured' => (bool) ($deltaRecord['measured'] ?? false),
            'net_behavior_delta' => (int) ($deltaRecord['net_behavior_delta'] ?? 0),
            'symbols_added' => (int) ($deltaRecord['symbols_added'] ?? 0),
            'symbols_removed' => (int) ($deltaRecord['symbols_removed'] ?? 0),
            'api_signature_changed' => (int) ($deltaRecord['api_signature_changed'] ?? 0),
        ];

        return $existingQuality;
    }

    /**
     * Snapshot determinístico do escopo as-of um ref git, materializando a subárvore via `git archive`
     * num diretório efêmero (zero mutação de HEAD/index/working-tree). Lança quando o ref não verifica
     * (=> unmeasured); retorna snapshot vazio quando o ref verifica mas o pathspec não existe ali (escopo
     * novo no merge ⇒ tudo conta como adição). SEMPRE limpa os efêmeros.
     *
     * @return array<string,mixed>
     */
    private function gitArchiveSnapshot(string $repoRoot, string $ref, string $scopeRoot): array
    {
        $root = rtrim($repoRoot, '/');
        if (! is_dir($root.'/.git')) {
            throw new \RuntimeException('not a git repo: '.$root);
        }
        // Verifica que o ref resolve para um commit — root commit sem pai falha aqui (=> unmeasured).
        $verify = new Process(['git', 'rev-parse', '--verify', '--quiet', $ref.'^{commit}'], $root, $this->gitEnv(), null, 30.0);
        $verify->run();
        if (! $verify->isSuccessful()) {
            throw new \RuntimeException('ref does not resolve: '.$ref);
        }

        $scope = ltrim($scopeRoot, '/');
        $tmp = rtrim(sys_get_temp_dir(), '/').'/atlas-mdbd-'.bin2hex(random_bytes(8));
        $tar = $tmp.'.tar';
        try {
            File::ensureDirectoryExists($tmp);

            $archive = new Process(['git', 'archive', '--format=tar', '-o', $tar, $ref, '--', $scope], $root, $this->gitEnv(), null, 60.0);
            $archive->run();
            if (! $archive->isSuccessful() || ! is_file($tar) || filesize($tar) === 0) {
                // pathspec ausente nesse ref (escopo novo) => snapshot vazio (medido como adições).
                return ['schema' => AtlasLoopBehaviorDeltaSnapshotter::SCHEMA, 'captured_at' => '1970-01-01T00:00:00Z', 'symbols' => [], 'api_surface_hash' => ''];
            }

            $extract = new Process(['tar', '-xf', $tar, '-C', $tmp], $root, $this->gitEnv(), null, 60.0);
            $extract->run();
            if (! $extract->isSuccessful()) {
                return ['schema' => AtlasLoopBehaviorDeltaSnapshotter::SCHEMA, 'captured_at' => '1970-01-01T00:00:00Z', 'symbols' => [], 'api_surface_hash' => ''];
            }

            return (new AtlasLoopBehaviorDeltaSnapshotter)->snapshot($tmp, $scope);
        } finally {
            File::deleteDirectory($tmp);
            @unlink($tar);
        }
    }

    /**
     * PATH saneado para git/tar sob launchd/cron (mesmo padrão do grader pétreo).
     *
     * @return array<string,string>
     */
    private function gitEnv(): array
    {
        $binDir = \dirname(PHP_BINARY);
        $base = getenv('PATH');
        $base = is_string($base) && $base !== '' ? $base : '/usr/bin:/bin:/usr/sbin:/sbin';

        return ['PATH' => '/usr/bin'.PATH_SEPARATOR.'/bin'.PATH_SEPARATOR.$binDir.PATH_SEPARATOR.$base];
    }
}
