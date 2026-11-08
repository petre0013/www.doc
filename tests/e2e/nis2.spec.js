import { test, expect } from '@playwright/test';
import { createDoc, openWorkspace } from './helpers.js';

const PASSWORD = 'e2e-password-1234';
const stamp = () => `${Date.now()}${Math.floor(Math.random() * 1000)}`;

/** Fail a test if the page throws, wherever it happens. */
test.beforeEach(async ({ page }) => {
    page.on('pageerror', (error) => { throw error; });
});

async function addUser(page, name, email, role) {
    await page.goto('/admin/users');
    const form = page.locator('form').filter({ hasText: 'Add a user' });
    const inputs = form.locator('input');
    await inputs.nth(0).fill(name);
    await inputs.nth(1).fill(email);
    await inputs.nth(2).fill(PASSWORD);
    await inputs.nth(3).fill(PASSWORD);
    await form.locator('select').selectOption(role);
    await form.getByRole('button', { name: 'Add user' }).click();
    await expect(page.getByText(email)).toBeVisible();
}

async function signIn(browser, email) {
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const page = await context.newPage();
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
    await page.getByRole('button', { name: /Sign in/i }).click();
    await page.waitForURL(/workspaces/);
    return page;
}

/** A wrapping <label> also contains its options' text, so match from its start. */
function selectFor(page, label) {
    const escaped = label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return page.locator('label').filter({ hasText: new RegExp(`^${escaped}`) }).locator('select');
}

async function writeAndSave(page, text) {
    const editor = page.locator('.tiptap.ProseMirror');
    await editor.click();
    await page.keyboard.type(text);
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Edit', exact: true })).toBeVisible();
}

test('document control runs from draft to approved, and readers keep the approved text', async ({ page, browser }) => {
    const id = stamp();
    const approverEmail = `approver${id}@example.com`;
    const approverName = `Approver ${id}`;
    await addUser(page, approverName, approverEmail, 'viewer');

    const url = await createDoc(page, 'E2E NIS2 Control', `Policy ${id}`);
    await writeAndSave(page, 'Approved wording');

    await page.getByRole('button', { name: 'Place under document control' }).click();
    await selectFor(page, 'Owner').selectOption({ label: 'Admin User' });
    await selectFor(page, 'Approver').selectOption({ label: approverName });
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText(/^DOC-\d+/)).toBeVisible();

    await page.getByRole('button', { name: 'Submit for approval' }).click();
    await page.getByRole('button', { name: 'Submit', exact: true }).click();
    await expect(page.getByText('In review', { exact: true })).toBeVisible();

    const approver = await signIn(browser, approverEmail);
    await approver.goto(url);
    await approver.getByRole('button', { name: 'Approve', exact: true }).click();
    await approver.getByRole('button', { name: 'Approve this version' }).click();
    await expect(approver.locator('span.rounded-full', { hasText: /^Approved$/ })).toBeVisible();

    await page.reload();
    await page.getByRole('button', { name: 'Edit', exact: true }).click();
    await writeAndSave(page, ' plus unapproved draft');
    await expect(page.getByText("changes that aren't approved")).toBeVisible();

    await approver.reload();
    await expect(approver.getByText('Approved wording')).toBeVisible();
    await expect(approver.getByText('unapproved draft')).toHaveCount(0);
    await expect(approver.getByText("You're reading the approved version")).toBeVisible();

    await page.goto('/document-control');
    const row = page.getByRole('row').filter({ hasText: `Policy ${id}` });
    await expect(row.getByRole('link', { name: `Policy ${id}` })).toBeVisible();
    await page.getByLabel('In force on').fill('2020-01-01');
    await expect(row.getByText('None')).toBeVisible();

    const pack = await page.request.get('/document-control/audit-pack');
    expect(pack.status()).toBe(200);
    expect(pack.headers()['content-disposition']).toContain('audit-pack');
    await approver.context().close();
});

test('a restricted workspace disappears for non-members', async ({ page, browser }) => {
    const id = stamp();
    const name = `Restricted ${id}`;
    const email = `outsider${id}@example.com`;
    await addUser(page, `Outsider ${id}`, email, 'viewer');
    await openWorkspace(page, name);
    const workspaceUrl = page.url();

    await page.getByRole('button', { name: 'More actions' }).click();
    await page.getByRole('menuitem', { name: 'Manage access…' }).click();
    await page.getByLabel(/Restrict this workspace/).check();
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByLabel('Restricted workspace')).toBeVisible();

    const outsider = await signIn(browser, email);
    await expect(outsider.getByText(name)).toHaveCount(0);
    const response = await outsider.goto(workspaceUrl);
    expect(response.status()).toBe(404);
    await outsider.context().close();
});

test('registers record incidents with deadlines and score risks', async ({ page }) => {
    const id = stamp();
    await page.goto('/registers/incident');
    await page.getByRole('button', { name: 'Add incident' }).click();
    await page.getByLabel('Title').fill(`Outage ${id}`);
    await selectFor(page, 'Significant under Art. 23?').selectOption('yes');
    await page.getByLabel('Became aware it was significant').fill('2026-01-01T10:00');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.waitForURL(/registers\/incident\/\d+/);
    await expect(page.getByText(/Early warning: due/)).toContainText('late');

    await page.goto('/registers/risk');
    await page.getByRole('button', { name: 'Add risk' }).click();
    await page.getByLabel('Title').fill(`Ransomware ${id}`);
    await selectFor(page, 'Likelihood (1-5)').selectOption('4');
    await selectFor(page, 'Impact (1-5)').selectOption('5');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.waitForURL(/registers\/risk\/\d+/);
    await expect(page.getByText('Score: 20')).toBeVisible();

    await page.goto('/registers');
    await expect(page.getByRole('heading', { name: 'Registers' })).toBeVisible();
    await expect(page.getByRole('link', { name: /Incidents/ })).toBeVisible();
});

test('a deactivated user is locked out and the audit page works', async ({ page, browser }) => {
    const id = stamp();
    const email = `leaver${id}@example.com`;
    await addUser(page, `Leaver ${id}`, email, 'viewer');

    const row = page.locator('div.flex.items-center.gap-3').filter({ hasText: email });
    await row.getByTitle('Deactivate user').click();
    await page.getByRole('button', { name: 'Deactivate user' }).last().click();
    await expect(row.getByText('Deactivated')).toBeVisible();

    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const leaver = await context.newPage();
    await leaver.goto('/login');
    await leaver.getByLabel('Email').fill(email);
    await leaver.getByLabel('Password', { exact: true }).fill(PASSWORD);
    await leaver.getByRole('button', { name: /Sign in/i }).click();
    await expect(leaver.getByText('These credentials do not match')).toBeVisible();
    await context.close();

    await page.goto('/admin/audit');
    await expect(page.getByText(/Integrity/)).toBeVisible();
    await expect(page.getByRole('link', { name: 'Export CSV' })).toBeVisible();
    await page.getByLabel('Keep for').fill(String(400 + Math.floor(Math.random() * 2000)));
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByRole('button', { name: 'Save' })).toBeDisabled();
});
