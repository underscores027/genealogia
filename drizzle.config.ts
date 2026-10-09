import { defineConfig } from "drizzle-kit";

// O drizzle-kit não lê o .env sozinho. Gerar migração funciona sem banco;
// aplicar (db:migrar) exige DATABASE_URL.
try {
  process.loadEnvFile();
} catch {
  // sem .env: segue com as variáveis do ambiente
}

export default defineConfig({
  dialect: "postgresql",
  schema: "./src/db/esquema.ts",
  out: "./drizzle",
  dbCredentials: { url: process.env.DATABASE_URL ?? "" },
});
