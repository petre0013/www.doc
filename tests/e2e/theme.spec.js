import { test, expect } from '@playwright/test';

// Theme preference is per-browser (localStorage), so each test starts from the
// auth storage state only — i.e. no stored preference, which means "system".
// The only control is the "Appearance" card on Settings › Profile.
// No workspace/content is created by this spec.
test.describe('Theme', () => {
  test('defaults to system and follows OS scheme changes live', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await page.goto('/workspaces');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');

    // With the preference on "system", an OS scheme flip re-themes without a reload.
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  });

  test('picking Dark in settings applies the dark tokens and persists across reload', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await page.goto('/settings/profile');

    await page.getByRole('button', { name: 'Dark', exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

    // The token layer actually flipped (not just the attribute).
    const canvas = await page.evaluate(() =>
      getComputedStyle(document.documentElement).getPropertyValue('--canvas').trim().toUpperCase()
    );
    expect(canvas).toBe('#171B17');

    // Persists on other pages: the pre-paint boot script re-stamps it.
    await page.goto('/workspaces');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

    // An explicit choice wins over the OS scheme.
    await page.emulateMedia({ colorScheme: 'light' });
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  });

  test('System option returns to following the OS', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.goto('/settings/profile');

    await page.getByRole('button', { name: 'Light', exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');

    await page.getByRole('button', { name: 'System', exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  });

  test('accent defaults to sage and picking a hue swaps only the accent ramp', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await page.goto('/settings/profile');
    await expect(page.locator('html')).toHaveAttribute('data-accent', 'sage');

    await page.getByRole('group', { name: 'Accent' }).getByRole('button', { name: 'Pink', exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('data-accent', 'pink');

    // The accent ramp flipped, but neutrals and semantics stayed put.
    const tokens = await page.evaluate(() => {
      const s = getComputedStyle(document.documentElement);
      return {
        accent400: s.getPropertyValue('--accent-400').trim().toUpperCase(),
        canvas: s.getPropertyValue('--canvas').trim().toUpperCase(),
        successSurface: s.getPropertyValue('--success-surface').trim().toUpperCase(),
      };
    });
    expect(tokens.accent400).toBe('#B4809D');
    expect(tokens.canvas).toBe('#F5F4ED');
    expect(tokens.successSurface).toBe('#DAE6D4');

    // Persists across navigation via the pre-paint script (no flash of sage).
    await page.goto('/workspaces');
    await expect(page.locator('html')).toHaveAttribute('data-accent', 'pink');
  });

  test('page width preference stamps, persists, and leaves non-document pages boxed', async ({ page }) => {
    await page.goto('/settings/profile');
    await expect(page.locator('html')).toHaveAttribute('data-width', 'boxed');

    await page.getByRole('group', { name: 'Page width' }).getByRole('button', { name: 'Full width', exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('data-width', 'full');

    // Persists across navigation via the pre-paint script.
    await page.goto('/workspaces');
    await expect(page.locator('html')).toHaveAttribute('data-width', 'full');

    // Only document pages opt in (DocsLayout `wideable`): the workspaces
    // page keeps its boxed column even with "full" stored.
    const maxWidth = await page.evaluate(() => getComputedStyle(document.querySelector('main')).maxWidth);
    expect(maxWidth).not.toBe('none');
  });

  test('accent and scheme compose independently', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await page.goto('/settings/profile');

    await page.getByRole('group', { name: 'Accent' }).getByRole('button', { name: 'Blue', exact: true }).click();
    await page.getByRole('button', { name: 'Dark', exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(page.locator('html')).toHaveAttribute('data-accent', 'blue');

    // Dark blue ramp, dark neutrals: the compound block won.
    const tokens = await page.evaluate(() => {
      const s = getComputedStyle(document.documentElement);
      return {
        accent400: s.getPropertyValue('--accent-400').trim().toUpperCase(),
        canvas: s.getPropertyValue('--canvas').trim().toUpperCase(),
      };
    });
    expect(tokens.accent400).toBe('#7798D3');
    expect(tokens.canvas).toBe('#171B17');

    // Scheme back to System: the accent must survive untouched.
    await page.getByRole('button', { name: 'System', exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await expect(page.locator('html')).toHaveAttribute('data-accent', 'blue');
  });
});
