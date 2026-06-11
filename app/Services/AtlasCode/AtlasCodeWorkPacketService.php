<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use App\Models\AtlasCodeWorkPacket;
use App\Models\AtlasProject;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Atlas Code Work Packet service.
 *
 * Canon:
 *   - docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
 *   - docs/engineering-knowledge-base/atlas-code-adaptive-provider-operating-room-v1.md
 *   - docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md
 *
 * Schema: atlas.code.work_packet.v1
 *
 * A Work Packet is the smallest governed unit Atlas hands to a provider. It
 * declares objective, allowed/forbidden files, acceptance criteria, validation
 * commands, report format and stop rule.
 *
 * Storage: filesystem JSON under `storage/app/atlas-code/work-packets/{obra_id}/{packet_id}.json`.
 * When the operator exports a packet for an Observed Session, Atlas also
 * writes the canonical Markdown copy to `<workspace_path>/.atlas/packets/{packet_id}.md`
 * so the provider can read it locally.
 *
 * This service does NOT call any provider. It only prepares Atlas state and
 * filesystem artifacts. Provider invocation is human-driven (interactive
 * observed) — see AtlasCodeObservedSessionService.
 */
final class AtlasCodeWorkPacketService
{
    public const SCHEMA_VERSION = 'atlas.code.work_packet.v1';

    public function __construct(private readonly AtlasCodeWorkspaceProfileService $profiles) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForObra(string $obraId): array
    {
        if ($this->usesDatabase()) {
            return AtlasCodeWorkPacket::query()
                ->where('obra_id', $obraId)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (AtlasCodeWorkPacket $m): array => $this->shape($this->modelToArray($m)))
                ->all();
        }
        $dir = $this->packetsDir($obraId);
        if (! is_dir($dir)) {
            return [];
        }
        $packets = [];
        foreach ((array) glob($dir.'/*.json') as $file) {
            if (! is_string($file) || ! is_file($file)) {
                continue;
            }
            $raw = @file_get_contents($file);
            if (! is_string($raw) || $raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $packets[] = $this->shape($decoded);
            }
        }
        // Newest first
        usort($packets, static fn (array $a, array $b): int => strcmp(
            (string) ($b['created_at'] ?? ''),
            (string) ($a['created_at'] ?? '')
        ));

