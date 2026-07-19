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

export interface ApiCompany {
  id: string;
  legal_name: string;
  trade_name: string | null;
  legal_form: string;
  ice: string | null;
  vat_exempt: boolean;
  default_currency: string;
  country: string;
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
