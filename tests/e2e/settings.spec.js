import { test, expect } from '@playwright/test';

test.describe('User Settings', () => {
  test('user can access settings and update their profile name', async ({ page }) => {
    await page.goto('/workspaces');
    await expect(page).toHaveTitle(/www\.doc/i);

    // The header has a plain Settings icon-link (no avatar dropdown).
    await page.getByRole('link', { name: 'Settings' }).click();
    await expect(page.getByText('Profile information')).toBeVisible();

    // Update the name. Use a unique value so the assertion can't match stale
    // state, then revert to keep runs deterministic.
    const original = await page.getByLabel('Name').inputValue();
    expect(original).not.toBe('');

    await page.getByLabel('Name').fill(`${original} (e2e)`);
    await page.getByRole('button', { name: 'Save changes' }).click();
    // The save button flips to "Saved" on success.
    await expect(page.getByRole('button', { name: 'Saved' })).toBeVisible();

    // Revert
    await page.getByLabel('Name').fill(original);
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByRole('button', { name: 'Saved' })).toBeVisible();
  });
});
