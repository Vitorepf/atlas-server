# Armadilha: volume anônimo de `bootstrap/cache` fossiliza providers

> 27/07/2026 · diagnosticado com `atlas-backend` e `atlas-queue` em crash-loop.

## Sintoma

Container em `Restarting (1)` sem parar. Log:

```
In Container.php line 1430:
  Unresolvable dependency resolving [Parameter #0 [ <required> callable
  $pendingPacketsSource ]] in class App\Services\Ai\SelfConstruction\Maestro\
  DynamicPriority\AtlasMaestroPriorityFactSnapshotter
```

No host, `php artisan --version` roda normal. O provider existe, está
registrado em `bootstrap/providers.php` e faz o bind. Tudo aponta para "código
velho no container" — **e essa leitura é falsa**.

## Causa real

O `docker-compose.yml` monta o código com `- .:/app` (bind), então o container
**vê o código atual**. Mas monta também:

```yaml
- /app/bootstrap/cache   # volume anônimo, isola o cache do bind do host
```

Esse volume anônimo sobrevive a `restart`, `up -d` e `build`. Dentro dele,
`services.php` — o manifesto de providers que o Laravel lê no boot — ficou
congelado:

```
-rwxr-xr-x  21919  May 25 04:32  services.php   ← manifesto
app/Providers/AtlasMaestroPriorityServiceProvider.php  ← Jul 24 20:51
```

Provider criado em julho, manifesto de maio. O Laravel confia no manifesto,
nunca registra o provider, o binding não existe e o container morre no boot —
inclusive no `config:clear` do próprio comando de arranque, o que impede
consertar por dentro.

## Conserto

```bash
# 1. descubra o volume anônimo do serviço
docker inspect atlas-backend \
  --format '{{range .Mounts}}{{if eq .Destination "/app/bootstrap/cache"}}{{.Name}}{{end}}{{end}}'

# 2. apague os caches (todos regeneráveis: o boot roda config:cache/route:cache)
docker run --rm -v <VOLUME>:/c alpine \
  rm -f /c/services.php /c/packages.php /c/config.php /c/routes-v7.php

# 3. suba
docker compose restart backend
```

Repita para `atlas-queue`, que tem o **próprio** volume anônimo com a mesma
cópia fossilizada — consertar só o backend deixa a fila em loop.

## Como reconhecer em 30 segundos

```bash
docker run --rm -v <VOLUME>:/c alpine ls -la /c/services.php
```

Se a data do `services.php` for anterior ao provider que falta, é isto.

## Por que não é "código velho na imagem"

Vale insistir porque custou horas: com `- .:/app` **não existe** código velho no
container. Rebuildar a imagem não conserta — o volume anônimo sobrevive ao
rebuild. Só apagar o cache resolve.
