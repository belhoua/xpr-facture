<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Une ligne dépasse les bornes de calcul de DocumentCalculator.
 *
 * 422 et non 500 : la valeur vient d'une saisie, et les FormRequests posent
 * déjà les mêmes bornes. Cette exception n'est atteinte que par un appelant
 * interne qui court-circuiterait la validation HTTP — un import, un seeder,
 * un job. Le message doit donc rester lisible par un développeur ET par un
 * utilisateur, d'où le passage par __().
 */
final class LineAmountOutOfRange extends UnprocessableEntityHttpException
{
    public static function quantity(string|float|int $value): self
    {
        return new self(__('Quantity :value is outside the supported range.', [
            'value' => (string) $value,
        ]));
    }

    public static function unitPrice(int $cents): self
    {
        return new self(__('Unit price :value is outside the supported range.', [
            'value' => (string) $cents,
        ]));
    }
}
