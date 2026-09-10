import { expect, test } from '@playwright/test'

const run = process.env.LIFECYCLE_E2E_RUN

test('records client and specialist absences', async ({ page }, testInfo) => {
  test.skip(!run, 'Use bash bin/test-absence-e2e to provision isolated disposable tenants.')
  const device = testInfo.project.name.startsWith('mobile') ? 'mobile' : 'desktop'
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))

  await page.goto('/clients')
  await page.getByLabel('Email', { exact: true }).fill(`appointments-e2e-${run}-${device}@example.test`)
  await page.getByLabel('Пароль', { exact: true }).fill('Appointments-E2E-test-only')
  await page.getByRole('button', { name: 'Войти', exact: true }).click()
  await expect(page.getByRole('link', { name: /Петя Сидоров/ })).toBeVisible()

  const nextMonday = new Date()
  nextMonday.setUTCDate(nextMonday.getUTCDate() + ((8 - nextMonday.getUTCDay()) % 7 || 7))
  const appointmentDate = nextMonday.toISOString().slice(0, 10)
  await page.goto('/appointments/new')
  await page.getByLabel('Дата').fill(appointmentDate)
  await page.getByLabel('Начало').fill('15:00')
  await page.getByRole('button', { name: 'Создать занятие', exact: true }).click()
  await expect(page.getByRole('link', { name: 'Открыть занятие', exact: true })).toBeVisible()

  await page.goto('/clients')
  await page.getByRole('link', { name: /Петя Сидоров/ }).click()
  await page.getByRole('link', { name: 'Оформить отсутствие', exact: true }).click()
  await page.getByLabel('С', { exact: true }).fill(appointmentDate)
  await page.getByLabel('По', { exact: true }).fill(appointmentDate)
  await expect(page.getByText('1 занятий в диапазоне', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Сохранить отсутствие', exact: true }).click()
  await expect(page.getByText('Постоянное место сохранено', { exact: false })).toBeVisible()

  nextMonday.setUTCDate(nextMonday.getUTCDate() + 7)
  const specialistDate = nextMonday.toISOString().slice(0, 10)
  await page.goto('/specialists')
  await page.getByRole('link', { name: /Юлия Иванова/ }).click()
  await page.getByRole('link', { name: 'Оформить отсутствие', exact: true }).click()
  await page.getByLabel('С', { exact: true }).fill(specialistDate)
  await page.getByLabel('По', { exact: true }).fill(specialistDate)
  await page.getByRole('button', { name: 'Сохранить отсутствие', exact: true }).click()
  await expect(page.getByText(/Отпуск ·/)).toBeVisible()

  expect(errors).toEqual([])
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
})
