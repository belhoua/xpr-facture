<?php

declare(strict_types=1);

namespace App\Modules\Documents\Enums;

use App\Modules\Accounting\Enums\DocumentType;

/**
 * États d'un document commercial. Valeurs miroir de `documents_status_check`.
 *
 * Un SEUL jeu d'états pour les 8 types, mais pas tous ouverts à tous : un devis
 * ne se règle pas partiellement, une facture ne s'« accepte » pas. C'est
 * `allowedFor()` qui porte cette matrice — et elle fait autorité, aussi bien
 * pour la validation d'entrée que pour ce que propose l'interface.
 */
enum DocumentStatus: string
{
    /** Brouillon : modifiable, sans numéro. Le seul état où l'on peut supprimer. */
    case Draft = 'draft';

    /** Émis : numéroté, gelé, parti chez le tiers. */
    case Sent = 'sent';

    /** Devis accepté par le client. */
    case Accepted = 'accepted';

    /** Devis refusé. Terminal — un nouveau devis se crée, on ne « rouvre » pas. */
    case Refused = 'refused';

    /** Devis concrétisé en facture. Terminal, et posé automatiquement. */
    case Converted = 'converted';

    /**
     * En cours : l'affaire est ouverte, le règlement n'est pas encore attendu.
     *
     * État d'AVANCEMENT et non de rédaction — à ne pas confondre avec `Draft`,
     * qui signifie « pas encore numéroté ». Un document `in_progress` porte son
     * numéro. Propre aux situations à ce jour (chantier en cours, décompte non
     * clos).
     */
    case InProgress = 'in_progress';

    /** Facture partiellement réglée. */
    case Partial = 'partial';

    /** Facture intégralement réglée. */
    case Paid = 'paid';

    /** Facture échue et non réglée. */
    case Overdue = 'overdue';

    /** Annulé. Terminal. La trace reste en base, jamais de DELETE (§3). */
    case Cancelled = 'cancelled';

    /**
     * États atteignables pour ce type de document, brouillon compris.
     *
     * @return list<self>
     */
    public static function allowedFor(DocumentType $type): array
    {
        return match ($type) {
            // Le cycle commercial d'une proposition : envoyée, puis acceptée,
            // refusée, ou transformée.
            DocumentType::Quote => [
                self::Draft, self::Sent, self::Accepted,
                self::Refused, self::Converted, self::Cancelled,
            ],

            // Le cycle d'encaissement. `partial` et `paid` seront posés par le
            // module Encaissements ; ils restent réglables à la main d'ici là.
            DocumentType::Invoice, DocumentType::PurchaseInvoice => [
                self::Draft, self::Sent, self::Partial,
                self::Paid, self::Overdue, self::Cancelled,
            ],

            // La situation suit un cycle d'encaissement, comme la facture, mais
            // sans `overdue` : elle n'a pas d'échéance opposable — c'est un état
            // d'avancement, pas une créance exigible à date.
            //
            // Quatre états lui sont offerts à l'écran : `sent` (non payé),
            // `in_progress` (en cours), `partial` et `paid`. Ils sont DÉDUITS
            // du montant réglé par défaut, mais l'utilisateur peut les fixer
            // lui-même — `in_progress` n'étant, lui, jamais déductible : aucun
            // montant ne dit qu'un chantier est en cours.
            DocumentType::Situation => [
                self::Draft, self::Sent, self::InProgress,
                self::Partial, self::Paid, self::Cancelled,
            ],

            // Types dérivés : émis, puis validés par le tiers. Pas de notion
            // de règlement — ils ne portent pas de créance.
            DocumentType::Proforma, DocumentType::PurchaseOrder,
            DocumentType::DeliveryNote, DocumentType::ShippingSlip => [
                self::Draft, self::Sent, self::Accepted, self::Cancelled,
            ],
        };
    }

    /**
     * États que l'utilisateur peut viser via l'endpoint de changement d'état.
     *
     * `draft` en est exclu : un document émis porte un numéro fiscal, le
     * ramener au brouillon le laisserait modifiable — l'immuabilité tomberait
     * (§3). `cancelled` et `converted` en sont exclus aussi, mais pour une
     * autre raison : ils ont chacun leur endpoint, qui applique des règles
     * propres (l'un clôt la pièce et interdit toute réouverture, l'autre crée
     * la facture fille).
     *
     * @return list<self>
     */
    public static function manuallyAssignableFor(DocumentType $type): array
    {
        return array_values(array_filter(
            self::allowedFor($type),
            static fn (self $status): bool => ! in_array(
                $status,
                [self::Draft, self::Cancelled, self::Converted],
                strict: true,
            ),
        ));
    }

    /** États terminaux : plus aucune transition n'en part. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Refused, self::Converted], strict: true);
    }

    /**
     * Un document validé est gelé (§3) : ni édition, ni suppression. Seul le
     * brouillon échappe à la règle, parce qu'il n'a pas encore de numéro.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
