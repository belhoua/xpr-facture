<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/**
 * Budget de requêtes de l'écriture d'un document.
 *
 * Ce fichier ne teste pas un comportement visible mais un COÛT : le nombre de
 * requêtes SQL que déclenche l'enregistrement d'une facture ne doit pas
 * dépendre du nombre de lignes qu'elle porte.
 *
 * Il existe parce que la régression est invisible autrement. Les lignes
 * partaient une à une (`$item->save()` en boucle) : la facture était juste, les
 * totaux étaient justes, tous les autres tests passaient — seule la latence
 * grimpait avec le nombre de postes, ce qu'aucune assertion fonctionnelle ne
 * pouvait rattraper. Un futur `foreach` réintroduit au même endroit ferait
 * échouer ce test-ci, et lui seul.
 *
 * On compare DEUX écritures plutôt que de figer un nombre absolu : le total
 * dépend du contexte tenant, de la numérotation et de la sérialisation, qui
 * évoluent légitimement. Ce qui ne doit pas évoluer, c'est l'ÉCART entre une
 * facture de 3 lignes et une facture de 30.
 */

/**
 * Compte les requêtes SQL émises pendant l'exécution.
 *
 * @param  callable(): void  $work
 */
function countQueries(callable $work): int
{
    $count = 0;

    DB::listen(static function () use (&$count): void {
        $count++;
    });

    $work();

    // Pas de `DB::flushQueryLog()` : l'écouteur est posé sur la connexion pour
    // la durée du test, et chaque test repart d'une application neuve.
    return $count;
}

/**
 * @return array<string, mixed>
 */
function payloadWithLines(int $lines): array
{
    $items = [];

    for ($i = 0; $i < $lines; $i++) {
        $items[] = [
            'label' => 'Poste '.($i + 1),
            'quantity' => '1',
            'unitPriceCents' => 10_000,
        ];
    }

    return [
        'type' => 'invoice',
        'clientName' => 'Client Budget SARL',
        'items' => $items,
    ];
}

it('enregistre une facture à coût de requêtes constant, quel que soit le nombre de lignes', function (): void {
    [$user] = workspaceAccount();

    $short = countQueries(function () use ($user): void {
        actingAs($user)
            ->postJson('/api/v1/documents', payloadWithLines(3))
            ->assertCreated();
    });

    $long = countQueries(function () use ($user): void {
        actingAs($user)
            ->postJson('/api/v1/documents', payloadWithLines(30))
            ->assertCreated();
    });

    // 27 lignes de plus ne doivent coûter aucune requête supplémentaire : elles
    // partent dans le même INSERT (DocumentItem::insertMany). La tolérance
    // couvre le seul écart légitime — la relecture des lignes en réponse, dont
    // le nombre de requêtes ne dépend pas du nombre de lignes.
    expect($long)->toBeLessThanOrEqual($short + 1);
});
