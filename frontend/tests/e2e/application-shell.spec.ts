import { expect, test } from '@playwright/test'

test('loads the responsive shell and navigates between primary sections', async ({ page }) => {
  await page.goto('/')

  await expect(page).toHaveTitle('Vovremya')
  await expect(page.getByRole('heading', { name: 'Сегодня', exact: true }).first()).toBeVisible()
  await expect(page.getByTestId('backend-status')).toHaveAttribute('data-state', 'ok')

  await page.getByRole('link', { name: 'Календарь' }).click()
  await expect(page).toHaveURL(/\/calendar$/)
  await expect(page.getByRole('heading', { name: 'Календарь' })).toBeVisible()

  await page.getByRole('link', { name: 'Клиенты' }).click()
  await expect(page).toHaveURL(/\/clients$/)
  await expect(page.getByRole('heading', { name: 'Клиенты' })).toBeVisible()
})
