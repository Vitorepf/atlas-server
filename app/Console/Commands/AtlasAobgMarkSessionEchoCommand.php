<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasAurgNode;
use App\Services\Ai\AtlasOpenBrainWriteBackService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Obra #14 H2.5a — backfill do marker de proveniência nos mission nodes já
 * cunhados pelo AOBG session capture ANTES do marker existir na origem.
 *
 * Predicado (medido no DB vivo em 06/07/2026, separação 104/0): um node
 * source_kind='mission' cujo meta.provider está presente E cujo source_id tem
 * forma de UUID de sessão só nasce do write-back do session capture — missões
 * internas usam ids estruturados (obra-*, codex:*, ...) e não gravam provider,
 * ou gravam provider mas com id estruturado. O node NÃO é apagado (é registro
 * legítimo de sessão); ele apenas recebe meta.origin para as superfícies de
 * render (context pack / file context) nunca apresentarem seu label bruto como
 * "decisão" do operador (o canal de echo/auto-injeção do gotcha 6fe3da7b7c).
 *
 * Idempotente: nodes já marcados são pulados. --dry-run apenas relata.
 */
class AtlasAobgMarkSessionEchoCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aobg:mark-session-echo
        {--dry-run : Apenas relata o que seria marcado, sem escrever}
        {--json : Saída JSON canônica}';

    protected $description = 'Backfill do marker origin=aobg_session_capture nos mission nodes minted por session capture (anti-echo do reality graph).';

    private const SESSION_UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

    public function handle(): int
    {
        if (! Schema::hasTable('atlas_aurg_nodes')) {
            return $this->emit(['ok' => false, 'reason' => 'store_missing', 'marked' => 0, 'already_marked' => 0, 'skipped' => 0]);
        }

        $dryRun = (bool) $this->option('dry-run');
        $marked = 0;
        $alreadyMarked = 0;
        $skipped = 0;
        $samples = [];

        AtlasAurgNode::query()
            ->where('source_kind', 'mission')
            ->orderBy('id')
            ->chunkById(200, function ($nodes) use ($dryRun, &$marked, &$alreadyMarked, &$skipped, &$samples): void {
                foreach ($nodes as $node) {
                    $meta = (array) ($node->meta ?? []);
                    if (($meta['origin'] ?? '') === AtlasOpenBrainWriteBackService::MISSION_ORIGIN_SESSION_CAPTURE) {
                        $alreadyMarked++;

                        continue;
                    }
                    $provider = trim((string) ($meta['provider'] ?? ''));
                    $isSessionUuid = preg_match(self::SESSION_UUID_PATTERN, (string) $node->source_id) === 1;
                    if ($provider === '' || ! $isSessionUuid) {
                        $skipped++;

                        continue;
                    }

                    $marked++;
                    if (count($samples) < 10) {
                        $samples[] = [
                            'node_id' => (string) $node->id,
                            'provider' => $provider,
                            'label_head' => mb_substr((string) $node->label, 0, 60),
                        ];
                    }
                    if (! $dryRun) {
                        $meta['origin'] = AtlasOpenBrainWriteBackService::MISSION_ORIGIN_SESSION_CAPTURE;
                        $node->meta = $meta;
                        $node->save();
                    }
                }
            });

        return $this->emit([
            'ok' => true,
            'dry_run' => $dryRun,
            'marked' => $marked,
            'already_marked' => $alreadyMarked,
            'skipped_non_session' => $skipped,
            'samples' => $samples,
        ]);
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function emit(array $result): int
    {
        $result = ['schema_version' => 'atlas.aobg.mark_session_echo.v1'] + $result;

        if ((bool) $this->option('json')) {
            $this->line($this->encode($result));
        } else {
            $this->components->twoColumnDetail('Marcados', (string) ($result['marked'] ?? 0));
            $this->components->twoColumnDetail('Já marcados', (string) ($result['already_marked'] ?? 0));
            $this->components->twoColumnDetail('Pulados (não-sessão)', (string) ($result['skipped_non_session'] ?? 0));
        }

        return ($result['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
