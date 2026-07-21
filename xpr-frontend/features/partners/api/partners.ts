import {
  partnerListSchema,
  partnerSchema,
  type Partner,
  type PartnerFormValues,
  type PartnerList,
  type PartnerType,
} from "@/features/partners/schemas/partner";
import { api, ensureCsrfCookie } from "@/lib/api/client";

export interface PartnerFilters {
  type?: PartnerType | "all";
  search?: string;
}

export const partnerKeys = {
  all: ["partners"] as const,
  list: (filters: PartnerFilters) =>
    [...partnerKeys.all, "list", filters] as const,
};

export async function fetchPartners(
  filters: PartnerFilters,
): Promise<PartnerList> {
  const { data } = await api.get("/partners", {
    params: {
      // "all" est un état de l'interface, pas un filtre serveur : on ne
      // l'envoie pas, sans quoi l'API rejetterait une valeur d'enum inconnue.
      type: filters.type && filters.type !== "all" ? filters.type : undefined,
      search: filters.search?.trim() || undefined,
      perPage: 100,
    },
  });

  return partnerListSchema.parse(data);
}

/**
 * Le formulaire manipule des chaînes vides ; l'API attend `null` pour un champ
 * absent. Sans cette conversion, un ICE vide serait envoyé comme "" et
 * échouerait sur la validation des 15 chiffres.
 */
function toPayload(values: PartnerFormValues): Record<string, unknown> {
  const clean = (value: string | undefined): string | null =>
    value && value.trim() !== "" ? value.trim() : null;

  return {
    type: values.type,
    legalName: values.legalName.trim(),
    tradeName: clean(values.tradeName),
    ice: clean(values.ice),
    ifNumber: clean(values.ifNumber),
    contactName: clean(values.contactName),
    email: clean(values.email),
    phone: clean(values.phone),
    city: clean(values.city),
    address: clean(values.address),
    paymentTermsDays: values.paymentTermsDays,
    notes: clean(values.notes),
  };
}

export async function createPartner(
  values: PartnerFormValues,
): Promise<Partner> {
  // Mutation ⇒ jeton CSRF obligatoire (auth Sanctum par cookies de session).
  await ensureCsrfCookie();

  const { data } = await api.post("/partners", toPayload(values));

  return partnerSchema.parse(data);
}

export async function updatePartner(
  id: string,
  values: PartnerFormValues,
): Promise<Partner> {
  await ensureCsrfCookie();

  const { data } = await api.patch(`/partners/${id}`, toPayload(values));

  return partnerSchema.parse(data);
}

/** Archive le tiers : il quitte les listes, son historique reste lisible. */
export async function archivePartner(id: string): Promise<void> {
  await ensureCsrfCookie();

  await api.delete(`/partners/${id}`);
}
