<?php

declare(strict_types=1);

use App\Modules\Documents\Models\Document;
use App\Modules\Partners\Models\Partner;
use App\Modules\Tenancy\Services\TenantContext;

use function Pest\Laravel\actingAs;

/**
 * La SITUATION — 9ᵉ type de `documents`, et seule exception au régime
 * d'immuabilité du §3 (décision du 2026-08-05, cf. docs/modules/situations.md).
 *
 * Trois familles de règles sont éprouvées ici :
 *
 *  1. **Le montant est GLOBAL** : pas de lignes, pas de ventilation de TVA. Le
 *     total est une donnée d'entrée, ce qu'aucun autre type ne permet.
 *  2. **L'état de règlement est DÉDUIT**, jamais choisi : non payé / partiel /
 *     payé se calculent depuis l'avance face au total.
 *  3. **La pièce reste modifiable** une fois numérotée — sa séquence peut donc
 *     présenter des trous, ce qui resterait interdit sur une facture.
 */

/**
 * Payload d'une situation. Le tiers est obligatoire : l'écran « par client »
 * agrège sur `partner_id`.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function situationPayload(string $partnerId, array $overrides = []): array
{
    return array_merge([
        'type' => 'situation',
        'partnerId' => $partnerId,
        'subject' => 'Situation du mois d’octobre',
        'totalCents' => 500_000,
    ], $overrides);
}

/** Un client de la société active, prêt à porter des situations. */
function situationClient(string $companyId): Partner
{
    app(TenantContext::class)->activateCompany($companyId);

    return Partner::factory()->client()->create(['ice' => null]);
}

it('numérote la situation dès sa création, sans étape d’émission', function (): void {
    [$user, $company] = workspaceAccount();
    $client = situationClient($company->id);

    // Contrairement à une facture, qui naît brouillon et sans numéro : la
    // situation n'est pas opposable à la DGI, elle n'a pas d'acte d'émission.
    actingAs($user)
        ->postJson('/api/v1/documents', situationPayload($client->id))
        ->assertCreated()
        ->assertJsonPath('number', 'SIT-2026-0001')
        ->assertJsonPath('subject', 'Situation du mois d’octobre')
        ->assertJsonPath('totalCents', 500_000);
});

it('déduit l’état de règlement du montant de l’avance', function (
    ?int $paid,
    string $expected,
): void {
    [$user, $company] = workspaceAccount();
    $client = situationClient($company->id);

    actingAs($user)
        ->postJson('/api/v1/documents', situationPayload($client->id, [
            'totalCents' => 500_000,
            ...($paid === null ? [] : ['paidCents' => $paid]),
        ]))
        ->assertCreated()
        ->assertJsonPath('status', $expected);
})->with([
    'aucune avance → non payé' => [null, 'sent'],
    'avance nulle → non payé' => [0, 'sent'],
    'avance partielle → partiel' => [200_000, 'partial'],
    'avance égale au total → payé' => [500_000, 'paid'],
]);

it('enregistre l’état choisi par l’utilisateur', function (string $status): void {
    [$user, $company] = workspaceAccount();
    $client = situationClient($company->id);

    // Le choix explicite PRIME sur la déduction : une situation soldée que
    // l'utilisateur déclare « en cours » reste en cours.
    actingAs($user)
        ->postJson('/api/v1/documents', situationPayload($client->id, [
            'totalCents' => 500_000,
            'paidCents' => 500_000,
            'status' => $status,
        ]))
        ->assertCreated()
        ->assertJsonPath('status', $status);
})->with(['sent', 'in_progress', 'partial', 'paid']);

it('déduit l’état quand l’utilisateur n’en impose aucun', function (): void {
    [$user, $company] = workspaceAccount();
    $client = situationClient($company->id);

    // Rétrocompatibilité : sans `status`, le comportement d'origine tient.
    actingAs($user)
        ->postJson('/api/v1/documents', situationPayload($client->id, [
            'totalCents' => 500_000,
            'paidCents' => 250_000,
        ]))
        ->assertCreated()
        ->assertJsonPath('status', 'partial');
});

