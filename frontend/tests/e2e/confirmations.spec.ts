import { expect, test } from '@playwright/test'

const run = process.env.CONFIRMATIONS_E2E_RUN

test('configures confirmations and sends one request from an appointment', async ({ page }, testInfo) => {
  test.skip(!run, 'Use bash bin/test-confirmations-e2e to provision isolated disposable tenants.')
  const device = testInfo.project.name.startsWith('mobile') ? 'mobile' : 'desktop'
  const pageErrors: string[] = []
  page.on('pageerror', (error) => pageErrors.push(error.message))

  await page.goto('/settings')
  await page.getByLabel('Email', { exact: true }).fill(`appointments-e2e-${run}-${device}@example.test`)
  await page.getByLabel('Пароль', { exact: true }).fill('Appointments-E2E-test-only')
  await page.getByRole('button', { name: 'Войти', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Подтверждения и напоминания' })).toBeVisible()
  await page.getByLabel('Запросить подтверждение накануне в').fill('13:30')
  await page.getByRole('button', { name: 'Сохранить', exact: true }).click()
  await expect(page.getByText('Настройки сохранены.', { exact: true })).toBeVisible()

  const nextMonday = new Date()
  nextMonday.setUTCDate(nextMonday.getUTCDate() + ((8 - nextMonday.getUTCDay()) % 7 || 7))
  const appointmentDate = nextMonday.toISOString().slice(0, 10)
  await page.goto('/appointments/new')
  await page.getByLabel('Дата').fill(appointmentDate)
  await page.getByLabel('Начало').fill('10:00')
  await page.getByRole('button', { name: 'Создать занятие', exact: true }).click()
  await page.getByRole('link', { name: 'Открыть занятие', exact: true }).click()
  await expect(page.getByText('Не запрашивалось', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Отправить запрос', exact: true }).click()
  await expect(page.getByText('Ожидает ответа', { exact: true })).toBeVisible()

  expect(pageErrors).toEqual([])
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
})
