import { test, expect } from '@playwright/test';

test.describe('Search Functionality', () => {
  test('user can search for documents using the top nav search bar', async ({ page }) => {
    // Navigate to dashboard
    await page.goto('/workspaces');
    await expect(page).toHaveTitle(/www\.doc/i);
    
    // Find the search input
    const searchInput = page.getByPlaceholder(/Search docs/i);
    await expect(searchInput).toBeVisible();
    
    // Type a query and hit Enter to trigger search
    await searchInput.fill('E2E Test');
    await searchInput.press('Enter');
    
    // Wait for the URL to change to the search results page
    await expect(page).toHaveURL(/.*\/search\?q=E2E(%20|\+)Test/i);
    
    // Ensure the results page loads
    await expect(page.getByRole('heading', { name: /result.* for/i })).toBeVisible();
  });
});
