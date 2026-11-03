import { test as setup, expect } from '@playwright/test';
const authFile = 'playwright/.auth/admin.json';

// CI boots a fresh stack, so the wizard branch below creates this account.
// Against a LOCAL seeded dev DB the admin already exists with a different
// password — override with E2E_EMAIL / E2E_PASSWORD (the seeder uses "password"):
//   E2E_PASSWORD=password APP_URL=http://localhost:8000 npx playwright test
const EMAIL = process.env.E2E_EMAIL || 'admin@example.com';
const PASSWORD = process.env.E2E_PASSWORD || 'password123';

setup('complete setup wizard and authenticate', async ({ page }) => {
  // A fresh database redirects everything to /setup
  await page.goto('/login');

  // Wait for network to settle so we know where we landed
  await page.waitForLoadState('networkidle');

  if (page.url().includes('login')) {
      // Already setup, just login. `exact` so "Password" doesn't also match the
      // field's "Show password" eye-toggle button (aria-label).
      await page.getByLabel('Email').fill(EMAIL);
      await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
      await page.getByRole('button', { name: /Sign in/i }).click();
  } else {
      // Step 1: Welcome
      await expect(page).toHaveURL(/.*setup/);
      await page.getByRole('button', { name: /Get started/i }).click();

      // Step 2: Administrator account creation
      await expect(page.getByText('Create your administrator account')).toBeVisible();
      await page.getByLabel('Name').fill('Admin User');
      await page.getByLabel('Email').fill(EMAIL);

      // Need to use exact matches for password fields
      await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
      await page.getByLabel('Confirm password').fill(PASSWORD);
      await page.getByRole('button', { name: /Continue/i }).click();

      // Step 3: Instance name
      await expect(page.getByLabel('Instance name')).toBeVisible();
      // It defaults to 'www.doc', just click Continue
      await page.getByRole('button', { name: /Continue/i }).click();

      // Step 4: Email / SMTP (Skip for now)
      await expect(page.getByText('Email (SMTP) settings')).toBeVisible();
      await page.getByRole('button', { name: /Skip for now/i }).click();

      // Step 5: Finish
      await expect(page.getByText('All set')).toBeVisible();
      await page.getByRole('button', { name: /Finish setup/i }).click();
  }

  // After finishing or logging in, it redirects to the workspaces index
  await expect(page).toHaveURL(/.*workspaces/);
  await expect(page.getByRole('heading', { name: 'Workspaces' })).toBeVisible();

  // Save the authenticated state
  await page.context().storageState({ path: authFile });
});
