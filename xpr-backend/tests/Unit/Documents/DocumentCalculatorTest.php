<?php

declare(strict_types=1);

use App\Modules\Documents\Exceptions\LineAmountOutOfRange;
use App\Modules\Documents\Services\DocumentCalculator;

/**
 * Arithmétique des documents. En Unit et non en Feature : le calculateur ne
 * touche ni la base ni le contexte tenant, et ces cas doivent rester
 * exécutables en quelques millisecondes — c'est la règle la plus souvent
 * modifiée du produit (la réglementation fiscale évolue, §3).
 */
function calc(): DocumentCalculator
{
    return new DocumentCalculator;
}

it('calcule une ligne simple avec TVA à 20 %', function (): void {
    // 3 × 1 000,00 MAD HT = 3 000,00 → TVA 600,00 → TTC 3 600,00
    expect(calc()->line('3', 100_000, '0', '20.00'))->toBe([
        'grossCents' => 300_000,
        'discountCents' => 0,
        'subtotalCents' => 300_000,
        'taxCents' => 60_000,
        'totalCents' => 360_000,
    ]);
});

it('applique la remise AVANT la TVA', function (): void {
    // Taxer avant remise surfacturerait le client : 1 000,00 − 10 % = 900,00,
    // et c'est 900,00 qui porte la TVA, pas 1 000,00.
    expect(calc()->line('1', 100_000, '10.00', '20.00'))->toMatchArray([
        'discountCents' => 10_000,
        'subtotalCents' => 90_000,
        'taxCents' => 18_000,
        'totalCents' => 108_000,
    ]);
});

it('gère les quantités fractionnaires au millième', function (): void {
    // 1,5 heure × 600,00 = 900,00
    expect(calc()->line('1.500', 60_000, '0', '20.00'))->toMatchArray([
        'subtotalCents' => 90_000,
        'taxCents' => 18_000,
    ]);
});

it('arrondit au centime le plus proche, demi-centime vers le haut', function (): void {
    // 0,333 × 100,00 = 33,30 exactement ; le cas intéressant est en dessous.
    // 1 × 33,33 avec TVA 7 % = 2,3331 → 2,33 (arrondi au plus proche).
    expect(calc()->line('1', 3_333, '0', '7.00')['taxCents'])->toBe(233);

    // 1 × 10,00 avec TVA 5,5 % = 0,55 pile — pas de demi-centime ici.
    // Le vrai demi-centime : 1 × 1,00 à 50 % = 0,50 → 50 centimes.
    expect(calc()->line('1', 100, '0', '50.00')['taxCents'])->toBe(50);

    // 1 × 0,01 à 50 % = 0,005 centime → arrondi vers le HAUT = 1 centime.
    expect(calc()->line('1', 1, '0', '50.00')['taxCents'])->toBe(1);
});

it('traite un taux à 0 % sans le confondre avec une absence de TVA', function (): void {
    expect(calc()->line('2', 50_000, '0', '0.00'))->toMatchArray([
        'subtotalCents' => 100_000,
        'taxCents' => 0,
        'totalCents' => 100_000,
    ]);
});

it('additionne les lignes DÉJÀ arrondies pour le total du document', function (): void {
    // Chaque ligne est arrondie séparément, comme sur la facture papier :
    // le pied de page doit être la somme exacte de ce que le client lit.
    $lines = [
        calc()->line('1', 3_333, '0', '7.00'),
        calc()->line('1', 3_333, '0', '7.00'),
        calc()->line('1', 3_333, '0', '7.00'),
    ];

    $totals = calc()->totals(array_map(
        static fn (array $line): array => [
            'subtotalCents' => $line['subtotalCents'],
            'discountCents' => $line['discountCents'],
            'taxCents' => $line['taxCents'],
            'totalCents' => $line['totalCents'],
        ],
        $lines,
    ));

    // 3 × 233 = 699. Un calcul « en pleine précision » aurait donné 700 —
    // et la facture aurait affiché un centime de plus que la somme visible.
    expect($totals)->toBe([
        'subtotalCents' => 9_999,
        'discountCents' => 0,
        'taxCents' => 699,
        'totalCents' => 10_698,
    ]);
});

it('ventile la TVA par taux et fusionne les écritures équivalentes', function (): void {
    $summary = calc()->taxSummary([
        ['taxRate' => '20.00', 'subtotalCents' => 100_000, 'taxCents' => 20_000],
        // « 20 » et « 20.00 » désignent le même taux : une seule ligne attendue.
        ['taxRate' => '20', 'subtotalCents' => 50_000, 'taxCents' => 10_000],
        ['taxRate' => '10.00', 'subtotalCents' => 30_000, 'taxCents' => 3_000],
    ]);

    // Trié par taux croissant : l'ordre du récapitulatif doit être stable
    // d'un document à l'autre.
    expect($summary)->toBe([
        ['rate' => '10.00', 'baseCents' => 30_000, 'taxCents' => 3_000],
        ['rate' => '20.00', 'baseCents' => 150_000, 'taxCents' => 30_000],
    ]);
});

it('refuse une quantité hors des bornes de calcul', function (): void {
    // Au-delà, `quantité × prix` déborderait l'entier 64 bits et le montant
    // deviendrait silencieusement faux — le pire des échecs sur une facture.
    expect(fn () => calc()->line('200000', 100_000, '0', '20.00'))
        ->toThrow(LineAmountOutOfRange::class);

    expect(fn () => calc()->line('0', 100_000, '0', '20.00'))
        ->toThrow(LineAmountOutOfRange::class);
});

it('refuse un prix unitaire hors des bornes de calcul', function (): void {
    expect(fn () => calc()->line('1', 10_000_000_000, '0', '20.00'))
        ->toThrow(LineAmountOutOfRange::class);
});
