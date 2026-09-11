import { expect, test } from '@playwright/test'

const run = process.env.WAITING_E2E_RUN

test('configures waiting list conditions', async ({ page }, testInfo) => {
  test.skip(!run, 'Use bash bin/test-waiting-e2e to provision isolated disposable tenants.')
  const device = testInfo.project.name.startsWith('mobile') ? 'mobile' : 'desktop'
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))

  await page.goto('/clients')
  await page.getByLabel('Email', { exact: true }).fill(`appointments-e2e-${run}-${device}@example.test`)
  await page.getByLabel('Пароль', { exact: true }).fill('Appointments-E2E-test-only')
  await page.getByRole('button', { name: 'Войти', exact: true }).click()
  await page.getByRole('link', { name: /Петя Сидоров/ }).click()
  await page.getByRole('link', { name: 'Настроить ожидание', exact: true }).click()

  await expect(page.getByRole('heading', { name: 'Настроить ожидание' })).toBeVisible()
  await page.getByLabel('Необходимая частота занятий').selectOption('2')
  await page.getByLabel('Время с').fill('17:00')
  await page.getByLabel('Время до').fill('19:00')
  await page.getByLabel('Организационный комментарий').fill('Могут приехать быстро')
  await page.getByRole('button', { name: 'Сохранить условия', exact: true }).click()

  await expect(page.getByRole('heading', { name: 'Клиент' })).toBeVisible()
  await page.getByRole('link', { name: 'Настроить ожидание', exact: true }).click()
  await expect(page.getByLabel('Необходимая частота занятий')).toHaveValue('2')
  await expect(page.getByLabel('Время с')).toHaveValue('17:00')
  await expect(page.getByLabel('Время до')).toHaveValue('19:00')
  await expect(page.getByLabel('Организационный комментарий')).toHaveValue('Могут приехать быстро')

  expect(errors).toEqual([])
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
})
