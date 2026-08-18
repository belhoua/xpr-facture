<?php

declare(strict_types=1);

namespace App\Modules\Payments\Controllers;

use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentScanStorage;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sert le scan d'un chèque ou d'une LCN.
 *
 * SEUL chemin de lecture des fichiers déposés : le disque est hors webroot,
 * aucune URL ne l'expose. Trois gardes, dans cet ordre :
 *
 *  1. le règlement est résolu SOUS LE SCOPE TENANT — une société ne peut pas
 *     lire le chèque d'une autre, et l'inexistence répond 404, jamais 403 ;
 *  2. la route porte la permission de lecture des règlements, comme toutes les
 *     autres (§10) ;
 *  3. le fichier est diffusé en flux depuis le disque privé, sous son nom
 *     d'ORIGINE — jamais sous le nom de stockage, qui est un UUID sans
 *     signification pour l'utilisateur.
 *
 * Un chèque scanné porte un RIB, un nom et une signature manuscrite : c'est la
 * donnée la plus sensible que ce module manipule.
 */
final class PaymentScanController
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentScanStorage $scans,
    ) {}

    public function __invoke(string $payment): StreamedResponse
    {
        $model = $this->payments->findForCompany($payment);

        // Une ligne qui désigne un fichier absent est un défaut de données, pas
        // une autorisation : on répond 404 plutôt que de laisser remonter une
        // erreur de flux illisible.
        if ($model->scan_path === null || ! $this->scans->exists($model->scan_path)) {
            throw (new ModelNotFoundException)->setModel(Payment::class, [$payment]);
        }

        return $this->scans->disk()->download(
            $model->scan_path,
            $model->scan_name ?? basename($model->scan_path),
        );
    }
}
