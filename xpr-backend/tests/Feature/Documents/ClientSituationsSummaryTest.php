<?php

declare(strict_types=1);

use App\Modules\Documents\Models\Document;
use App\Modules\Partners\Models\Partner;
use App\Modules\Tenancy\Services\TenantContext;

use function Pest\Laravel\actingAs;

/**
 * Les quatre indicateurs de l'écran « situations par client ».
 *
 * Ce que ce fichier verrouille, et que `SituationTest` ne couvrait pas : le
 * KPI « payé » se lit dans `payments`, la table des règlements RÉELS, et non
 * dans la seule colonne `documents.paid_cents`. Les deux coïncident tant que
 * `PaymentWriteService` est le seul à écrire — mais l'écran doit rester juste
 * le jour où ce n'est plus le cas.
 *
 * Le repli sur `paid_cents` n'est pas une tolérance : c'est le régime normal
 * de la SITUATION, dont l'avance est saisie en en-tête et n'a aucun règlement
 * derrière elle.
 */

/** Un client de la société active, prêt à porter documents et règlements. */
function summaryClient(string $companyId): Partner
{
    app(TenantContext::class)->activateCompany($companyId);

    return Partner::factory()->client()->create(['ice' => null]);
}

/**
 * Facture ÉMISE rattachée au client, seule pièce qu'un règlement puisse viser.
 *
 * Créée par la factory et non par l'API : ce fichier éprouve la LECTURE des
 * agrégats, et passer par la création puis l'émission d'une facture complète y
 * ajouterait la validation des lignes, qui a ses propres tests.
 *
 * Le NUMÉRO est posé à la main, ce que la factory ne fait jamais — elle ignore
 * la séquence de la société. Il est indispensable ici : sans lui la facture
 * n'existe pas fiscalement, et `PaymentWriteService` refuse d'y rattacher une
 * recette. Le préfixe est propre à ces tests, pour ne jamais entrer en
 * collision avec les factures numérotées par le seeder de démonstration.
 */
function issuedInvoiceFor(Partner $client, int $totalCents): Document
{
    static $sequence = 0;
    $sequence++;

    return Document::factory()->sent()->create([
        'company_id' => $client->company_id,
        'partner_id' => $client->id,
        'client_name' => $client->legal_name,
        'number' => sprintf('TEST-SUM-%04d', $sequence),
        'subtotal_cents' => $totalCents,
        'discount_cents' => 0,
        'tax_cents' => 0,
        'total_cents' => $totalCents,
        'paid_cents' => 0,
    ]);
}

it('lit le montant payé dans les règlements enregistrés, pas dans la colonne du document', function (): void {
    [$user, $company] = workspaceAccount();
    $client = summaryClient($company->id);
    $invoice = issuedInvoiceFor($client, 400_000);

    actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amountCents' => 150_000,
            'paidOn' => now()->toDateString(),
            'method' => 'cash',
        ])
        ->assertCreated();

    actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amountCents' => 100_000,
            'paidOn' => now()->toDateString(),
            'method' => 'transfer',
        ])
        ->assertCreated();

    actingAs($user)
        ->getJson("/api/v1/documents/summary?type=invoice&partnerId={$client->id}")
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('totalCents', 400_000)
        ->assertJsonPath('paidCents', 250_000)
        ->assertJsonPath('remainingCents', 150_000);
});

it('cesse de compter un règlement retiré', function (): void {
    [$user, $company] = workspaceAccount();
    $client = summaryClient($company->id);
    $invoice = issuedInvoiceFor($client, 400_000);

    $payment = actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amountCents' => 150_000,
            'paidOn' => now()->toDateString(),
            'method' => 'cash',
        ])
        ->assertCreated()
        ->json('id');

    actingAs($user)
        ->deleteJson("/api/v1/payments/{$payment}")
        ->assertNoContent();

    // Le règlement demeure en base — soft delete — mais il ne pèse plus dans
    // aucun cumul. C'est ce que le `deleted_at IS NULL` de la sous-requête
    // garantit : le SQL brut n'hérite pas du global scope d'Eloquent.
    actingAs($user)
        ->getJson("/api/v1/documents/summary?type=invoice&partnerId={$client->id}")
        ->assertOk()
        ->assertJsonPath('paidCents', 0)
        ->assertJsonPath('remainingCents', 400_000);
});