it('conserve « en cours » quand on modifie autre chose', function (): void {
    [$user, $company] = workspaceAccount();
    $client = situationClient($company->id);

    $id = actingAs($user)
        ->postJson('/api/v1/documents', situationPayload($client->id, [
            'status' => 'in_progress',
        ]))
        ->assertCreated()
        ->json('id');

    // Aucun montant ne dit qu'un chantier est en cours : le réalignement
    // automatique doit épargner cet état, sinon l'utilisateur le perdrait en
    // corrigeant une faute de frappe dans l'objet.
    actingAs($user)
        ->patchJson("/api/v1/documents/{$id}", ['subject' => 'Objet corrigé'])
        ->assertOk()
        ->assertJsonPath('status', 'in_progress');
});

it('change l’état d’une situation en cours de vie', function (): void {
    [$user, $company] = workspaceAccount();
    $client = situationClient($company->id);

    $id = actingAs($user)
        ->postJson('/api/v1/documents', situationPayload($client->id, [
            'status' => 'in_progress',
        ]))
        ->assertCreated()
        ->json('id');

    actingAs($user)
        ->patchJson("/api/v1/documents/{$id}", ['status' => 'sent'])
        ->assertOk()
        ->assertJsonPath('status', 'sent');
});

it('refuse un état hors de la matrice', function (string $status): void {
    [$user, $company] = workspaceAccount();
    $client = situationClient($company->id);

    // `overdue` n'appartient pas au cycle d'une situation (pas d'échéance
    // opposable) ; `draft` la dirait non numérotée alors qu'elle l'est.
    actingAs($user)
        ->postJson('/api/v1/documents', situationPayload($client->id, ['status' => $status]))
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', fn (string $m): bool => $m !== '');
})->with(['overdue', 'draft', 'cancelled', 'accepted', 'n_importe_quoi']);

it('ignore un état transmis sur une facture', function (): void {
    [$user] = workspaceAccount();

    // L'état d'une facture est la conséquence de son cycle : créer une facture
    // déjà « payée » contournerait tout suivi d'encaissement.
    actingAs($user)
        ->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'clientName' => 'Cliente SARL',
            'status' => 'paid',
            'items' => [['label' => 'Prestation', 'quantity' => '1', 'unitPriceCents' => 1000]],
        ])
        ->assertCreated()
        ->assertJsonPath('status', 'draft');
});

it('expose le reste à payer', function (): void {
    [$user, $company] = workspaceAccount();
    $client = situationClient($company->id);

    actingAs($user)
        ->postJson('/api/v1/documents', situationPayload($client->id, [
            'totalCents' => 500_000,
            'paidCents' => 180_000,
        ]))
        ->assertCreated()
        ->assertJsonPath('paidCents', 180_000)
        ->assertJsonPath('remainingCents', 320_000);
});

it('n’applique aucune TVA : le total est le montant brut saisi', function (): void {
    [$user, $company] = workspaceAccount();
    $client = situationClient($company->id);

    // La situation est une pièce de SUIVI, pas une pièce fiscale : elle ne
    // porte ni base taxable ni récapitulatif de TVA.
    actingAs($user)
        ->postJson('/api/v1/documents', situationPayload($client->id))
        ->assertCreated()
        ->assertJsonPath('subtotalCents', 500_000)
        ->assertJsonPath('taxCents', 0)
        ->assertJsonPath('discountCents', 0)
        ->assertJsonPath('totalCents', 500_000)
        ->assertJsonPath('items', []);
});

