import { defineConfig, devices } from '@playwright/test';

/**
 * UI-тести YMS «Рампа» проти розгорнутого стенду.
 *
 * Тести навмисно ходять по реальному HTTPS, а не по локальному dev-серверу:
 * половина дефектів, які вони ловлять, живе саме на стику фронтенду, шлюзу
 * і бекенду, і на моках не відтворюється.
 */
export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  workers: 1,
  timeout: 60_000,
  expect: { timeout: 15_000 },
  retries: 0,
  reporter: [['list'], ['json', { outputFile: 'results.json' }]],
  use: {
    baseURL: 'https://yms.104.248.132.130.sslip.io',
    ignoreHTTPSErrors: true,
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
    locale: 'uk-UA',
    timezoneId: 'Europe/Kyiv',
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 900 } } },
  ],
});