it('retient l’avance saisie sur une situation, qui ne porte aucun règlement', function (): void {
    [$user, $company] = workspaceAccount();
    $client = summaryClient($company->id);

    // Une situation ne peut pas recevoir de règlement (PaymentWriteService
    // n'accepte que la facture) : sans le repli sur `paid_cents`, l'écran
    // afficherait « payé : 0 » sur un chantier pourtant avancé.
    actingAs($user)
        ->postJson('/api/v1/documents', [
            'type' => 'situation',
            'partnerId' => $client->id,
            'subject' => 'Situation du mois d’octobre',
            'totalCents' => 500_000,
            'paidCents' => 200_000,
        ])
        ->assertCreated();

    actingAs($user)
        ->getJson("/api/v1/documents/summary?type=situation&partnerId={$client->id}")
        ->assertOk()
        ->assertJsonPath('paidCents', 200_000)
        ->assertJsonPath('remainingCents', 300_000);
});

it('agrège situations ET factures quand les deux types sont demandés', function (): void {
    [$user, $company] = workspaceAccount();
    $client = summaryClient($company->id);

    $invoice = issuedInvoiceFor($client, 400_000);

    actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amountCents' => 150_000,
            'paidOn' => now()->toDateString(),
            'method' => 'cash',
        ])
        ->assertCreated();

    actingAs($user)
        ->postJson('/api/v1/documents', [
            'type' => 'situation',
            'partnerId' => $client->id,
            'subject' => 'Situation du mois d’octobre',
            'totalCents' => 500_000,
            'paidCents' => 200_000,
        ])
        ->assertCreated();

    // Un devis du même client : il PROPOSE, il ne doit peser sur aucun total.
    actingAs($user)
        ->postJson('/api/v1/documents', [
            'type' => 'quote',
            'partnerId' => $client->id,
            'clientName' => $client->legal_name,
            'items' => [['label' => 'Étude', 'quantity' => '1', 'unitPriceCents' => 999_000]],
        ])
        ->assertCreated();

    actingAs($user)
        ->getJson("/api/v1/documents/summary?type=situation,invoice&partnerId={$client->id}")
        ->assertOk()
        ->assertJsonPath('count', 2)
        ->assertJsonPath('totalCents', 900_000)
        // 150 000 de règlements réels + 200 000 d'avance saisie.
        ->assertJsonPath('paidCents', 350_000)
        ->assertJsonPath('remainingCents', 550_000);

    // La liste doit décrire exactement les lignes des indicateurs.
    actingAs($user)
        ->getJson("/api/v1/documents?type=situation,invoice&partnerId={$client->id}")
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

it('expose l’historique des règlements sur chaque ligne de la liste', function (): void {
    [$user, $company] = workspaceAccount();
    $client = summaryClient($company->id);
    $invoice = issuedInvoiceFor($client, 400_000);

    actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amountCents' => 150_000,
            'paidOn' => now()->toDateString(),
            'method' => 'cheque',
            'checkNumber' => '4412876',
            'checkStatus' => 'pending',
        ])
        ->assertCreated();

    actingAs($user)
        ->getJson("/api/v1/documents?type=invoice&partnerId={$client->id}")
        ->assertOk()
        ->assertJsonPath('data.0.payments.0.amountCents', 150_000)
        ->assertJsonPath('data.0.payments.0.method', 'cheque')
        ->assertJsonPath('data.0.payments.0.checkNumber', '4412876')
        // Le chemin de disque n'est JAMAIS exposé (cf. PaymentResource).
        ->assertJsonMissingPath('data.0.payments.0.scanPath');
});

