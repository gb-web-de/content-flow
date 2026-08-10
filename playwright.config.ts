import { defineConfig, devices } from '@playwright/test'

/*
 * Dev-only E2E harness, exercising the wizard flows as a blackbox against a
 * real, DDEV-served backend. Nothing here builds or bundles the extension's
 * own JavaScript - it stays plain ES modules loaded via TYPO3's importmap.
 *
 * Point CONTENTFLOW_BASE_URL at whichever DDEV instance is running for the
 * checkout under test (`ddev describe` prints its URL) - the project name in
 * .ddev/config.yaml isn't unique across worktrees, so no fixed default is
 * assumed here.
 */
export default defineConfig({
  testDir: './Tests/Playwright',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL: process.env.CONTENTFLOW_BASE_URL ?? 'https://content-flow.ddev.site',
    trace: 'retain-on-failure',
    ignoreHTTPSErrors: true,
    storageState: '.Build/playwright/backend-auth.json',
  },
  projects: [
    {
      name: 'setup',
      testMatch: /auth\.setup\.ts/,
    },
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
      dependencies: ['setup'],
    },
  ],
})
