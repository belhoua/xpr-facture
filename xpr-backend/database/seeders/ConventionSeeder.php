<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Jeu de démonstration du module Conventions : 8 contrats de convention de
 * contrôle et suivi, et les 8 dépôts de dossier qui les accompagnent — un
 * contrat n'en a aucun (dossier pas encore déposé), un autre en a deux (rejet
 * puis nouveau dépôt).
 *
 * Séparé de `DemoSeeder` (871 lignes) plutôt qu'ajouté dedans : le module a son
 * propre domaine, ses propres tables, et ce fichier doit pouvoir être relancé
 * seul (`db:seed --class=ConventionSeeder`) quand on retouche l'écran des
 * conventions sans vouloir reconstruire tout le reste.
 *
 * Stratégie RLS, identique au reste du dépôt : `conventions` et `file_deposits`
 * portent des policies Row Level Security. Le seeder n'a pas de requête HTTP,
 * donc pas de middleware `tenant` pour armer le contexte — on pose
 * `SET LOCAL app.company_id` dans une transaction avant chaque lot, exactement
 * comme le fait `SetTenantContext` en production. Et on écrit par le query
 * builder, non par l'ORM, pour ne pas déclencher le global scope
 * `BelongsToCompany` qui exigerait un `TenantContext` non chargé ici.
 *
 * `source_document_id` reste NUL sur tous ces contrats, délibérément : les
 * factures de démonstration sont émises pour des clients tirés au hasard, et
 * les rattacher afficherait « issu du document FAC-2026-0007 » sur un contrat
 * établi au nom de quelqu'un d'autre. Le lien se crée en situation réelle, par
 * l'action « Transférer en Contrat de Convention » depuis un devis.
 */
final class ConventionSeeder extends Seeder
{
    /** ICE de la société principale de démonstration (cf. DemoSeeder). */
    private const MAIN_COMPANY_ICE = '001234567890123';

