/**
 * Coquille des vues IMPRIMABLES (devis, facture, situation).
 *
 * Volontairement nue : ni sidebar, ni barre du haut, ni palette ⌘K. Ces routes
 * ne montrent qu'un document, à l'écran comme sur le papier — la mise en page
 * A4 se relit à sa largeur réelle, et surtout la page ne porte plus AUCUN champ
 * de saisie ni contrôle de pilotage (recherche, calculatrice, sélecteurs de
 * langue et de thème) qui donnerait à croire qu'une valeur du document se
 * modifie ici. Une pièce comptable se consulte, elle ne s'édite pas : la
 * correction passe par le formulaire, et pour un document émis par un avoir
 * (§3).
 *
 * C'est un groupe de routes distinct de `(app)` et non un masquage CSS : le
 * chrome n'est pas caché, il n'est pas monté. Les URL, elles, ne bougent pas —
 * `(print)` n'apparaît pas dans le chemin.
 */
export default function PrintLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <div className="bg-background min-h-dvh">
      <main className="mx-auto w-full max-w-[210mm] px-4 py-6 print:max-w-none print:p-0">
        {children}
      </main>
    </div>
  );
}
