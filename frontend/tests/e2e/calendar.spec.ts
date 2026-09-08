import { expect, test } from '@playwright/test'

const run = process.env.CALENDAR_E2E_RUN

test('calendar vertical slice: today, responsive views, filters, create and open', async ({ page }, testInfo) => {
  test.skip(!run, 'Use bash bin/test-calendar-e2e to provision isolated disposable tenants.')
  const device = testInfo.project.name.startsWith('mobile') ? 'mobile' : 'desktop'
  const consoleErrors: string[] = []
  const pageErrors: string[] = []
  page.on('console', (message) => {
    if (message.type() === 'error' && !message.text().includes('status of 401 (Unauthorized)')) consoleErrors.push(message.text())
  })
  page.on('pageerror', (error) => pageErrors.push(error.message))

  await page.goto('/')
  await page.getByLabel('Email', { exact: true }).fill(`calendar-e2e-${run}-${device}@example.test`)
  await page.getByLabel('Пароль', { exact: true }).fill('Calendar-E2E-test-only')
  await page.getByRole('button', { name: 'Войти', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Сегодня', exact: true })).toBeVisible()
  await expect(page.getByRole('link', { name: /09:00, Петя Сидоров/ })).toBeVisible()
  await expect(page.getByRole('link', { name: /09:00, Петя Сидоров/ })).toContainText('Логопедическое занятие')
  if (device === 'mobile') {
    await expect(page.getByText('Юлия Иванова · Логопед', { exact: true })).toBeVisible()
  }

  await page.getByRole('link', { name: 'Календарь', exact: true }).first().click()
  await expect(page.getByRole('heading', { name: 'Календарь', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'День', exact: true })).toHaveAttribute('aria-pressed', 'true')
  if (device === 'desktop') {
    await expect(page.getByRole('region', { name: 'Календарь дня' })).toBeVisible()
    await page.getByLabel('Фильтр специалиста').selectOption({ label: 'Анна Петрова' })
    await expect(page.getByRole('link', { name: /10:30, Петя Сидоров/ })).toBeVisible()
    await expect(page.getByRole('link', { name: /09:00, Петя Сидоров/ })).toHaveCount(0)
    await page.getByLabel('Фильтр специалиста').selectOption('')
  } else {
    await expect(page.getByLabel('Фильтр специалиста')).toHaveCount(0)
    await expect(page.getByRole('region', { name: 'Список занятий' })).toBeVisible()
  }

  await page.getByRole('button', { name: 'Неделя', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Неделя', exact: true })).toHaveAttribute('aria-pressed', 'true')
  if (device === 'desktop') {
    await expect(page.getByRole('region', { name: 'Календарь недели' })).toBeVisible()
    await page.getByRole('button', { name: 'На весь экран' }).click()
    await expect(page.getByRole('button', { name: 'Выйти из полноэкранного режима' })).toBeVisible()
    await page.getByRole('button', { name: 'Выйти из полноэкранного режима' }).click()
  } else {
    await expect(page.getByText('Занятий нет', { exact: true }).first()).toBeVisible()
    const laterAppointment = page.getByRole('link', { name: /11:30, Петя Сидоров/ })
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
    expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(0)
    await laterAppointment.click()
    await expect(page.getByRole('heading', { name: 'Занятие', exact: true })).toBeVisible()
    await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(0)
    await page.getByRole('link', { name: 'Назад', exact: true }).click()
    await expect(page.getByRole('heading', { name: 'Календарь', exact: true })).toBeVisible()
  }

  await page.getByRole('link', { name: 'Новое занятие', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Новое занятие', exact: true })).toBeVisible()
  if (device === 'desktop') await page.getByLabel('Специалист').selectOption({ label: 'Юлия Иванова' })
  await page.getByLabel('Начало').fill('15:00')
  await page.getByRole('button', { name: 'Создать занятие', exact: true }).click()
  const openAppointment = page.getByRole('link', { name: 'Открыть занятие', exact: true })
  await expect(openAppointment).toBeVisible()
  await openAppointment.click()
  await expect(page.getByRole('heading', { name: 'Занятие', exact: true })).toBeVisible()
  await expect(page.getByText('15:00', { exact: true })).toBeVisible()
  await expect(page.getByText('Петя Сидоров', { exact: true })).toBeVisible()
  await expect(page.getByText('Логопедическое занятие', { exact: true })).toBeVisible()
  await expect(page.getByText('Данные услуги на момент записи', { exact: true })).toBeVisible()

  expect(pageErrors).toEqual([])
  expect(consoleErrors).toEqual([])
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
})
