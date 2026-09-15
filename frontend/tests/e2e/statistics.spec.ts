import { expect, test } from '@playwright/test'

const run = process.env.STATISTICS_E2E_RUN

test('statistics screen: month, specialist filter and responsive layout', async ({ page }, testInfo) => {
  test.skip(!run, 'Use bash bin/test-statistics-e2e to provision isolated disposable tenants.')
  const device = testInfo.project.name.startsWith('mobile') ? 'mobile' : 'desktop'
  const pageErrors: string[] = []
  page.on('pageerror', (error) => pageErrors.push(error.message))

  await page.goto('/statistics')
  await page.getByLabel('Email', { exact: true }).fill(`calendar-e2e-${run}-${device}@example.test`)
  await page.getByLabel('Пароль', { exact: true }).fill('Calendar-E2E-test-only')
  const [initialResponse] = await Promise.all([
    page.waitForResponse((response) => new URL(response.url()).pathname === '/api/statistics' && response.ok()),
    page.getByRole('button', { name: 'Войти', exact: true }).click(),
  ])
  const initial = await initialResponse.json() as { month: string; appointments: { planned: number }; specialists: Array<{ id: string; name: string }> }
  await expect(page.getByRole('heading', { name: 'Статистика', exact: true })).toBeVisible()
  for (const title of ['Занятия', 'Изменения расписания', 'Подтверждения', 'Лист ожидания']) {
    await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible()
  }
  await expect(page.getByText('Запланировано', { exact: true }).locator('..').locator('strong')).toHaveText(String(initial.appointments.planned))

  if (device === 'desktop') {
    await expect(page.getByRole('combobox', { name: 'Специалист', exact: true })).toBeVisible()
    expect(initial.specialists).toHaveLength(2)
    const [filteredResponse] = await Promise.all([
      page.waitForResponse((response) => new URL(response.url()).pathname === '/api/statistics' && new URL(response.url()).searchParams.get('specialistId') === initial.specialists[0].id && response.ok()),
      page.getByRole('combobox', { name: 'Специалист', exact: true }).selectOption(initial.specialists[0].id),
    ])
    const filtered = await filteredResponse.json() as { appointments: { planned: number } }
    await expect(page.getByText('Запланировано', { exact: true }).locator('..').locator('strong')).toHaveText(String(filtered.appointments.planned))
  } else {
    await expect(page.getByRole('combobox', { name: 'Специалист', exact: true })).toHaveCount(0)
    expect(initial.specialists).toHaveLength(1)
  }

  const [nextResponse] = await Promise.all([
    page.waitForResponse((response) => new URL(response.url()).pathname === '/api/statistics' && new URL(response.url()).searchParams.get('month') !== initial.month && response.ok()),
    page.getByRole('button', { name: 'Следующий месяц' }).click(),
  ])
  const next = await nextResponse.json() as { month: string; appointments: { planned: number } }
  expect(next.month).not.toBe(initial.month)
  await expect(page.getByText('Запланировано', { exact: true }).locator('..').locator('strong')).toHaveText(String(next.appointments.planned))
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  expect(pageErrors).toEqual([])
})
