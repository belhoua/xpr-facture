/**
 * Durées de fraîcheur partagées par les requêtes TanStack Query.
 *
 * Le défaut global (`app/providers.tsx`) est de **5 minutes** : il convient aux
 * listes métier, qui changent à la vitesse d'une saisie humaine. Ce fichier ne
 * porte que les écarts à ce défaut, et il n'y en a qu'un.
 *
 * Aucune de ces durées ne retarde une mise à jour venue de l'application
 * elle-même : chaque mutation invalide ses clés, ce qui refetch immédiatement.
 * Elles ne concernent que les écritures faites AILLEURS — un collègue, un autre
 * onglet — dont l'apparition peut attendre.
 */

/**
 * Référentiels : taux de TVA, catégories, devises, prestations du catalogue,
 * modes de règlement.
 *
 * Une heure, contre cinq minutes pour le reste. Ces listes sont **courtes et
 * quasi figées** — on ajoute un taux de TVA une fois par réforme fiscale —, et
 * elles alimentent des déroulants qu'un formulaire ouvre et referme dix fois
 * par heure. Les redemander à chaque ouverture coûtait une requête par champ
 * pour ramener exactement la même réponse.
 *
 * Écrit ici plutôt que recopié dans chaque écran : la constante était dupliquée
 * à l'identique dans sept fichiers, et sept copies d'un réglage de performance
 * divergent au premier ajustement — un formulaire garderait alors son ancien
 * comportement sans que rien ne le signale.
 */
export const REFERENCE_STALE_TIME = 60 * 60 * 1000;
