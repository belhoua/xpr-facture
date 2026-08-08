/**
 * Contrats JSON de /api/v1/auth — miroirs de UserResource et CompanyResource.
 *
 * DETTE ASSUMÉE (P0-15) : écrits à la main tant que la génération OpenAPI
 * (Scramble → openapi-typescript) n'est pas branchée ; ils migreront vers
 * lib/api/generated/ et ce fichier ne gardera que des ré-exports.
 */

export interface ApiUser {
  id: string;
  name: string;
  email: string;
  locale: "fr" | "ar" | "en";
  email_verified: boolean;
  default_company_id: string | null;
  created_at: string | null;
}

/**
 * Identité légale de la société active. Complète, parce que l'en-tête et le
 * pied de page des documents imprimés en portent chaque mention (§3) —
 * `share_capital` est en CENTIMES, comme tous les montants de l'API (§7).
 */
export interface ApiCompany {
  id: string;
  legal_name: string;
  trade_name: string | null;
  /** Baseline imprimée sous la marque, ex. « Bureau de contrôle… ». */
  tagline: string | null;
  legal_form: string;
  ice: string | null;
  if_number: string | null;
  rc_number: string | null;
  rc_city: string | null;
  patente: string | null;
  cnss: string | null;
  share_capital: number | null;
  vat_regime: string;
  vat_exempt: boolean;
  address: string | null;
  city: string | null;
  country: string;
  phone: string | null;
  email: string | null;
  website: string | null;
  bank_rib: string | null;
  default_currency: string;
  timezone: string;
}

export interface RegisterResponse {
  user: ApiUser;
  company: ApiCompany;
}

export interface LoginResponse {
  user: ApiUser;
}

export interface MeResponse {
  user: ApiUser;
  active_company: ApiCompany | null;
  companies: ApiCompany[];
}
