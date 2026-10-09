import { fileURLToPath } from "node:url";
import { defineConfig } from "vitest/config";

// Testes de unidade ficam ao lado do arquivo testado, dentro de src/.
// Os testes de tela (testes/e2e/) são do Playwright e não entram aqui.
export default defineConfig({
  resolve: {
    alias: { "@": fileURLToPath(new URL("./src", import.meta.url)) },
  },
  test: {
    include: ["src/**/*.test.ts", "src/**/*.test.tsx"],
  },
});
