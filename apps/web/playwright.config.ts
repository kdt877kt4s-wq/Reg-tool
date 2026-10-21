import { defineConfig, devices } from "@playwright/test";

/**
 * Browser tests of the MVP path against a running API and web app. CI
 * starts both (see the "e2e" job); locally, start them yourself and run
 * `npm run e2e` with E2E_BASE_URL and E2E_API_LOG set.
 */
export default defineConfig({
  testDir: "./e2e",
  timeout: 420_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [["list"], ["junit", { outputFile: "e2e-results.xml" }]],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? "http://localhost:3000",
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
});
