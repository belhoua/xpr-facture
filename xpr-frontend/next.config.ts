import type { NextConfig } from "next";
import createNextIntlPlugin from "next-intl/plugin";

// Branche le chargeur de messages i18n (lib/i18n/request.ts) dans le build
const withNextIntl = createNextIntlPlugin("./lib/i18n/request.ts");

const nextConfig: NextConfig = {};

export default withNextIntl(nextConfig);
