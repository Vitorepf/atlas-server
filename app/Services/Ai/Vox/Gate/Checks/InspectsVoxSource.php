<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Gate\Checks;

/**
 * Suporte compartilhado dos grupos de check da certificação Vox V6.
 *
 * Reúne os leitores de source read-only (arquivo/JSON/glob), o encurtador
 * de path e os status canônicos usados por todos os grupos. Extraído de
 * VoxV6CertificationService na GOD-DEBULK — comportamento idêntico, só
 * deixou de morar num único godfile.
 */
trait InspectsVoxSource
{
    public const STATUS_PASS = 'pass';

    public const STATUS_WARN = 'warn';

    public const STATUS_FAIL = 'fail';

    /**
     * Lista dos arquivos backend Vox que compõem V3→V6 e são alvo dos
     * scans de fronteira (Voice Realtime, mobile, API paga, shell).
     *
     * @return list<string>
     */
    private function backendCoreVoxFiles(): array
    {
        return [
            base_path('app/Services/Ai/Vox/Interlocutor/VoxInterlocutorPolicy.php'),
            base_path('app/Services/Ai/Vox/Routing/VoxAutoModeRouter.php'),
            base_path('app/Services/Ai/Vox/VoxCompiler.php'),
            base_path('app/Services/Ai/Vox/VoxPromptCompiler.php'),
            base_path('app/Services/Ai/Vox/VoxPromptPolisher.php'),
            base_path('app/Services/Ai/Vox/VoxRiskClassifier.php'),
            base_path('app/Services/Ai/Vox/VoxIntentExtractor.php'),
        ];
    }

    private function desktopRoot(): ?string
    {
        $candidate = dirname(base_path()).'/atlas-desktop';

        return is_dir($candidate) ? $candidate : null;
    }

    private function readFile(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);

        return $content === false ? null : $content;
    }

    private function extractBetween(string $source, string $startNeedle, string $endNeedle): ?string
    {
        $start = strpos($source, $startNeedle);
        if ($start === false) {
            return null;
        }

        $end = strpos($source, $endNeedle, $start + strlen($startNeedle));
        if ($end === false) {
            return substr($source, $start);
        }

        return substr($source, $start, $end - $start);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $path): ?array
    {
        $raw = $this->readFile($path);
        if ($raw === null) {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function fileExistsByGlob(string $dir, string $pattern): bool
    {
        if (! is_dir($dir)) {
            return false;
        }
        $hits = glob($dir.'/'.$pattern) ?: [];

        return $hits !== [];
    }

    /**
     * Encurta caminhos absolutos para legibilidade do envelope JSON. NÃO
     * é redação — só substitui HOME por `~` quando aplicável.
     */
    private function shortenPath(string $path): string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '' && str_starts_with($path, $home)) {
            return '~'.substr($path, strlen($home));
        }

        return $path;
    }
}
