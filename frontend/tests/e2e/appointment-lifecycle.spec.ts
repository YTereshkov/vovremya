import { expect, test } from '@playwright/test'

const run = process.env.LIFECYCLE_E2E_RUN

test('records client cancellation and exposes one managed free window', async ({ page }, testInfo) => {
  test.skip(!run, 'Use bash bin/test-lifecycle-e2e to provision isolated disposable tenants.')
  const device = testInfo.project.name.startsWith('mobile') ? 'mobile' : 'desktop'
  const pageErrors: string[] = []
  page.on('pageerror', (error) => pageErrors.push(error.message))

  await page.goto('/settings')
  await page.getByLabel('Email', { exact: true }).fill(`appointments-e2e-${run}-${device}@example.test`)
  await page.getByLabel('Пароль', { exact: true }).fill('Appointments-E2E-test-only')
  await page.getByRole('button', { name: 'Войти', exact: true }).click()
  const lateCancellation = page.getByLabel('Поздняя отмена — менее чем за')
  await expect(lateCancellation).toHaveValue('12')
  await lateCancellation.fill('24')
  await Promise.all([
    page.waitForResponse((response) => response.url().endsWith('/api/scheduling/settings') && response.request().method() === 'PUT' && response.ok()),
    lateCancellation.locator('xpath=ancestor::form').getByRole('button', { name: 'Сохранить', exact: true }).click(),
  ])
  await page.reload()
  await expect(lateCancellation).toHaveValue('24')

  const nextMonday = new Date()
  nextMonday.setUTCDate(nextMonday.getUTCDate() + ((8 - nextMonday.getUTCDay()) % 7 || 7))
  const appointmentDate = nextMonday.toISOString().slice(0, 10)
  await page.goto('/appointments/new')
  await page.getByLabel('Дата').fill(appointmentDate)
  await page.getByLabel('Начало').fill('15:00')
  await page.getByRole('button', { name: 'Создать занятие', exact: true }).click()
  await page.getByRole('link', { name: 'Открыть занятие', exact: true }).click()
  await Promise.all([
    page.waitForResponse((response) => /\/api\/appointments\/[^/]+\/result$/.test(new URL(response.url()).pathname) && response.request().method() === 'GET' && response.ok()),
    page.getByRole('link', { name: 'Указать результат', exact: true }).click(),
  ])
  await expect(page.getByRole('heading', { name: 'Результат занятия', exact: true })).toBeVisible()
  await expect(page.getByLabel('Отменено клиентом', { exact: true })).toBeChecked()
  await page.getByLabel('Создать разовое свободное окно').check()
  await page.getByLabel('Комментарий').fill('Клиент предупредил заранее')
  await page.getByRole('button', { name: 'Сохранить результат', exact: true }).click()
  await expect(page.getByText('Отменено клиентом', { exact: true })).toBeVisible()
  await expect(page.getByText('Клиент предупредил заранее', { exact: true })).toBeVisible()
  await expect(page.getByText('Результат занятия изменён', { exact: true })).toBeVisible()

  let candidateRequests = 0
  page.on('request', (request) => {
    if (/\/api\/free-windows\/[^/]+\/candidates$/.test(new URL(request.url()).pathname)) candidateRequests++
  })
  await page.goto('/waiting')
  await expect(page.getByRole('heading', { name: 'Ожидание', exact: true })).toBeVisible()
  await expect(page.getByText('Логопедическое занятие', { exact: true })).toBeVisible()
  await expect(page.getByText('15:00–15:45', { exact: true })).toBeVisible()
  expect(candidateRequests).toBe(0)

  await Promise.all([
    page.waitForResponse((response) => /\/api\/free-windows\/[^/]+\/candidates$/.test(new URL(response.url()).pathname) && response.ok()),
    page.getByRole('button', { name: 'Показать подходящих клиентов', exact: true }).click(),
  ])
  expect(candidateRequests).toBe(1)
  await expect(page.getByRole('button', { name: 'Скрыть подходящих клиентов', exact: true })).toBeVisible()

  expect(pageErrors).toEqual([])
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
})
