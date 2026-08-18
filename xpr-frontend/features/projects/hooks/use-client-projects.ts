"use client";

import { useQuery } from "@tanstack/react-query";

import { fetchProjects, projectKeys } from "@/features/projects/api/projects";
import type { Project } from "@/features/projects/schemas/project";

/**
 * Projets d'UN client, pour les déroulants « Projet ».
 *
 * Trois écrans en ont besoin — le formulaire de document, l'encours d'un client
 * et la liste des situations. Un hook partagé plutôt que trois `useQuery`
 * recopiés : ils divergeraient sur la clé de cache, et deux clés différentes
 * pour la même donnée font réapparaître un projet supprimé sur l'un des écrans.
 *
 * `enabled` sur le client : `""` signifie « pas encore choisi », et il n'y a
 * alors rien à demander. Interroger `/projects` dans cet état rendrait tous les
 * projets de la société, dont on ne proposerait ensuite qu'une partie — une
 * requête large pour un usage étroit, et l'occasion d'afficher le chantier d'un
 * autre client.
 *
 * `"all"` est distinct de `""` et demande DÉLIBÉRÉMENT tout le répertoire de
 * projets : c'est ce dont a besoin la liste générale des situations, qui filtre
 * par projet sans avoir choisi de client au préalable.
 */
export function useClientProjects(partnerId: string): {
  projects: readonly Project[];
  isPending: boolean;
} {
  const filters = {
    search: "",
    // Le statut n'est PAS filtré : une facture peut solder un chantier déjà
    // terminé, et masquer les projets clos rendrait la pièce impossible à
    // rattacher au moment même où on la rédige.
    status: "all",
    partnerId: partnerId || "all",
  };

  const query = useQuery({
    queryKey: projectKeys.list(filters),
    queryFn: () => fetchProjects(filters),
    enabled: partnerId !== "",
  });

  return {
    projects: query.data?.data ?? [],
    // `isPending` vaut vrai sur une requête désactivée : sans client choisi, il
    // n'y a rien à attendre, et un déroulant bloqué en « chargement… » le
    // laisserait croire le contraire.
    isPending: partnerId !== "" && query.isPending,
  };
}