    /**
     * Contrats de démonstration.
     *
     * Ce que le jeu couvre volontairement :
     *  - les QUATRE états du cycle (`draft`, `sent`, `signed`, `cancelled`) ;
     *  - un contrat SANS n° de dossier, pour éprouver l'affichage « Non
     *    déposé » et la reprise automatique du numéro au premier dépôt ;
     *  - un dossier REJETÉ puis redéposé sous une nouvelle référence — c'est le
     *    seul cas qui justifie que les dépôts soient une table fille ;
     *  - des honoraires de 84 000 à 248 000 MAD TTC, tous ventilés 25/25/50.
     *
     * Le TITRE FONCIER a sa propre colonne et n'est PAS recopié dans la
     * description : le contrat imprimé le compose lui-même (« …, sis à : …,
     * — TF : 138618/04 »). L'écrire aux deux endroits ferait diverger les deux
     * copies à la première correction.
     */
    private const CONVENTIONS = [
        [
            'dossier_number' => '0003439/AK/26',
            'status' => 'signed',
            'issued_at' => '2026-07-15',
            'issue_city' => 'MARRAKECH',
            'owner_name' => 'SOCIETE CLINIQUE LA VALLEE',
            'owner_ice' => '001234567890012',
            'owner_rc' => 'Marrakech n° 32577',
            'owner_address' => 'Domaine de la Chérifa, route d\'Amizmiz, Marrakech',
            'project_description' => "CONSTRUCTION D'UNE CLINIQUE POLYCLINIQUE PSYCHIATRIQUE ET D'ADDICTOLOGIE R+1 AVEC 3 SOUS-SOLS",
            'project_address' => 'Propriété dite « Domaine de la Chérifa », route d\'Amizmiz, Marrakech',
            'project_title_deed' => '138618/04',
            'lots' => [
                'Béton armé',
                'Étanchéité des terrasses',
                'Électricité (CFO/CFA) – Ascenseurs',
                'Fluides (Plomberie sanitaire – Évacuation – Climatisation – Chauffage – Ventilation – Désenfumage et Protection incendie)',
            ],
            'execution_delay' => 'Néant',
            'total_cents' => 162_240_00,
            'notes' => null,
            'deposits' => [
                [
                    'reference' => '0003439/AK/26',
                    'organisation' => 'Commune de Marrakech',
                    'deposited_at' => '2026-07-22',
                    'status' => 'validated',
                    'decided_at' => '2026-08-14',
                    'notes' => 'Dossier accepté sans réserve.',
                ],
            ],
        ],
        [
            'dossier_number' => '0001820/MA/26',
            'status' => 'sent',
            'issued_at' => '2026-06-02',
            'issue_city' => 'MARRAKECH',
            'owner_name' => 'PROMOTION IMMOBILIERE AL YASMINE SARL',
            'owner_ice' => '002845019930071',
            'owner_rc' => 'Marrakech n° 48120',
            'owner_address' => 'Lotissement Al Massar, îlot 14, Marrakech',
            'project_description' => "RÉSIDENCE D'HABITATION R+4 AVEC SOUS-SOL",
            'project_address' => 'Lotissement Al Massar, îlot 14, Marrakech',
            'project_title_deed' => '45210/02',
            'lots' => [
                'Béton armé',
                'Étanchéité des terrasses',
                'Électricité (CFO/CFA)',
                'Plomberie sanitaire et évacuation',
            ],
            'execution_delay' => '18 mois à compter de l\'ordre de service',
            'total_cents' => 118_800_00,
            'notes' => 'En attente de la signature du gérant.',
            'deposits' => [
                [
                    'reference' => '0001820/MA/26',
                    'organisation' => 'Agence Urbaine de Marrakech',
                    'deposited_at' => '2026-06-15',
                    'status' => 'in_progress',
                    'decided_at' => null,
                    'notes' => 'Instruction en cours, passage en commission annoncé.',
                ],
            ],
        ],
        [
            'dossier_number' => '0002945/OU/26',
            'status' => 'signed',
            'issued_at' => '2026-03-28',
            'issue_city' => 'OUJDA',
            'owner_name' => 'GROUPE IMMOBILIER BENNANI',
            'owner_ice' => '000917452280036',
            'owner_rc' => 'Oujda n° 27341',
            'owner_address' => '8, Bd Mohammed V, Oujda',
            'project_description' => "CONSTRUCTION D'UN ENSEMBLE COMMERCIAL R+2 AVEC PARKING SOUTERRAIN",
            'project_address' => 'Angle Bd Derfoufi et rue Tarik Ibn Ziad, Oujda',
            'project_title_deed' => '90412/06',
            'lots' => [
                'Béton armé',
                'Charpente métallique',
                'Étanchéité des terrasses',
                'Fluides (Climatisation – Ventilation – Protection incendie)',
            ],
            'execution_delay' => '24 mois à compter de l\'ordre de service',
            'total_cents' => 205_000_00,
            'notes' => null,
            // Le cas qui justifie la table fille : refus, puis nouveau dépôt
            // sous une AUTRE référence. Le n° de dossier du contrat, lui, reste
            // celui du premier dépôt.
            'deposits' => [
                [
                    'reference' => '0002945/OU/26',
                    'organisation' => 'Agence Urbaine d\'Oujda',
                    'deposited_at' => '2026-04-09',
                    'status' => 'rejected',
                    'decided_at' => '2026-05-02',
                    'notes' => 'Rejet : hauteur sous plafond du sous-sol non conforme au règlement.',
                ],
                [
                    'reference' => '0003310/OU/26',
                    'organisation' => 'Agence Urbaine d\'Oujda',
                    'deposited_at' => '2026-05-20',
                    'status' => 'validated',
                    'decided_at' => '2026-06-30',
                    'notes' => 'Redéposé après reprise des plans du sous-sol.',
                ],
            ],
        ],
        [
            'dossier_number' => '0004112/CA/26',
            'status' => 'draft',
            'issued_at' => '2026-07-24',
            'issue_city' => 'CASABLANCA',
            'owner_name' => 'SOCIETE ATLAS BATIMENT SARL AU',
            'owner_ice' => '003128760450099',
            'owner_rc' => 'Casablanca n° 501233',
            'owner_address' => 'Zone industrielle de Sidi Bernoussi, lot 212, Casablanca',
            'project_description' => "EXTENSION D'UNE UNITÉ INDUSTRIELLE AVEC MEZZANINE TECHNIQUE",
            'project_address' => 'Zone industrielle de Sidi Bernoussi, lot 212, Casablanca',
            'project_title_deed' => '77903/12',
            'lots' => [
                'Béton armé',
                'Charpente métallique',
                'Protection incendie',
            ],
            'execution_delay' => '10 mois à compter de l\'ordre de service',
            'total_cents' => 96_500_00,
            'notes' => 'Devis en cours de négociation — contrat non transmis.',
            'deposits' => [
                [
                    'reference' => '0004112/CA/26',
                    'organisation' => 'Protection Civile — Casablanca',
                    'deposited_at' => '2026-07-30',
                    'status' => 'deposited',
                    'decided_at' => null,
                    'notes' => 'Dossier déposé au guichet, récépissé en attente de retrait.',
                ],
            ],
        ],
        [
            'dossier_number' => '0000734/OU/26',
            'status' => 'sent',
            'issued_at' => '2026-03-02',
            'issue_city' => 'OUJDA',
            'owner_name' => 'COOPERATIVE AGRICOLE ANNAKHIL',
            'owner_ice' => '001560234770018',
            'owner_rc' => 'Oujda n° 19087',
            'owner_address' => 'Route de Taourirt, km 7, Oujda',
            'project_description' => "CONSTRUCTION D'UN DÉPÔT FRIGORIFIQUE ET D'UN BÂTIMENT ADMINISTRATIF",
            'project_address' => 'Route de Taourirt, km 7, Oujda',
            'project_title_deed' => '21877/03',
            'lots' => [
                'Béton armé',
                'Étanchéité des terrasses',
                'Électricité (CFO/CFA)',
            ],
            'execution_delay' => '12 mois à compter de l\'ordre de service',
            'total_cents' => 84_000_00,
            'notes' => null,
            'deposits' => [
                [
                    'reference' => '0000734/OU/26',
                    'organisation' => 'Commune d\'Oujda',
                    'deposited_at' => '2026-03-11',
                    'status' => 'validated',
                    'decided_at' => '2026-04-05',
                    'notes' => null,
                ],
            ],
        ],
        [
            // Aucun n° de dossier : le contrat est rédigé, le dossier n'a pas
            // encore été déposé. C'est ce cas que la liste affiche « Non
            // déposé », et celui où le premier dépôt reportera son numéro.
            'dossier_number' => null,
            'status' => 'draft',
            'issued_at' => '2026-08-01',
            'issue_city' => 'MARRAKECH',
            'owner_name' => 'RESIDENCE DAR SALAM SARL',
            'owner_ice' => '004472019860025',
            'owner_rc' => 'Marrakech n° 55901',
            'owner_address' => 'Route de l\'Ourika, km 9, Marrakech',
            'project_description' => "CONSTRUCTION D'UNE VILLA R+1 AVEC PISCINE ET DÉPENDANCES",
            'project_address' => 'Route de l\'Ourika, km 9, Marrakech',
            'project_title_deed' => '60118/04',
            'lots' => [
                'Béton armé',
                'Étanchéité des terrasses',
            ],
            'execution_delay' => 'Néant',
            'total_cents' => 88_000_00,
            'notes' => 'Contrat à faire signer avant dépôt du dossier.',
            'deposits' => [],
        ],
        [
            'dossier_number' => '0003877/MA/26',
            'status' => 'signed',
            'issued_at' => '2026-06-20',
            'issue_city' => 'MARRAKECH',
            'owner_name' => 'SOCIETE HOTELIERE RIAD ANNOUR',
            'owner_ice' => '002019873440064',
            'owner_rc' => 'Marrakech n° 41220',
            'owner_address' => 'Derb Sidi Bouloukat, Médina, Marrakech',
            'project_description' => "RÉHABILITATION D'UN RIAD EN MAISON D'HÔTES AVEC RENFORCEMENT DE STRUCTURE",
            'project_address' => 'Derb Sidi Bouloukat, Médina, Marrakech',
            'project_title_deed' => '12045/01',
            'lots' => [
                'Béton armé et renforcement de structure',
                'Étanchéité des terrasses',
                'Électricité (CFO/CFA)',
                'Protection incendie',
            ],
            'execution_delay' => '14 mois à compter de l\'ordre de service',
            'total_cents' => 132_500_00,
            'notes' => null,
            'deposits' => [
                [
                    'reference' => '0003877/MA/26',
                    'organisation' => 'Protection Civile — Marrakech',
                    'deposited_at' => '2026-07-01',
                    'status' => 'in_progress',
                    'decided_at' => null,
                    'notes' => 'Visite de conformité programmée.',
                ],
            ],
        ],
        [
            'dossier_number' => '0002201/CA/26',
            'status' => 'cancelled',
            'issued_at' => '2026-02-05',
            'issue_city' => 'CASABLANCA',
            'owner_name' => 'PROMOTION AL BOUSTANE SA',
            'owner_ice' => '005230119970042',
            'owner_rc' => 'Casablanca n° 388741',
            'owner_address' => '120, Bd Zerktouni, Casablanca',
            'project_description' => 'CONSTRUCTION DE 3 IMMEUBLES R+5 À USAGE MIXTE',
            'project_address' => 'Lotissement Ennakhil, tranche 2, Casablanca',
            'project_title_deed' => '88320/09',
            'lots' => [
                'Béton armé',
                'Étanchéité des terrasses',
                'Électricité (CFO/CFA) – Ascenseurs',
                'Fluides (Plomberie sanitaire – Évacuation – Climatisation)',
            ],
            'execution_delay' => '30 mois à compter de l\'ordre de service',
            'total_cents' => 248_000_00,
            'notes' => 'Convention annulée : projet abandonné par le maître d\'ouvrage.',
            'deposits' => [
                [
                    'reference' => '0002201/CA/26',
                    'organisation' => 'Agence Urbaine de Casablanca',
                    'deposited_at' => '2026-02-18',
                    'status' => 'rejected',
                    'decided_at' => '2026-03-25',
                    'notes' => 'Rejet : coefficient d\'occupation du sol dépassé.',
                ],
            ],
        ],
    ];