it('refuse les lignes de détail sur une situation', function (): void {
    [$user, $company] = workspaceAccount();
    $client = situationClient($company->id);

    // Accepter des lignes créerait deux sources de vérité pour le montant :
    // celui saisi en en-tête, et la somme des lignes.
    actingAs($user)
        ->postJson('/api/v1/documents', situationPayload($client->id, [
            'items' => [['label' => 'Prestation', 'quantity' => '1', 'unitPriceCents' => 1000]],
        ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.items.0', fn (string $m): bool => $m !== '');
});

it('exige un tiers, un objet et un montant', function (string $missing): void {
    [$user, $company] = workspaceAccount();
    $client = situationClient($company->id);

    $payload = situationPayload($client->id);
    unset($payload[$missing]);

    actingAs($user)
        ->postJson('/api/v1/documents', $payload)
        ->assertStatus(422)
        ->assertJsonPath("errors.{$missing}.0", fn (string $m): bool => $m !== '');
})->with(['partnerId', 'subject', 'totalCents']);

it('refuse une avance supérieure au montant', function (): void {
    [$user, $company] = workspaceAccount();
    $client = situationClient($company->id);

    actingAs($user)
        ->postJson('/api/v1/documents', situationPayload($client->id, [
            'totalCents' => 100_000,
            'paidCents' => 150_000,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.paidCents.0', fn (string $m): bool => $m !== '');
});

it('reste modifiable une fois numérotée, contrairement à une facture', function (): void {
    [$user, $company] = workspaceAccount();
    $client = situationClient($company->id);

    $id = actingAs($user)
        ->postJson('/api/v1/documents', situationPayload($client->id))
        ->assertCreated()
        ->json('id');

    // C'est LA brèche assumée dans l'immuabilité du §3, bornée au seul type
    // situation par DocumentType::freezesOnIssue().
    actingAs($user)
        ->patchJson("/api/v1/documents/{$id}", ['subject' => 'Situation rectifiée'])
        ->assertOk()
        ->assertJsonPath('subject', 'Situation rectifiée')
        // Le numéro ne bouge pas : corriger n'est pas renuméroter.
        ->assertJsonPath('number', 'SIT-2026-0001');
});

it('réaligne l’état de règlement quand les montants changent', function (): void {
    [$user, $company] = workspaceAccount();
    $client = situationClient($company->id);

    $id = actingAs($user)
        ->postJson('/api/v1/documents', situationPayload($client->id, [
            'totalCents' => 500_000,
            'paidCents' => 200_000,
        ]))
        ->assertCreated()
        ->assertJsonPath('status', 'partial')
        ->json('id');

    // Solder l'avance doit faire basculer le badge : laisser « partiel » sur
    // une ligne intégralement réglée afficherait le contraire des chiffres.
    actingAs($user)
        ->patchJson("/api/v1/documents/{$id}", ['paidCents' => 500_000])
        ->assertOk()
        ->assertJsonPath('status', 'paid')
        ->assertJsonPath('remainingCents', 0);
});

it('refuse en PATCH une avance qui dépasserait le nouveau total', function (): void {
    [$user, $company] = workspaceAccount();
    $client = situationClient($company->id);

    $id = actingAs($user)
        ->postJson('/api/v1/documents', situationPayload($client->id, [
            'totalCents' => 500_000,
            'paidCents' => 400_000,
        ]))
        ->assertCreated()
        ->json('id');

    // Le FormRequest ne voit qu'un champ et n'a rien à comparer : c'est le
    // service qui rattrape, en confrontant la valeur reçue à l'état persisté.
    actingAs($user)
        ->patchJson("/api/v1/documents/{$id}", ['totalCents' => 100_000])
        ->assertStatus(409);
});

it('se supprime malgré son numéro', function (): void {
    [$user, $company] = workspaceAccount();
    $client = situationClient($company->id);

    $id = actingAs($user)
        ->postJson('/api/v1/documents', situationPayload($client->id))
        ->assertCreated()
        ->json('id');

    actingAs($user)
        ->deleteJson("/api/v1/documents/{$id}")
        ->assertNoContent();

    // Soft delete : la ligne reste en base, jamais un DELETE réel (§3).
    expect(
        Document::withTrashed()->whereKey($id)->whereNotNull('deleted_at')->exists(),
    )->toBeTrue();
});

it('ne se modifie plus une fois annulée', function (): void {
    [$user, $company] = workspaceAccount();
    $client = situationClient($company->id);

    $id = actingAs($user)
        ->postJson('/api/v1/documents', situationPayload($client->id))
        ->assertCreated()
        ->json('id');

    actingAs($user)->postJson("/api/v1/documents/{$id}/cancel")->assertOk();

    // L'annulation est terminale pour TOUS les types : rouvrir la situation en
    // édition effacerait la trace de son annulation.
    actingAs($user)
        ->patchJson("/api/v1/documents/{$id}", ['subject' => 'Réouverture'])
        ->assertStatus(409);
});

it('agrège les totaux d’un client pour les indicateurs', function (): void {
    [$user, $company] = workspaceAccount();
    $client = situationClient($company->id);
    $other = Partner::factory()->client()->create(['ice' => null]);

    foreach ([[500_000, 200_000], [300_000, 300_000], [100_000, 0]] as [$total, $paid]) {
        actingAs($user)
            ->postJson('/api/v1/documents', situationPayload($client->id, [
                'totalCents' => $total,
                'paidCents' => $paid,
            ]))
            ->assertCreated();
    }

    // Situation d'un AUTRE client : elle ne doit peser sur aucun total.
    actingAs($user)
        ->postJson('/api/v1/documents', situationPayload($other->id, ['totalCents' => 999_000]))
        ->assertCreated();

    actingAs($user)
        ->getJson("/api/v1/documents/summary?type=situation&partnerId={$client->id}")
        ->assertOk()
        ->assertJsonPath('count', 3)
        ->assertJsonPath('totalCents', 900_000)
        ->assertJsonPath('paidCents', 500_000)
        ->assertJsonPath('remainingCents', 400_000);
});

it('calcule les indicateurs sur TOUTES les situations, pas sur une page', function (): void {
    [$user, $company] = workspaceAccount();
    $client = situationClient($company->id);

    // 30 situations : au-delà de la page par défaut (25). Un total calculé
    // côté client sur les lignes affichées serait faux ici, et silencieusement.
    for ($i = 0; $i < 30; $i++) {
        actingAs($user)
            ->postJson('/api/v1/documents', situationPayload($client->id, ['totalCents' => 10_000]))
            ->assertCreated();
    }

    actingAs($user)
        ->getJson("/api/v1/documents/summary?type=situation&partnerId={$client->id}")
        ->assertOk()
        ->assertJsonPath('count', 30)
        ->assertJsonPath('totalCents', 300_000);
});

it('filtre les situations par plage de dates', function (): void {
    [$user, $company] = workspaceAccount();
    $client = situationClient($company->id);

    foreach (['2026-03-15', '2026-07-20'] as $date) {
        actingAs($user)
            ->postJson('/api/v1/documents', situationPayload($client->id, ['issuedAt' => $date]))
            ->assertCreated();
    }

    actingAs($user)
        ->getJson('/api/v1/documents?type=situation&from=2026-07-01&to=2026-07-31')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.issuedAt', '2026-07-20');
});

it('recherche une situation par son objet', function (): void {
    [$user, $company] = workspaceAccount();
    $client = situationClient($company->id);

    actingAs($user)
        ->postJson('/api/v1/documents', situationPayload($client->id, [
            'subject' => 'Décompte chantier Casablanca',
        ]))
        ->assertCreated();

    actingAs($user)
        ->postJson('/api/v1/documents', situationPayload($client->id, [
            'subject' => 'Décompte chantier Rabat',
        ]))
        ->assertCreated();

    // L'objet est le seul texte discriminant d'une situation : on la retrouve
    // par « Rabat », pas par un numéro qu'on ne retient pas.
    actingAs($user)
        ->getJson('/api/v1/documents?type=situation&search=Rabat')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.subject', 'Décompte chantier Rabat');
});

it('ne laisse pas une société voir les situations d’une autre', function (): void {
    [$userA, $companyA] = workspaceAccount();
    [$userB] = workspaceAccount();

    $clientOfA = situationClient($companyA->id);

    actingAs($userA)
        ->postJson('/api/v1/documents', situationPayload($clientOfA->id, [
            'totalCents' => 750_000,
        ]))
        ->assertCreated();

    // Test d'isolation exigé par §5.6. B ne voit ni les lignes, ni les totaux.
    actingAs($userB)
        ->getJson('/api/v1/documents?type=situation')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);

    actingAs($userB)
        ->getJson("/api/v1/documents/summary?type=situation&partnerId={$clientOfA->id}")
        ->assertOk()
        ->assertJsonPath('count', 0)
        ->assertJsonPath('totalCents', 0);
});

it('n’accepte ni montant global ni avance sur une facture', function (): void {
    [$user] = workspaceAccount();

    // Les champs sont IGNORÉS, comme `status` et `number` : le total d'une
    // facture reste la somme de ses lignes, quoi qu'on poste.
    actingAs($user)
        ->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'clientName' => 'Cliente SARL',
            'totalCents' => 9_999_999,
            'paidCents' => 9_999_999,
            'items' => [['label' => 'Prestation', 'quantity' => '1', 'unitPriceCents' => 250_000]],
        ])
        ->assertCreated()
        ->assertJsonPath('totalCents', 250_000)
        ->assertJsonPath('paidCents', 0);
});
