<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stockage des scans de chèques et de LCN.
 *
 * Premier upload du dépôt : ce fichier pose donc les règles du §10, que les
 * suivants reprendront.
 *
 *  - **Hors webroot.** Le disque `local` pointe sur `storage/app/private`,
 *    qu'aucune URL ne sert. Le seul chemin de lecture est un endpoint
 *    authentifié qui vérifie la société (cf. `PaymentScanController`) — un
 *    chèque scanné porte un RIB, un nom et une signature.
 *  - **Nom ALÉATOIRE.** Le nom d'origine n'est jamais utilisé comme nom de
 *    fichier : il permettrait de deviner l'adresse d'un scan voisin, et il
 *    porte les traversées de répertoire (`../`) que l'on refuse d'avoir à
 *    filtrer. Il est conservé à part, en base, pour être proposé au
 *    téléchargement.
 *  - **Cloisonné par société.** Le préfixe `payments/{company_id}/` fait que
 *    deux sociétés n'écrivent jamais dans le même dossier. Ce n'est pas la
 *    protection — c'est l'endpoint qui la porte — mais une fuite éventuelle
 *    resterait bornée à une société.
 *
 * L'EXTENSION vient du type MIME réel (`extension()` lit le contenu), pas de ce
 * que le client déclare : un exécutable renommé `.pdf` serait stocké tel quel
 * sinon, et servi plus tard sous son faux type.
 */
final class PaymentScanStorage
{
    private const DISK = 'local';

    private const DIRECTORY = 'payments';

    /**
     * Écrit le fichier et rend de quoi le retrouver.
     *
     * @return array{path: string, name: string}
     */
    public function store(UploadedFile $file, string $companyId): array
    {
        $extension = $file->extension() ?: 'bin';
        $name = Str::uuid7()->toString().'.'.$extension;

        $path = $this->disk()->putFileAs(
            self::DIRECTORY.'/'.$companyId,
            $file,
            $name,
        );

        return [
            'path' => is_string($path) ? $path : self::DIRECTORY.'/'.$companyId.'/'.$name,
            // Tronqué : le nom d'origine sert d'étiquette au téléchargement,
            // pas de clé. Une chaîne sans borne remplirait la colonne.
            'name' => Str::limit($file->getClientOriginalName(), 120, ''),
        ];
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    /** Réponse de téléchargement, construite par le contrôleur autorisé. */
    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
