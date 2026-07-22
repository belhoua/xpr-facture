<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Documents\Exceptions\LineAmountOutOfRange;

/**
 * Calcul des montants d'un document. **Tout est en entiers** (§7) : jamais un
 * float ne traverse cette classe.
 *
 * Pourquoi c'est non négociable : en binaire, 0.1 + 0.2 ne vaut pas 0.3. Sur
 * une facture à trois lignes, l'écart passe inaperçu ; sur une déclaration de
 * TVA annuelle agrégeant des milliers de documents, il devient un écart
 * comptable qu'aucun contrôleur fiscal n'acceptera.
 *
 * Les grandeurs fractionnaires sont donc converties en entiers d'échelle fixe
 * AVANT tout calcul :
 *  - quantité      → millièmes  (2,5 → 2500)
 *  - pourcentages  → centièmes de point, « points de base » (20,00 % → 2000)
 *
 * ARRONDI COMMERCIAL (§3) : au plus proche, la demi-unité s'arrondissant vers
 * le haut. `intdiv($n + $d / 2, $d)` l'exprime sans division flottante. Toutes
 * les grandeurs manipulées sont positives ou nulles — les avoirs portent des
 * montants positifs, leur sens vient de leur `type`, pas d'un signe — donc
 * `intdiv` tronque bien vers zéro et l'arrondi est exact.
 *
 * ORDRE DES OPÉRATIONS, imposé par le fisc et non par le code : on arrondit à
 * chaque étape (brut, puis remise, puis TVA). Calculer en pleine précision
 * jusqu'au total donnerait un centime d'écart avec la facture papier, sur
 * laquelle chaque ligne est arrondie.
 */
final class DocumentCalculator
{
    /**
     * Bornes de sécurité contre le dépassement d'entier 64 bits.
     *
     * Le produit `quantité × prix` est le seul point de la chaîne où l'on
     * multiplie deux grandeurs saisies. Avec 1e8 millièmes (100 000 unités) et
     * 1e10 centimes (100 M MAD l'unité), il plafonne à 1e18, sous la limite de
     * PHP_INT_MAX (≈ 9,2e18). Les FormRequests appliquent les mêmes bornes ;
     * ce contrôle est la ceinture qui accompagne les bretelles, pour le jour où
     * un appelant interne court-circuitera la validation HTTP.
     */
    private const MAX_QUANTITY_MILLI = 100_000_000;

    private const MAX_UNIT_PRICE_CENTS = 9_999_999_999;

    /**
     * Montants d'UNE ligne.
     *
     * @param  numeric-string|float|int  $quantity  quantité, jusqu'à 3 décimales
     * @param  int  $unitPriceCents  prix unitaire HT, en centimes
     * @param  numeric-string|float|int  $discountPercent  remise de ligne, en %
     * @param  numeric-string|float|int  $taxRate  taux de TVA APPLIQUÉ, en %
     * @return array{grossCents: int, discountCents: int, subtotalCents: int, taxCents: int, totalCents: int}
     *
     * @throws LineAmountOutOfRange si la ligne dépasse les bornes de calcul
     */
    public function line(
        string|float|int $quantity,
        int $unitPriceCents,
        string|float|int $discountPercent,
        string|float|int $taxRate,
    ): array {
        $quantityMilli = self::toScaledInt($quantity, 1000);
        $discountBasisPoints = self::toScaledInt($discountPercent, 100);
        $taxBasisPoints = self::toScaledInt($taxRate, 100);

        if ($quantityMilli <= 0 || $quantityMilli > self::MAX_QUANTITY_MILLI) {
            throw LineAmountOutOfRange::quantity($quantity);
        }

        if ($unitPriceCents < 0 || $unitPriceCents > self::MAX_UNIT_PRICE_CENTS) {
            throw LineAmountOutOfRange::unitPrice($unitPriceCents);
        }

        // Brut HT = quantité × prix unitaire, arrondi au centime.
        $grossCents = self::divideRounded($quantityMilli * $unitPriceCents, 1000);

        // Remise appliquée sur le brut, puis arrondie : c'est le montant qui
        // figure en clair sur le document, il doit être un nombre de centimes.
        $discountCents = self::divideRounded($grossCents * $discountBasisPoints, 10_000);

        // Base d'imposition = brut − remise. C'est ce montant, et lui seul, qui
        // porte la TVA : taxer avant remise surfacturerait le client.
        $subtotalCents = $grossCents - $discountCents;

        $taxCents = self::divideRounded($subtotalCents * $taxBasisPoints, 10_000);

        return [
            'grossCents' => $grossCents,
            'discountCents' => $discountCents,
            'subtotalCents' => $subtotalCents,
            'taxCents' => $taxCents,
            'totalCents' => $subtotalCents + $taxCents,
        ];
    }

    /**
     * Totaux d'un document, par simple somme des lignes DÉJÀ arrondies.
     *
     * Sommer les lignes arrondies plutôt que recalculer sur le total est
     * délibéré : c'est ce que fait la facture papier, et le pied de page doit
     * être la somme exacte de ce que le client lit au-dessus. Recalculer
     * produirait des écarts d'un centime, indéfendables en contrôle.
     *
     * @param  list<array{subtotalCents: int, discountCents: int, taxCents: int, totalCents: int}>  $lines
     * @return array{subtotalCents: int, discountCents: int, taxCents: int, totalCents: int}
     */
    public function totals(array $lines): array
    {
        $totals = ['subtotalCents' => 0, 'discountCents' => 0, 'taxCents' => 0, 'totalCents' => 0];

        foreach ($lines as $line) {
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $line[$key];
            }
        }

        return $totals;
    }

    /**
     * Récapitulatif de TVA PAR TAUX — mention obligatoire en pied de document
     * (§3). Trié par taux croissant pour que l'ordre du tableau soit stable
     * d'un document à l'autre.
     *
     * @param  list<array{taxRate: string, subtotalCents: int, taxCents: int}>  $lines
     * @return list<array{rate: string, baseCents: int, taxCents: int}>
     */
    public function taxSummary(array $lines): array
    {
        /** @var array<string, array{rate: string, baseCents: int, taxCents: int}> $byRate */
        $byRate = [];

        foreach ($lines as $line) {
            // Clé normalisée : « 20 » et « 20.00 » désignent le même taux et ne
            // doivent pas produire deux lignes de récapitulatif.
            $key = number_format((float) $line['taxRate'], 2, '.', '');

            $byRate[$key] ??= ['rate' => $key, 'baseCents' => 0, 'taxCents' => 0];
            $byRate[$key]['baseCents'] += $line['subtotalCents'];
            $byRate[$key]['taxCents'] += $line['taxCents'];
        }

        ksort($byRate, SORT_NUMERIC);

        return array_values($byRate);
    }

    /**
     * Division entière avec arrondi commercial (au plus proche, 0,5 vers le
     * haut). Le numérateur est toujours positif ici, cf. l'en-tête de classe.
     */
    private static function divideRounded(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }

    /**
     * Convertit une grandeur décimale en entier d'échelle fixe.
     *
     * Le passage par `round()` sur un float est sans danger ICI, et seulement
     * ici : la valeur d'entrée porte au plus 3 décimales (contrainte
     * `decimal(12,3)` en base, `decimal(5,2)` pour les taux), et un double IEEE
     * 754 représente ces ordres de grandeur bien en deçà de son seuil de perte
     * de précision. C'est la dernière opération flottante de la chaîne : tout
     * ce qui suit est entier.
     */
    private static function toScaledInt(string|float|int $value, int $scale): int
    {
        return (int) round((float) $value * $scale);
    }
}