        return $packets;
    }

    public function find(string $obraId, string $packetId): ?array
    {
        if ($this->usesDatabase()) {
            $row = AtlasCodeWorkPacket::query()
                ->where('obra_id', $obraId)
                ->where('id', $packetId)
                ->first();

            return $row ? $this->shape($this->modelToArray($row)) : null;
        }
        $path = $this->packetPath($obraId, $packetId);
        if (! is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        return $this->shape($decoded);
    }

    private function usesDatabase(): bool
    {
        try {
            return Schema::hasTable('atlas_code_work_packets');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function modelToArray(AtlasCodeWorkPacket $m): array
    {
        $arr = $m->toArray();
        // schema_version is not a column; inject the canonical value so
        // shape() preserves the contract identically to filesystem mode.
        $arr['schema_version'] = self::SCHEMA_VERSION;

        return $arr;
    }

    /**
     * Create a new Work Packet bound to an Obra.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(AtlasProject $obra, array $input): array
    {
        $packetId = 'wp_'.Str::ulid()->toBase32();
        $now = now()->toJSON();

        $workspaceSlug = (string) (data_get($obra->metadata, 'workspace_slug') ?? '');
        $workspacePath = (string) (data_get($obra->metadata, 'workspace_path') ?? '');
        if ($workspaceSlug !== '' && $workspacePath === '') {
            $profile = $this->profiles->findBySlug($workspaceSlug);
            $workspacePath = (string) ($profile['workspace_path'] ?? '');
        }

        $packet = [
            'schema_version' => self::SCHEMA_VERSION,
            'id' => $packetId,
            'obra_id' => (string) $obra->getKey(),
            'obra_title' => (string) ($obra->title ?? ''),
            'workspace_slug' => $workspaceSlug !== '' ? $workspaceSlug : null,
            'workspace_path' => $workspacePath !== '' ? $workspacePath : null,
            'status' => 'draft',
            'objective' => trim((string) ($input['objective'] ?? '')),
            'context_summary' => trim((string) ($input['context_summary'] ?? '')),
            'allowed_files' => AiStringListNormalizer::trimmedStrings($input['allowed_files'] ?? []),
            'forbidden_files' => AiStringListNormalizer::trimmedStrings($input['forbidden_files'] ?? []),
            'interfaces' => AiStringListNormalizer::trimmedStrings($input['interfaces'] ?? []),
            'constraints' => AiStringListNormalizer::trimmedStrings($input['constraints'] ?? []),
            'acceptance_criteria' => AiStringListNormalizer::trimmedStrings($input['acceptance_criteria'] ?? []),
            'verification_commands' => AiStringListNormalizer::trimmedStrings($input['verification_commands'] ?? []),
            'report_format' => trim((string) ($input['report_format'] ?? "## Resumo\n## Arquivos alterados\n## Testes rodados\n## Riscos\n## Próximos passos")),
            'stop_rule' => trim((string) ($input['stop_rule'] ?? 'Parar e reportar quando: escopo expandir, arquivo proibido for tocado, teste/build falhar 3x sem progresso, ou criterio de aceite não puder ser atendido com o packet atual.')),
            'role_slot' => trim((string) ($input['role_slot'] ?? 'implementation_lead')),
            'risk_band' => trim((string) ($input['risk_band'] ?? 'medium')),
            'task_category' => trim((string) ($input['task_category'] ?? 'feature')),
            'evidence_required' => AiStringListNormalizer::trimmedStrings($input['evidence_required'] ?? ['diff', 'test_run', 'report']),
            'created_at' => $now,
            'updated_at' => $now,
            'exported_at' => null,
            'packet_md_path' => null,
            'prompt_hash' => null,
        ];

        // Markable as ready if mandatory fields present
        if ($packet['objective'] !== '' && count($packet['acceptance_criteria']) > 0) {
            $packet['status'] = 'ready';
        }

        $this->persist($packet);

        return $this->shape($packet);
    }

    /**
     * Export packet to local filesystem and produce a copy-safe prompt.
     *
     * Returns the updated packet (status=ready, exported_at, packet_md_path,
     * prompt_hash) plus the full prompt text and packet markdown.
     *
     * Note: writing to `<workspace_path>/.atlas/packets/` only succeeds when
     * the workspace_path resolves to an existing directory AND is writable.
     * Otherwise we return `packet_md_path=null` with `packet_md_status=skipped`
     * and the operator can paste the markdown manually. No fake success.
     *
     * @return array{packet: array<string,mixed>, prompt: string, packet_md: string, packet_md_status: string, packet_md_path: ?string}
     */
    public function exportForObservedSession(string $obraId, string $packetId, string $providerId): array
    {
        $packet = $this->find($obraId, $packetId);
        if ($packet === null) {
            throw new RuntimeException("work_packet_not_found: {$packetId}");
        }
        if ($packet['objective'] === '' || count($packet['acceptance_criteria']) === 0) {
            throw new RuntimeException('work_packet_incomplete: requires objective and at least one acceptance_criterion');
        }

        $packetMd = $this->buildPacketMarkdown($packet);
        $prompt = $this->buildCopySafePrompt($packet, $providerId);
        $promptHash = hash('sha256', $prompt);

        $packetMdPath = null;
        $packetMdStatus = 'skipped';
        $workspacePath = (string) ($packet['workspace_path'] ?? '');
        if ($workspacePath !== '' && is_dir($workspacePath) && is_writable($workspacePath)) {
            $packetsDir = rtrim($workspacePath, '/').'/.atlas/packets';
            if (! is_dir($packetsDir)) {
                @mkdir($packetsDir, 0775, true);
            }
            if (is_dir($packetsDir) && is_writable($packetsDir)) {
                $target = $packetsDir.'/'.$packet['id'].'.md';
                if (@file_put_contents($target, $packetMd) !== false) {
                    $packetMdPath = $target;
                    $packetMdStatus = 'written';
                }
            }
        }

        $packet['status'] = 'ready';
        $packet['exported_at'] = now()->toJSON();
        $packet['updated_at'] = $packet['exported_at'];
        $packet['packet_md_path'] = $packetMdPath;
        $packet['prompt_hash'] = $promptHash;

        $this->persist($packet);

        return [
            'packet' => $this->shape($packet),
            'prompt' => $prompt,
            'packet_md' => $packetMd,
            'packet_md_status' => $packetMdStatus,
            'packet_md_path' => $packetMdPath,
        ];
    }

    /**
     * @param  array<string, mixed>  $packet
     */
    public function buildCopySafePrompt(array $packet, string $providerId): string
    {
        $packetIdShort = substr((string) ($packet['id'] ?? ''), 0, 16);
        $packetMdRef = $packet['packet_md_path']
            ?? '.atlas/packets/'.($packet['id'] ?? 'unknown').'.md';

        $allowed = $packet['allowed_files'] ?? [];
        $forbidden = $packet['forbidden_files'] ?? [];
        $allowedStr = $allowed === []
            ? '(nenhum declarado — confirme com o operador antes de escrever)'
            : implode("\n  - ", array_map(static fn ($x): string => '`'.$x.'`', $allowed));
        $forbiddenStr = $forbidden === []
            ? '(nenhum declarado)'
            : implode("\n  - ", array_map(static fn ($x): string => '`'.$x.'`', $forbidden));

        $verification = $packet['verification_commands'] ?? [];
        $verificationStr = $verification === []
            ? '(o operador rodará os comandos canônicos do projeto)'
            : implode("\n  - ", array_map(static fn ($x): string => '`'.$x.'`', $verification));

        $acceptance = $packet['acceptance_criteria'] ?? [];
        $acceptanceStr = $acceptance === []
            ? '(nenhum critério canônico declarado — pare e peça ao operador)'
            : implode("\n  - ", $acceptance);

        $providerLabel = match ($providerId) {
            'claude_code' => 'Claude Code interativo',
            'codex_cli' => 'Codex CLI interativo',
            'gemini_cli' => 'Gemini CLI interativo',
            'manual_external' => 'sessão manual externa',
            default => $providerId,
        };

        return <<<PROMPT
Você está trabalhando dentro de uma Obra governada pelo Atlas Code, em modo {$providerLabel}.

Antes de tudo, leia o arquivo de packet local:

  {$packetMdRef}

Esse packet é a fonte da verdade desta sessão observada. Respeite TODOS os campos:

OBJETIVO
  - {$packet['objective']}

REGRA-MÃE
  - Não amplie escopo.
  - Não reverta mudanças que não são suas.
  - Não toque arquivos fora de allowed_files.
  - Não declare completion. Quem declara completion é o Atlas, com gates + review + aceite humano.

ARQUIVOS PERMITIDOS
  - {$allowedStr}

ARQUIVOS PROIBIDOS
  - {$forbiddenStr}

CRITÉRIOS DE ACEITE
  - {$acceptanceStr}

COMANDOS DE VALIDAÇÃO
  - {$verificationStr}

REGRA DE PARADA
  - {$packet['stop_rule']}

ENTREGA FINAL ESPERADA (texto que você devolve para eu copiar no Atlas)
  Seu relatório final DEVE seguir este esqueleto:

{$packet['report_format']}

Importante:
  - Esta sessão é interativa observada. Não rode `claude -p`, não chame Agent SDK, não suba worker headless.
  - Não declare "concluído" por texto. O Atlas vai importar seu relatório, rodar gates e pedir aceite humano.
  - Se faltar contexto, pare e descreva o que precisa.

Packet ID: {$packetIdShort}
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $packet
     */
    public function buildPacketMarkdown(array $packet): string
    {
        $now = (string) ($packet['exported_at'] ?? $packet['updated_at'] ?? $packet['created_at'] ?? now()->toJSON());
        $bullets = static function (array $items, string $emptyMsg): string {
            if ($items === []) {
                return '_'.$emptyMsg.'_';
            }

            return '- '.implode("\n- ", array_map(static fn ($x): string => (string) $x, $items));
        };

        $allowed = $bullets((array) ($packet['allowed_files'] ?? []), 'nenhum declarado');
        $forbidden = $bullets((array) ($packet['forbidden_files'] ?? []), 'nenhum declarado');
        $constraints = $bullets((array) ($packet['constraints'] ?? []), 'nenhum');
        $interfaces = $bullets((array) ($packet['interfaces'] ?? []), 'nenhum');
        $criteria = $bullets((array) ($packet['acceptance_criteria'] ?? []), 'nenhum');
        $verify = $bullets((array) ($packet['verification_commands'] ?? []), 'nenhum');
        $evidence = $bullets((array) ($packet['evidence_required'] ?? []), 'diff,test_run,report');

        return <<<MD
# Atlas Work Packet · {$packet['id']}

> Schema: {$packet['schema_version']}  ·  Generated: {$now}
> Canon: docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md

## Obra
- ID: `{$packet['obra_id']}`
- Título: {$packet['obra_title']}
- Workspace slug: `{$packet['workspace_slug']}`
- Workspace path: `{$packet['workspace_path']}`

## Operacional
- Status: `{$packet['status']}`
- Role slot: `{$packet['role_slot']}`
- Risk band: `{$packet['risk_band']}`
- Task category: `{$packet['task_category']}`

## Objetivo
{$packet['objective']}

## Contexto
{$packet['context_summary']}

## Arquivos permitidos
{$allowed}

## Arquivos proibidos
{$forbidden}

## Interfaces / contratos afetados
{$interfaces}

## Restrições
{$constraints}

## Critérios de aceite
{$criteria}

## Comandos de verificação
{$verify}

## Evidência exigida
{$evidence}

## Regra de parada
{$packet['stop_rule']}

## Formato do relatório final
```
{$packet['report_format']}
```

---
_Atlas Code · Work Packet read by an interactive observed provider session. The provider does NOT declare completion — Atlas imports the report, runs gates, and asks for human acceptance._
MD;
    }

    public function packetsBaseDir(): string
    {
        return storage_path('app/atlas-code/work-packets');
    }

    public function packetsDir(string $obraId): string
    {
        return $this->packetsBaseDir().'/'.$this->safeSegment($obraId);
    }

    public function packetPath(string $obraId, string $packetId): string
    {
        return $this->packetsDir($obraId).'/'.$this->safeSegment($packetId).'.json';
    }

    /**
     * @param  array<string, mixed>  $packet
     */
    private function persist(array $packet): void
    {
        $obraId = (string) ($packet['obra_id'] ?? '');
        $packetId = (string) ($packet['id'] ?? '');
        if ($obraId === '' || $packetId === '') {
            throw new RuntimeException('work_packet_persist_missing_keys');
        }

        if ($this->usesDatabase()) {
            $attrs = [
                'id' => $packetId,
                'obra_id' => $obraId,
                'obra_title' => $packet['obra_title'] ?? null,
                'workspace_slug' => $packet['workspace_slug'] ?? null,
                'workspace_path' => $packet['workspace_path'] ?? null,
                'status' => (string) ($packet['status'] ?? 'draft'),
                'objective' => (string) ($packet['objective'] ?? ''),
                'context_summary' => $packet['context_summary'] ?? null,
                'allowed_files' => $packet['allowed_files'] ?? [],
                'forbidden_files' => $packet['forbidden_files'] ?? [],
                'interfaces' => $packet['interfaces'] ?? [],
                'constraints' => $packet['constraints'] ?? [],
                'acceptance_criteria' => $packet['acceptance_criteria'] ?? [],
                'verification_commands' => $packet['verification_commands'] ?? [],
                'evidence_required' => $packet['evidence_required'] ?? [],
                'report_format' => $packet['report_format'] ?? null,
                'stop_rule' => $packet['stop_rule'] ?? null,
                'role_slot' => (string) ($packet['role_slot'] ?? 'implementation_lead'),
                'risk_band' => (string) ($packet['risk_band'] ?? 'medium'),
                'task_category' => (string) ($packet['task_category'] ?? 'feature'),
                'exported_at' => $packet['exported_at'] ?? null,
                'packet_md_path' => $packet['packet_md_path'] ?? null,
                'prompt_hash' => $packet['prompt_hash'] ?? null,
            ];
            AtlasCodeWorkPacket::query()->updateOrCreate(['id' => $packetId], $attrs);

            return;
        }

        $dir = $this->packetsDir($obraId);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $this->packetPath($obraId, $packetId);
        $written = @file_put_contents($path, json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($written === false) {
            throw new RuntimeException("work_packet_persist_failed: {$path}");
        }
    }

    /**
     * @param  array<string, mixed>  $packet
     * @return array<string, mixed>
     */
    private function shape(array $packet): array
    {
        return [
            'schema_version' => (string) ($packet['schema_version'] ?? self::SCHEMA_VERSION),
            'id' => (string) ($packet['id'] ?? ''),
            'obra_id' => (string) ($packet['obra_id'] ?? ''),
            'obra_title' => (string) ($packet['obra_title'] ?? ''),
            'workspace_slug' => isset($packet['workspace_slug']) && $packet['workspace_slug'] !== ''
                ? (string) $packet['workspace_slug']
                : null,
            'workspace_path' => isset($packet['workspace_path']) && $packet['workspace_path'] !== ''
                ? (string) $packet['workspace_path']
                : null,
            'status' => (string) ($packet['status'] ?? 'draft'),
            'objective' => (string) ($packet['objective'] ?? ''),
            'context_summary' => (string) ($packet['context_summary'] ?? ''),
            'allowed_files' => AiStringListNormalizer::trimmedStrings($packet['allowed_files'] ?? []),
            'forbidden_files' => AiStringListNormalizer::trimmedStrings($packet['forbidden_files'] ?? []),
            'interfaces' => AiStringListNormalizer::trimmedStrings($packet['interfaces'] ?? []),
            'constraints' => AiStringListNormalizer::trimmedStrings($packet['constraints'] ?? []),
            'acceptance_criteria' => AiStringListNormalizer::trimmedStrings($packet['acceptance_criteria'] ?? []),
            'verification_commands' => AiStringListNormalizer::trimmedStrings($packet['verification_commands'] ?? []),
            'report_format' => (string) ($packet['report_format'] ?? ''),
            'stop_rule' => (string) ($packet['stop_rule'] ?? ''),
            'role_slot' => (string) ($packet['role_slot'] ?? 'implementation_lead'),
            'risk_band' => (string) ($packet['risk_band'] ?? 'medium'),
            'task_category' => (string) ($packet['task_category'] ?? 'feature'),
            'evidence_required' => AiStringListNormalizer::trimmedStrings($packet['evidence_required'] ?? []),
            'created_at' => (string) ($packet['created_at'] ?? ''),
            'updated_at' => (string) ($packet['updated_at'] ?? ''),
            'exported_at' => isset($packet['exported_at']) && $packet['exported_at'] !== '' ? (string) $packet['exported_at'] : null,
            'packet_md_path' => isset($packet['packet_md_path']) && $packet['packet_md_path'] !== '' ? (string) $packet['packet_md_path'] : null,
            'prompt_hash' => isset($packet['prompt_hash']) && $packet['prompt_hash'] !== '' ? (string) $packet['prompt_hash'] : null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return array_values($out);
    }

    private function safeSegment(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_\-]/', '_', $value) ?? '';
        if ($clean === '') {
            throw new RuntimeException('work_packet_unsafe_id');
        }

        return $clean;
    }
}