    public function run(): void
    {
        $companyId = $this->mainCompanyId();

        if ($companyId === null) {
            $this->command->warn('⏭  ConventionSeeder ignoré : aucune société de démonstration en base.');

            return;
        }

        // Idempotence (cf. docblock de DatabaseSeeder : `db:seed` doit rester
        // rejouable). Les conventions n'ont pas toutes une clé naturelle — l'un
        // des contrats de démonstration n'a volontairement pas de n° de dossier
        // — on ne re-seede donc pas une société déjà pourvue plutôt que de
        // risquer des doublons.
        if (DB::table('conventions')->where('company_id', $companyId)->exists()) {
            $this->command->info('⏭  ConventionSeeder ignoré : la société a déjà des conventions.');

            return;
        }

        $now = now();
        $conventionRows = [];
        $depositRows = [];

        foreach (self::CONVENTIONS as $convention) {
            $conventionId = Str::uuid7()->toString();

            $conventionRows[] = [
                'id' => $conventionId,
                'company_id' => $companyId,
                'source_document_id' => null,
                'partner_id' => null,
                'dossier_number' => $convention['dossier_number'],
                'status' => $convention['status'],
                'issue_city' => $convention['issue_city'],
                'issued_at' => $convention['issued_at'],
                'owner_name' => $convention['owner_name'],
                'owner_ice' => $convention['owner_ice'],
                'owner_rc' => $convention['owner_rc'],
                'owner_address' => $convention['owner_address'],
                'project_description' => $convention['project_description'],
                'project_address' => $convention['project_address'],
                'project_title_deed' => $convention['project_title_deed'],
                // jsonb : la colonne attend du JSON, pas un tableau PHP — le
                // query builder n'a pas les casts de l'ORM pour le faire.
                'lots' => json_encode($convention['lots'], JSON_THROW_ON_ERROR),
                'execution_delay' => $convention['execution_delay'],
                'total_cents' => $convention['total_cents'],
                'currency' => 'MAD',
                // Les modalités du modèle client. Écrites explicitement plutôt
                // que laissées aux défauts de la colonne : c'est une donnée du
                // contrat, et un jeu de démonstration doit montrer ce que
                // l'écran affiche, pas ce que la base suppose.
                'advance_percent' => 25,
                'visa_percent' => 25,
                'completion_percent' => 50,
                'notes' => $convention['notes'],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            foreach ($convention['deposits'] as $deposit) {
                $depositRows[] = [
                    'id' => Str::uuid7()->toString(),
                    'company_id' => $companyId,
                    'convention_id' => $conventionId,
                    'reference' => $deposit['reference'],
                    'deposited_at' => $deposit['deposited_at'],
                    'organisation' => $deposit['organisation'],
                    'status' => $deposit['status'],
                    'decided_at' => $deposit['decided_at'],
                    'notes' => $deposit['notes'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Contournement RLS : SET LOCAL dans une transaction dédiée. Les deux
        // insertions y sont ENSEMBLE — un dépôt orphelin de sa convention
        // violerait la clé étrangère, et la transaction garantit que le jeu
        // arrive entier ou pas du tout.
        DB::transaction(function () use ($companyId, $conventionRows, $depositRows): void {
            DB::statement("SET LOCAL app.company_id = '{$companyId}'");
            DB::table('conventions')->insert($conventionRows);
            DB::table('file_deposits')->insert($depositRows);
        });

        $this->command->info('✅ ConventionSeeder terminé.');
        $this->command->table(
            ['Ressource', 'Quantité'],
            [
                ['Conventions', count($conventionRows)],
                ['Dépôts de dossier', count($depositRows)],
            ],
        );
    }

    /**
     * Société principale de démonstration, résolue par son ICE.
     *
     * Par l'ICE et non par « la première société venue » : `DemoSeeder` en crée
     * deux, et les conventions appartiennent au bureau de contrôle, pas au
     * bureau d'études secondaire. Rend `null` si la démo n'a pas tourné — le
     * seeder s'abstient alors au lieu d'échouer sur une FK.
     */
    private function mainCompanyId(): ?string
    {
        $id = DB::table('companies')
            ->where('ice', self::MAIN_COMPANY_ICE)
            ->value('id');

        return is_string($id) ? $id : null;
    }
}
