<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Enums\DocumentType;
use App\Modules\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compteur de numérotation d'un (société × type × exercice).
 *
 * L'allocation d'un numéro ne se fait JAMAIS via ce modèle directement : elle
 * exige un verrou de ligne dans la transaction qui valide le document. Passer
 * par DocumentNumberService, seul point d'entrée.
 *
 * @property string $id
 * @property string $company_id
 * @property string $fiscal_year_id
 * @property DocumentType $document_type
 * @property string $format
 * @property int $next_number
 */
final class Sequence extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $fillable = [
        'fiscal_year_id',
        'document_type',
        'format',
        'next_number',
    ];

    /** @return BelongsTo<FiscalYear, $this> */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * Applique le format au numéro : {YYYY}, {YY}, {MM} et un groupe de zéros
     * dont la longueur donne le remplissage ({0000} → 0007).
     *
     * L'année vient de l'EXERCICE, pas de la date du jour : un document émis le
     * 2 janvier sur un exercice décalé appartient encore à l'exercice précédent
     * et doit en porter le millésime.
     */
    public function formatNumber(int $number, FiscalYear $fiscalYear): string
    {
        $result = str_replace(
            ['{YYYY}', '{YY}', '{MM}'],
            [
                $fiscalYear->numberingYear(),
                substr($fiscalYear->numberingYear(), -2),
                $fiscalYear->starts_on->format('m'),
            ],
            $this->format,
        );

        return (string) preg_replace_callback(
            '/\{(0+)\}/',
            static fn (array $matches): string => str_pad(
                (string) $number,
                strlen($matches[1]),
                '0',
                STR_PAD_LEFT,
            ),
            $result,
        );
    }

    /**
     * Rang numérique porté par un numéro déjà attribué, ou `null` s'il ne suit
     * pas le format de cette séquence.
     *
     * Contrepartie de `formatNumber()` : reconstitue le motif attendu pour cet
     * exercice (mêmes substitutions {YYYY}/{YY}/{MM}) et capture le groupe de
     * chiffres. `\d+` et non une largeur figée sur celle du format : un numéro
     * qui a dépassé le remplissage (10000 sur un format à 4 zéros) reste
     * reconnu, `formatNumber()` ne tronque jamais, il ne fait qu'étendre.
     *
     * Un numéro saisi à la main hors de ce format (préfixe différent,
     * millésime d'un autre exercice) rend `null` : on ne devine pas un rang
     * pour un numéro qu'on ne sait pas rattacher à cette séquence.
     */
    public function parseNumber(string $number, FiscalYear $fiscalYear): ?int
    {
        $pattern = preg_quote($this->format, '/');
        $pattern = str_replace(
            [preg_quote('{YYYY}', '/'), preg_quote('{YY}', '/'), preg_quote('{MM}', '/')],
            [
                preg_quote($fiscalYear->numberingYear(), '/'),
                preg_quote(substr($fiscalYear->numberingYear(), -2), '/'),
                preg_quote($fiscalYear->starts_on->format('m'), '/'),
            ],
            $pattern,
        );

        $pattern = preg_replace('/\\\{0+\\\}/', '(\d+)', $pattern, 1);

        if (! is_string($pattern) || ! str_contains($pattern, '(\d+)')) {
            return null;
        }

        if (preg_match('/^'.$pattern.'$/', $number, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'next_number' => 'integer',
        ];
    }
}
