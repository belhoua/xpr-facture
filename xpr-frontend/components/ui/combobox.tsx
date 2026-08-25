"use client";

import { ChevronsUpDown, Plus } from "lucide-react";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { cn } from "@/lib/utils";

/**
 * Sélecteur RECHERCHABLE : un champ de saisie qui filtre la liste à la frappe.
 *
 * ── Pourquoi il remplace `<Select>` sur les tiers ─────────────────────────
 *
 * Un déroulant Radix se parcourt à la molette. Passe une trentaine de clients,
 * choisir « Studio Graphique Concept » demande de faire défiler une liste où
 * rien ne se cherche — et le répertoire d'un cabinet en compte des centaines.
 * Le filtrage vit dans `cmdk`, déjà présent pour la palette ⌘K : aucune
 * dépendance ajoutée, et le même comportement de recherche que celui que
 * l'utilisateur connaît déjà de `⌘K`.
 *
 * ── Ce que ce composant ne fait PAS ───────────────────────────────────────
 *
 * Il ne va pas chercher les données. La liste lui est donnée, déjà chargée par
 * l'écran — c'est ce qui le rend utilisable pour un référentiel de tiers comme
 * pour une liste de natures de charge, sans qu'il connaisse ni l'un ni l'autre.
 * Le filtrage est donc LOCAL : sur une liste paginée côté serveur, il ne
 * trouverait que ce que la page contient. Les appelants demandent 100 lignes,
 * ce qui couvre le répertoire d'une TPE ; au-delà, il faudra une recherche
 * serveur, et ce commentaire est là pour qu'on s'en souvienne.
 */
export interface ComboboxOption {
  value: string;
  label: string;
  /** Deuxième ligne, en retrait : ICE, ville, tout ce qui départage deux homonymes. */
  hint?: string;
}

export function Combobox({
  options,
  value,
  onChange,
  placeholder,
  searchPlaceholder,
  emptyLabel,
  onCreate,
  createLabel,
  disabled = false,
  id,
  className,
}: {
  options: readonly ComboboxOption[];
  /** "" = aucune sélection. */
  value: string;
  onChange: (value: string) => void;
  /** Libellé du bouton quand rien n'est choisi. */
  placeholder: string;
  searchPlaceholder: string;
  /** Affiché quand la recherche ne rend rien — « Aucun client trouvé ». */
  emptyLabel: string;
  /**
   * Création à la volée depuis la recherche, quand le référentiel se remplit à
   * l'usage plutôt que d'être administré ailleurs.
   *
   * Absente, le composant ne propose que de choisir — c'est le cas des tiers,
   * qui ont leur propre écran de saisie avec ICE, adresse et conditions de
   * règlement : les créer depuis un déroulant produirait des fiches réduites à
   * un nom.
   */
  onCreate?: (name: string) => void;
  /** Libellé de l'entrée de création, composé avec la saisie en cours. */
  createLabel?: (name: string) => string;
  disabled?: boolean;
  id?: string;
  className?: string;
}) {
  const [open, setOpen] = useState(false);
  // La saisie est SUIVIE ici, et non laissée à `cmdk` : l'entrée de création
  // doit porter le texte tapé, que le composant ne rend pas autrement.
  const [search, setSearch] = useState("");

  const canCreate =
    onCreate !== undefined &&
    createLabel !== undefined &&
    search.trim() !== "" &&
    // Rien à créer si la saisie désigne déjà une entrée : deux services du même
    // nom ne se distingueraient plus dans aucun déroulant.
    !options.some(
      (option) => option.label.toLowerCase() === search.trim().toLowerCase(),
    );

  const selected = options.find((option) => option.value === value);

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          id={id}
          type="button"
          variant="outline"
          role="combobox"
          aria-expanded={open}
          disabled={disabled}
          // `justify-between` et non le centrage par défaut d'un bouton : la
          // valeur se lit au début de la ligne, comme dans un champ de saisie.
          // `font-normal` pour la même raison — un bouton porte du texte
          // d'action, celui-ci porte une donnée.
          className={cn("w-full justify-between font-normal", className)}
        >
          <span className={cn("truncate", selected ? "" : "text-muted-foreground")}>
            {selected?.label ?? placeholder}
          </span>
          <ChevronsUpDown className="size-4 shrink-0 opacity-50" aria-hidden />
        </Button>
      </PopoverTrigger>

      {/* Largeur calée sur celle du déclencheur : une liste plus étroite que
          son champ tronquerait des raisons sociales que le champ, lui, affiche
          en entier. `p-0` annule le padding du popover — `Command` porte le
          sien, et les deux cumulés décollent la liste de ses bords. */}
      <PopoverContent
        align="start"
        className="w-[var(--radix-popover-trigger-width)] p-0"
      >
        <Command
          // Le filtre par défaut de `cmdk` note la proximité et réordonne ;
          // celui-ci cherche la sous-chaîne, dans le libellé ET dans son
          // complément — taper un ICE ou une ville doit remonter la fiche.
          // L'ordre reçu est conservé : c'est celui du serveur, alphabétique.
          filter={(itemValue, search) =>
            itemValue.toLowerCase().includes(search.toLowerCase()) ? 1 : 0
          }
        >
          <CommandInput
            placeholder={searchPlaceholder}
            value={search}
            onValueChange={setSearch}
          />
          <CommandList>
            {/* `CommandEmpty` ne s'affiche que si AUCUN item n'est rendu :
                l'entrée de création est donc placée à l'intérieur, sans quoi
                elle ferait disparaître le message pour tout le monde. */}
            <CommandEmpty>
              {canCreate ? (
                <button
                  type="button"
                  className="hover:bg-muted flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-start text-sm"
                  onClick={() => {
                    onCreate(search.trim());
                    setSearch("");
                    setOpen(false);
                  }}
                >
                  <Plus className="size-4 shrink-0" aria-hidden />
                  {createLabel(search.trim())}
                </button>
              ) : (
                emptyLabel
              )}
            </CommandEmpty>
            <CommandGroup>
              {options.map((option) => (
                <CommandItem
                  key={option.value}
                  // `CommandItem` porte DÉJÀ sa coche, révélée par cet
                  // attribut : en ajouter une seconde afficherait deux marques
                  // sur la ligne choisie.
                  data-checked={option.value === value}
                  // La valeur COMPARÉE par le filtre, pas celle qui est
                  // retenue : `cmdk` filtre sur cette chaîne, et `onSelect`
                  // rend la même. On y concatène le complément pour que la
                  // recherche le couvre, et on ignore l'argument reçu au profit
                  // de `option.value`.
                  value={`${option.label} ${option.hint ?? ""}`}
                  onSelect={() => {
                    // Re-cliquer sur la ligne déjà choisie la DÉSÉLECTIONNE :
                    // c'est le seul moyen de revenir à « aucun » sans entrée
                    // dédiée dans la liste.
                    onChange(option.value === value ? "" : option.value);
                    setOpen(false);
                  }}
                >
                  <div className="flex min-w-0 flex-col">
                    <span className="truncate">{option.label}</span>
                    {option.hint ? (
                      <span className="text-muted-foreground truncate text-xs">
                        {option.hint}
                      </span>
                    ) : null}
                  </div>
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