it('accepte les types en paramètre RÉPÉTÉ autant qu en liste', function (string $query): void {
    [$user, $company] = workspaceAccount();
    $client = summaryClient($company->id);

    issuedInvoiceFor($client, 400_000);

    actingAs($user)
        ->postJson('/api/v1/documents', [
            'type' => 'situation',
            'partnerId' => $client->id,
            'subject' => 'Situation du mois d’octobre',
            'totalCents' => 500_000,
            'paidCents' => 200_000,
        ])
        ->assertCreated();

    // `type[]=` est la forme naturelle d'un paramètre répété en HTTP, et celle
    // qu'émet tout client qui n'a pas lu notre convention CSV. Elle produisait
    // la chaîne « Array » puis un 500 — l'écran d'un client se vidait sur une
    // requête pourtant légitime.
    actingAs($user)
        ->getJson("/api/v1/documents/summary?{$query}&partnerId={$client->id}")
        ->assertOk()
        ->assertJsonPath('count', 2)
        ->assertJsonPath('totalCents', 900_000);

    actingAs($user)
        ->getJson("/api/v1/documents?{$query}&partnerId={$client->id}")
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
})->with([
    'liste CSV' => 'type=situation,invoice',
    'paramètre répété' => 'type[]=situation&type[]=invoice',
    'répété avec espaces' => 'type[]=%20situation%20&type[]=invoice',
]);

it('refuse un type inconnu dans la liste des types', function (string $query): void {
    [$user, $company] = workspaceAccount();
    $client = summaryClient($company->id);

    // Un type inconnu est une erreur d'appel, pas un filtre à ignorer : le
    // laisser passer renverrait TOUS les documents du client en réponse à une
    // demande précise. 422 et non 500 — la faute est dans la requête, et un 500
    // accuserait le serveur d'un défaut qu'il n'a pas.
    actingAs($user)
        ->getJson("/api/v1/documents/summary?{$query}&partnerId={$client->id}")
        ->assertStatus(422)
        ->assertJsonPath('errors.type.0', fn (string $message): bool => $message !== '');
})->with([
    'liste CSV' => 'type=situation,licorne',
    'paramètre répété' => 'type[]=situation&type[]=licorne',
]);

it('fait entrer dans l’encours du client une pièce saisie au NOM LIBRE', function (): void {
    [$user, $company] = workspaceAccount();
    $client = summaryClient($company->id);
    // Nom RENDU UNIQUE : la factory puise dans une liste fixe de raisons
    // sociales, dont le jeu de démonstration se sert aussi. Un homonyme ferait
    // rapprocher la facture sur la fiche la plus ancienne — le comportement
    // attendu, mais pas celui que ce cas vient éprouver.
    $client->forceFill(['legal_name' => 'Encours Nom Libre S.A.R.L.'])->save();

    // Le cas qui vidait l'écran « situations du client » : une facture saisie
    // sans sélectionner le tiers restait rattachée à personne. Depuis le
    // 2026-08-17, le nom libre retrouve la fiche existante et la pièce entre
    // dans l'encours de son client — c'est cette continuité que ce cas
    // verrouille, du formulaire jusqu'aux indicateurs.
    actingAs($user)
        ->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'clientName' => $client->legal_name,
            'items' => [['label' => 'Prestation', 'quantity' => '1', 'unitPriceCents' => 400_000]],
        ])
        ->assertCreated()
        ->assertJsonPath('partnerId', $client->id);

    actingAs($user)
        ->getJson("/api/v1/documents/summary?type=situation,invoice&partnerId={$client->id}")
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('totalCents', 400_000);
});

it('ne laisse pas une société voir les règlements d’une autre dans les totaux', function (): void {
    [$userA, $companyA] = workspaceAccount();
    [$userB] = workspaceAccount();

    $clientOfA = summaryClient($companyA->id);
    $invoice = issuedInvoiceFor($clientOfA, 400_000);

    actingAs($userA)
        ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amountCents' => 150_000,
            'paidOn' => now()->toDateString(),
            'method' => 'cash',
        ])
        ->assertCreated();

    // Test d'isolation exigé par §5.6. La sous-requête sur `payments` ne porte
    // pas de `company_id` : c'est le scope du document englobant qui la borne,
    // et c'est précisément ce que cette assertion vérifie.
    actingAs($userB)
        ->getJson("/api/v1/documents/summary?type=invoice&partnerId={$clientOfA->id}")
        ->assertOk()
        ->assertJsonPath('count', 0)
        ->assertJsonPath('totalCents', 0)
        ->assertJsonPath('paidCents', 0);
});
