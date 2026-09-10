import { expect, test } from '@playwright/test'

const run = process.env.TRANSFERS_E2E_RUN

function nextMonday(offsetWeeks = 0): string {
  const date = new Date()
  const days = ((8 - date.getDay()) % 7 || 7) + offsetWeeks * 7
  date.setDate(date.getDate() + days)
  return date.toISOString().slice(0, 10)
}

test('offers non-reserving transfer options', async ({ page }, testInfo) => {
  test.skip(!run, 'Use bash bin/test-transfers-e2e to provision isolated disposable tenants.')
  const device = testInfo.project.name.startsWith('mobile') ? 'mobile' : 'desktop'
  const pageErrors: string[] = []
  page.on('pageerror', (error) => pageErrors.push(error.message))

  await page.goto('/calendar')
  await page.getByLabel('Email', { exact: true }).fill(`appointments-e2e-${run}-${device}@example.test`)
  await page.getByLabel('Пароль', { exact: true }).fill('Appointments-E2E-test-only')
  await page.getByRole('button', { name: 'Войти', exact: true }).click()
  await page.getByRole('link', { name: 'Новое занятие', exact: true }).click()
  await page.getByLabel('Дата', { exact: true }).fill(nextMonday())
  await page.getByLabel('Начало', { exact: true }).fill('10:00')
  await page.getByRole('button', { name: 'Создать занятие', exact: true }).click()
  await page.getByRole('link', { name: 'Открыть занятие', exact: true }).click()
  await page.getByRole('link', { name: 'Предложить перенос', exact: true }).click()

  await expect(page.getByRole('heading', { name: 'Запрос переноса', exact: true })).toBeVisible()
  await expect(page.getByText('Предложенные варианты не занимают время в расписании.')).toBeVisible()
  await page.getByLabel('Дата варианта 1', { exact: true }).fill(nextMonday(1))
  await page.getByLabel('Время варианта 1', { exact: true }).fill('11:00')
  await page.getByLabel('Дата варианта 2', { exact: true }).fill(nextMonday(2))
  await page.getByLabel('Время варианта 2', { exact: true }).fill('12:00')
  await page.getByRole('button', { name: 'Предложить варианты', exact: true }).click()

  await expect(page.getByRole('heading', { name: 'Варианты отправлены', exact: true })).toBeVisible()
  await expect(page.getByText('Автоматического срока ожидания нет. Предложенные варианты не удерживают время.')).toBeVisible()
  const state = await page.request.get(new URL(`/api/appointments/${page.url().match(/appointments\/([^/]+)/)?.[1] ?? ''}/transfer`, page.url()).toString())
  expect(state.ok()).toBe(true)
  expect((await state.json()).options).toHaveLength(2)
  expect(pageErrors).toEqual([])
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
})
