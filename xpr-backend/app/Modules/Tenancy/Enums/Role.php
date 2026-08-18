<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Enums;

/**
 * Rôles applicatifs, attribués PAR SOCIÉTÉ (mode teams de Spatie) : être owner
 * chez A ne donne aucun droit chez B.
 *
 * La matrice ci-dessous est la source de vérité des droits par défaut. Elle est
 * volontairement explicite plutôt que hiérarchique (« admin hérite de sales ») :
 * une hiérarchie rend invisible ce qu'un rôle peut réellement faire, et c'est
 * précisément la question qu'on se pose lors d'un audit.
 */
enum Role: string
{
    /** Créateur de la société. Tous les droits, y compris les paramètres. */
    case Owner = 'owner';

    /** Gère l'exploitation et les collaborateurs, sauf les paramètres légaux. */
    case Admin = 'admin';

    /** Comptable : voit tout, agit sur les documents et la caisse. */
    case Accountant = 'accountant';

    /** Commercial : crée et suit ses documents, ne les annule pas. */
    case Sales = 'sales';

    /** Lecture seule. */
    case Viewer = 'viewer';

    /**
     * Permissions accordées à ce rôle.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),

            // Tout sauf la modification des paramètres : l'identité légale
            // (ICE, IF, RC) engage la société, elle reste au propriétaire.
            // Boucle explicite plutôt qu'array_filter, qui préserve les clés
            // et ne produirait donc pas la `list` annoncée.
            self::Admin => self::allExcept(Permission::SettingsUpdate),

            self::Accountant => [
                Permission::DashboardView,
                Permission::PartnersView,
                Permission::PartnersCreate,
                Permission::PartnersUpdate,
                Permission::PartnersDelete,
                Permission::CatalogView,
                Permission::CatalogCreate,
                Permission::CatalogUpdate,
                Permission::CatalogDelete,
                Permission::DocumentsView,
                Permission::DocumentsCreate,
                Permission::DocumentsUpdate,
                Permission::DocumentsDelete,
                Permission::DocumentsIssue,
                Permission::DocumentsCancel,
                Permission::ConventionsView,
                Permission::ConventionsCreate,
                Permission::ConventionsUpdate,
                Permission::ConventionsDelete,
                Permission::DepositsView,
                Permission::DepositsManage,
                Permission::ProjectsView,
                Permission::ProjectsCreate,
                Permission::ProjectsUpdate,
                Permission::ProjectsDelete,
                Permission::ServicesView,
                Permission::ServicesManage,
                Permission::CashView,
                Permission::CashManage,
                Permission::PaymentsView,
                Permission::PaymentsManage,
                Permission::UsersView,
                Permission::AdminNotesView,
                Permission::AdminNotesCreate,
                Permission::SettingsView,
            ],

            // Ni suppression ni annulation : ce sont des actes fiscaux, ils
            // relèvent du comptable ou de la direction.
            self::Sales => [
                Permission::DashboardView,
                Permission::PartnersView,
                Permission::PartnersCreate,
                Permission::PartnersUpdate,
                Permission::CatalogView,
                Permission::CatalogCreate,
                Permission::CatalogUpdate,
                Permission::DocumentsView,
                Permission::DocumentsCreate,
                Permission::DocumentsUpdate,
                // Émettre est accordé : un commercial doit pouvoir envoyer son
                // devis et sa facture sans attendre une validation comptable.
                // C'est ANNULER et SUPPRIMER qu'on lui refuse — les deux actes
                // qui touchent à un document déjà parti chez le client.
                Permission::DocumentsIssue,
                // Rédiger la convention issue de SON devis, oui ; la SUPPRIMER,
                // non — c'est un contrat signé, il relève de la direction.
                Permission::ConventionsView,
                Permission::ConventionsCreate,
                Permission::ConventionsUpdate,
                // Le suivi du dossier appartient au suivi client : c'est le
                // commercial que l'on appelle pour savoir où il en est.
                Permission::DepositsView,
                Permission::DepositsManage,
                // Le commercial suit l'avancement du projet qu'il a vendu et
                // date ce qu'il remet au client. SUPPRIMER lui est refusé :
                // effacer un suivi ferait disparaître la trace des livrables
                // remis, seule preuve qu'ils l'ont été.
                Permission::ProjectsView,
                Permission::ProjectsCreate,
                Permission::ProjectsUpdate,
                // Lire le référentiel, pas le tenir : le commercial classe son
                // projet sous un service existant, il n'en invente pas.
                Permission::ServicesView,
                Permission::CashView,
                // Encaisser relève du suivi client : c'est le commercial qui
                // reçoit le chèque. Le geste reste réversible — retirer un
                // règlement réaligne la facture — là où annuler une pièce
                // consomme définitivement son numéro.
                Permission::PaymentsView,
                Permission::PaymentsManage,
                Permission::UsersView,
                Permission::AdminNotesView,
                Permission::AdminNotesCreate,
                Permission::SettingsView,
            ],

            self::Viewer => [
                Permission::DashboardView,
                Permission::PartnersView,
                Permission::CatalogView,
                Permission::DocumentsView,
                Permission::ConventionsView,
                Permission::DepositsView,
                Permission::ProjectsView,
                Permission::ServicesView,
                Permission::CashView,
                Permission::PaymentsView,
                Permission::UsersView,
                Permission::AdminNotesView,
                Permission::SettingsView,
            ],
        };
    }

    /**
     * Toutes les permissions sauf celles citées.
     *
     * @return list<Permission>
     */
    private static function allExcept(Permission ...$excluded): array
    {
        $kept = [];

        foreach (Permission::cases() as $permission) {
            if (! in_array($permission, $excluded, strict: true)) {
                $kept[] = $permission;
            }
        }

        return $kept;
    }

    /** @return list<string> */
    public function permissionValues(): array
    {
        return array_map(
            static fn (Permission $permission): string => $permission->value,
            $this->permissions(),
        );
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
