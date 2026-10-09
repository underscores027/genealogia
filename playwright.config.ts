import { defineConfig, devices } from "@playwright/test";

// Testes de tela. Sobe o servidor de desenvolvimento se ainda não houver um rodando.
export default defineConfig({
  testDir: "./testes/e2e",
  use: { baseURL: "http://localhost:3000" },
  projects: [
    { name: "desktop", use: { ...devices["Desktop Chrome"] } },
    { name: "celular", use: { ...devices["Pixel 7"] } },
  ],
  webServer: {
    command: "npm run dev",
    url: "http://localhost:3000",
    reuseExistingServer: true,
  },
});
