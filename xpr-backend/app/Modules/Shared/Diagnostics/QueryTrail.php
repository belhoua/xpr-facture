<?php

declare(strict_types=1);

namespace App\Modules\Shared\Diagnostics;

/**
 * INSTRUMENTATION TEMPORAIRE — à retirer avec le bloc de diagnostic de
 * RegisterController.
 *
 * DB::listen() ne journalise qu'APRÈS succès : la requête qui échoue n'y
 * apparaît jamais, et c'est précisément celle qu'on cherche. On enregistre donc
 * aussi les requêtes TENTÉES, via Connection::beforeExecuting(), depuis le boot
 * de l'application — donc y compris la session, le rate limiter et la
 * validation, qui s'exécutent bien avant le contrôleur.
 *
 * La fautive est alors mécanique : la dernière tentée qui n'est pas dans les
 * réussies.
 *
 * État statique assumé : une instance de fonction serverless traite une requête
 * à la fois, et ce code ne survivra pas au diagnostic.
 */
final class QueryTrail
{
    /** @var list<string> */
    private static array $attempted = [];

    /** @var list<string> */
    private static array $succeeded = [];

    /** Borne mémoire : au-delà, on garde le début et la fin. */
    private const LIMIT = 60;

    public static function attempt(string $sql): void
    {
        if (count(self::$attempted) < self::LIMIT) {
            self::$attempted[] = $sql;
        }
    }

    public static function succeed(string $sql): void
    {
        if (count(self::$succeeded) < self::LIMIT) {
            self::$succeeded[] = $sql;
        }
    }

    /**
     * La requête en échec : la première tentée qui n'a pas de succès
     * correspondant, en comparant les deux suites position par position.
     */
    public static function failing(): ?string
    {
        foreach (self::$attempted as $index => $sql) {
            if ((self::$succeeded[$index] ?? null) !== $sql) {
                return $sql;
            }
        }

        return null;
    }

    /** @return array{attempted: list<string>, succeeded: list<string>, failing: ?string} */
    public static function report(): array
    {
        return [
            'attempted' => self::$attempted,
            'succeeded' => self::$succeeded,
            'failing' => self::failing(),
        ];
    }
}
