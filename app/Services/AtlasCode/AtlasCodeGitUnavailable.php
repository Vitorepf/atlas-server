<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use RuntimeException;

/**
 * O git não respondeu — e isso NÃO é "não há nada".
 *
 * Antes disto, toda falha de git virava string vazia, e string vazia virava
 * "não há commit hoje". Timeout de 20s num repositório grande, permissão
 * negada, `.git` corrompido, disco fora: tudo dizia ao operador a MESMA coisa
 * que um dia sem trabalho. Ele acreditaria — a frase é afirmativa e não tem
 * ressalva nenhuma.
 *
 * Falha silenciosa que vira fato é a pior mentira que uma ferramenta de
 * governança pode contar, porque ela é indistinguível de uma verdade. Esta
 * exceção existe para a diferença chegar à superfície: "não consegui ler" é
 * uma resposta honesta; "nada mudou" quando não se leu, não é.
 */
final class AtlasCodeGitUnavailable extends RuntimeException {}
