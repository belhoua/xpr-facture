<?php

declare(strict_types=1);

use App\Modules\Accounting\Enums\DocumentType;
use App\Modules\Accounting\Exceptions\NumberingOutsideTransaction;
use App\Modules\Accounting\Services\DocumentNumberService;
use Illuminate\Support\Carbon;

/**
 * Garde de conception de DocumentNumberService : hors transaction, le verrou de
 * ligne ne tient pas et deux validations concurrentes liraient le même
 * compteur.
 *
 * Ce cas vit en Unit et non en Feature parce que RefreshDatabase ouvre une
 * transaction autour de chaque test de Feature : transactionLevel() n'y vaut
 * jamais 0, la garde y serait inobservable. Ici, aucune transaction n'est
 * ouverte et le service refuse avant même de toucher la base.
 */
it('refuse d attribuer un numéro hors transaction', function (): void {
    app(DocumentNumberService::class)->allocate(DocumentType::Invoice, Carbon::today());
})->throws(NumberingOutsideTransaction::class);

it('nomme le type de document dans le message', function (): void {
    $exception = new NumberingOutsideTransaction(DocumentType::CreditNote);

    expect($exception->getMessage())->toContain('credit_note');
});
