<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use InvalidArgumentException;

/**
 * Atlas Código · onde mora o repositório que a tela pediu.
 *
 * O radar (M3 v2) lê o Mac do operador e mostra a frota inteira; o grafo lia
 * apenas os perfis registrados. O resultado era uma promessa quebrada: o app
 * listava 12 repositórios e abria 3 — os outros respondiam 404 com o nome do
 * repo que ele acabara de ver na tela.
 *
 * A ordem aqui resolve isso sem alargar autoridade:
 *
 *   1. Perfil registrado vence — ele carrega slug canônico e configuração, e é
 *      o que os gates de governança consultam.
 *   2. Sem perfil, o disco responde — o Atlas Código é projeção somente-leitura
 *      e não pode fingir que um repositório do Mac não existe.
 *
 * O que NÃO é feito aqui, de propósito: ensinar `findByReference` a descobrir
 * repositórios no disco. Aquele método também decide governança (onboarding,
 * workspace intelligence, code graph); dar-lhe descoberta automática faria um
 * repositório não registrado passar por workspace governado. Leitura é livre;
 * autoridade continua sendo registrada à mão.
 */
final class AtlasCodeRepoLocator
{
    public function __construct(
        private readonly ?AtlasCodeWorkspaceProfileService $profiles = null,
        private readonly ?AtlasCodeWorkspaceScanner $scanner = null,
    ) {}

    /**
     * @return array{slug:string, path:string}
     */
    public function locate(?string $repo): array
    {
        $requested = trim((string) $repo);

        $profile = ($this->profiles ?? new AtlasCodeWorkspaceProfileService())->findByReference($repo);
        if (is_array($profile)) {
            $path = trim((string) ($profile['repo_root'] ?? $profile['workspace_path'] ?? ''));
            if ($path !== '' && is_dir($path)) {
                return [
                    'slug' => (string) ($profile['slug'] ?? $requested),
                    'path' => $path,
                ];
            }
        }

        $found = ($this->scanner ?? new AtlasCodeWorkspaceScanner())->locate($requested);
        if ($found !== null) {
            return ['slug' => $requested, 'path' => $found];
        }

        // Perfil existe mas o caminho não abre → o caminho é o problema.
        // Nenhum perfil e nada no disco → o repositório é que não existe.
        throw new InvalidArgumentException(
            is_array($profile) ? 'repository_path_missing_or_unreadable' : 'repository_profile_not_found'
        );
    }
}
