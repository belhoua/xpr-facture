<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Services;

use App\Modules\Accounting\Enums\DocumentType;
use App\Modules\Conventions\Enums\ConventionStatus;
use App\Modules\Conventions\Models\Convention;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\Document;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Transfert **devis / facture → contrat de convention**.
 *
 * Ce n'est PAS une conversion au sens de `DocumentConversionService` : il n'y a
 * ni copie de lignes, ni reprise de TVA, ni changement d'état du document
 * source. Le devis reste un devis et poursuit son cycle — il peut encore être
 * converti en facture après avoir engendré la convention, ce qui est même le
 * cas courant : on signe la convention, puis on facture l'avance.
 *
 * Ce que le transfert fait réellement : il ÉPARGNE UNE RESSAISIE. Le maître
 * d'ouvrage, le projet et le forfait d'honoraires sont déjà sur le devis ; les
 * recopier à la main, c'est trois occasions de se tromper sur un ICE.
 *
 * La convention produite est un BROUILLON : tout ce qui n'existe pas sur un
 * devis — titre foncier, lots réellement contrôlés, délai d'exécution — reste à
 * compléter avant de la transmettre au client.
 */
final class ConventionDraftingService
{
    /**
     * Lots de l'article 1, tels que le modèle client les énumère.
     *
     * Point de DÉPART, pas une règle : chaque convention porte ensuite ses
     * propres lots (colonne `lots`), qu'on ajuste au projet. C'est bien ce
     * qu'on ajuste le plus souvent — une villa n'a ni ascenseur ni
     * désenfumage — d'où le pré-remplissage : retirer deux lignes est plus
     * rapide que d'en saisir quatre.
     *
     * @var list<string>
     */
    private const DEFAULT_LOTS = [
        'Béton armé',
        'Étanchéité des terrasses',
        'Électricité (CFO/CFA) – Ascenseurs',
        'Fluides (Plomberie sanitaire – Évacuation – Climatisation – Chauffage – Ventilation – Désenfumage et Protection incendie)',
    ];

    /** Modalités de règlement de l'article 10 : 25 % / 25 % / 50 %. */
    private const DEFAULT_ADVANCE_PERCENT = 25;

    private const DEFAULT_VISA_PERCENT = 25;

    private const DEFAULT_COMPLETION_PERCENT = 50;

    public function fromDocument(Document $document): Convention
    {
        $this->assertTransferable($document);

        // Le tiers complète ce que le document ne porte pas : un devis fige le
        // nom, l'ICE et l'adresse du client, mais pas son registre de commerce —
        // que la convention, elle, doit citer nommément.
        $partner = $document->partner()->first();

        $convention = new Convention([
            'source_document_id' => $document->id,
            'partner_id' => $document->partner_id,

            'status' => ConventionStatus::Draft->value,
            'issue_city' => $document->issue_city,
            // La convention est établie AUJOURD'HUI, pas à la date du devis :
            // c'est un acte distinct, souvent postérieur de plusieurs semaines.
            'issued_at' => Carbon::today(),

            'owner_name' => $document->client_name,
            'owner_ice' => $document->client_ice ?? $partner?->ice,
            'owner_rc' => $partner?->rc_number,
            'owner_address' => $document->client_address ?? $partner?->address,

            // L'objet du devis EST la désignation du projet chez un bureau de
            // contrôle (« Contrôle technique de la construction d'une… »). À
            // défaut, la chaîne vide : la convention naît incomplète et son
            // formulaire exigera ce champ avant tout enregistrement, plutôt que
            // d'inventer un libellé que personne n'a écrit.
            'project_description' => $document->subject ?? '',
            // Seule adresse que porte un document commercial. C'est celle du
            // chantier dans la pratique de BCAT, mais rien ne le garantit : elle
            // est proposée pour être relue, pas pour être tenue pour vraie.
            'project_address' => $document->client_address,
            // Le titre foncier n'existe sur aucun document commercial : il reste
            // à saisir.
            'project_title_deed' => null,

            'lots' => self::DEFAULT_LOTS,

            // Le forfait de l'article 10 est le TTC du document : c'est la somme
            // que le maître d'ouvrage s'engage à verser, celle qui s'écrit en
            // toutes lettres dans le contrat.
            'total_cents' => $document->total_cents,
            'currency' => $document->currency,
            'advance_percent' => self::DEFAULT_ADVANCE_PERCENT,
            'visa_percent' => self::DEFAULT_VISA_PERCENT,
            'completion_percent' => self::DEFAULT_COMPLETION_PERCENT,
        ]);

        $convention->save();

        return $convention->refresh()->load(['deposits', 'sourceDocument']);
    }

    /**
     * Seuls un DEVIS et une FACTURE portent des honoraires convenus avec le
     * maître d'ouvrage. Une situation en suit l'avancement, un bon de livraison
     * n'en parle pas : ni l'une ni l'autre ne peut fonder un contrat.
     */
    private function assertTransferable(Document $document): void
    {
        if (! in_array($document->type, [DocumentType::Quote, DocumentType::Invoice], strict: true)) {
            throw new ConflictHttpException(__('Only a quote or an invoice can be transferred into a convention.'));
        }

        // Annulé ou refusé : le document n'engage plus rien, fonder un contrat
        // sur ses montants reviendrait à réclamer un accord qui n'existe pas.
        //
        // Deux états volontairement ACCEPTÉS, là où `isTerminal()` les
        // refuserait tous les trois :
        //  - le BROUILLON — la convention précède fréquemment l'émission du
        //    devis, et rien dans le contrat ne dépend d'un numéro fiscal ;
        //  - le devis CONVERTI — il a produit une facture, c'est le signe que
        //    l'affaire est conclue : le moment exact où l'on rédige la
        //    convention.
        if (in_array($document->status, [DocumentStatus::Cancelled, DocumentStatus::Refused], strict: true)) {
            throw new ConflictHttpException(__('A cancelled or refused document cannot found a convention.'));
        }
    }
}
