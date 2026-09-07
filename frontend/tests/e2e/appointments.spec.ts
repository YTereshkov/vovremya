import { expect, test } from '@playwright/test'

const run = process.env.APPOINTMENTS_E2E_RUN

test('creates one-off appointments and distinguishes soft warnings from hard conflicts', async ({ page }, testInfo) => {
  test.skip(!run, 'Use bash bin/test-appointments-e2e to provision isolated disposable tenants.')
  const device = testInfo.project.name.startsWith('mobile') ? 'mobile' : 'desktop'
  const pageErrors: string[] = []
  page.on('pageerror', (error) => pageErrors.push(error.message))

  await page.goto('/calendar')
  await page.getByLabel('Email', { exact: true }).fill(`appointments-e2e-${run}-${device}@example.test`)
  await page.getByLabel('Пароль', { exact: true }).fill('Appointments-E2E-test-only')
  await page.getByRole('button', { name: 'Войти', exact: true }).click()
  await page.getByRole('link', { name: 'Новое занятие', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Новое занятие', exact: true })).toBeVisible()
  await expect(page.getByLabel('Специалист')).toHaveCount(0)
  await expect(page.getByLabel('Клиент')).toHaveValue(/.+/)
  await expect(page.getByLabel('Услуга')).toHaveValue(/.+/)
  await expect(page.getByLabel('Продолжительность')).toHaveValue('45')
  await page.getByLabel('Дата').fill('2026-09-07')

  await page.getByLabel('Начало').fill('10:00')
  await page.getByRole('button', { name: 'Создать занятие', exact: true }).click()
  await expect(page.getByRole('status')).toContainText('создано')

  await page.getByLabel('Начало').fill('10:55')
  await page.getByRole('button', { name: 'Создать занятие', exact: true }).click()
  const breakDialog = page.getByRole('dialog', { name: 'Предупреждение о времени' })
  await expect(breakDialog.getByRole('heading', { name: 'Между занятиями останется 10 минут' })).toBeVisible()
  await breakDialog.getByRole('button', { name: 'Всё равно создать', exact: true }).click()
  await expect(breakDialog).not.toBeVisible()

  await page.getByLabel('Начало').fill('13:00')
  await page.getByRole('button', { name: 'Создать занятие', exact: true }).click()
  const lunchDialog = page.getByRole('dialog', { name: 'Предупреждение о времени' })
  await expect(lunchDialog.getByRole('heading', { name: 'Занятие пересекается с обедом' })).toBeVisible()
  await lunchDialog.getByRole('button', { name: 'Всё равно создать', exact: true }).click()
  await expect(lunchDialog).not.toBeVisible()

  await page.getByLabel('Начало').fill('10:15')
  await page.getByRole('button', { name: 'Создать занятие', exact: true }).click()
  const hardDialog = page.getByRole('dialog', { name: 'Время недоступно' })
  await expect(hardDialog.getByRole('heading', { name: 'Время недоступно' })).toBeVisible()
  await expect(hardDialog.getByText('Время уже недоступно.')).toBeVisible()
  await expect(hardDialog.getByRole('button', { name: 'Всё равно создать' })).toHaveCount(0)

  expect(pageErrors).toEqual([])
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
})
