import { expect, test } from '@playwright/test'

const run = process.env.DIRECTORY_E2E_RUN

test('manages services and creates a client with a contact recipient', async ({ page }, testInfo) => {
  test.skip(!run, 'Use bash bin/test-catalog-clients-e2e to provision isolated disposable tenants.')
  const device = testInfo.project.name.startsWith('mobile') ? 'mobile' : 'desktop'
  const pageErrors: string[] = []
  page.on('pageerror', (error) => pageErrors.push(error.message))

  await page.goto('/settings/services')
  await page.getByLabel('Email', { exact: true }).fill(`directory-e2e-${run}-${device}@example.test`)
  await page.getByLabel('Пароль', { exact: true }).fill('Directory-E2E-test-only')
  await page.getByRole('button', { name: 'Войти', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Услуги', exact: true })).toBeVisible()

  await page.getByRole('link', { name: 'Услуга', exact: true }).click()
  await page.getByLabel('Название').fill('Диагностика речи')
  await page.getByLabel('По умолчанию, минут').fill('80')
  await page.getByLabel('Минимум, минут').fill('60')
  await page.getByLabel('Максимум, минут').fill('90')
  await page.getByRole('button', { name: 'Добавить услугу' }).click()
  await expect(page.getByText('80 минут · диапазон 60–90')).toBeVisible()
  await page.getByRole('link', { name: /Диагностика речи/ }).click()
  await page.getByLabel('Название').fill('Первичная диагностика')
  await page.getByRole('button', { name: 'Сохранить', exact: true }).click()
  await expect(page.getByText('Первичная диагностика', { exact: true })).toBeVisible()

  await page.goto('/clients/new')
  await page.getByLabel('Имя клиента').fill('Петя Сидоров')
  await page.getByLabel('Добавить контактное лицо').check()
  await page.getByLabel('Имя контактного лица').fill('Анна Сидорова')
  await page.getByLabel('Телефон контактного лица').fill('+7 999 123-45-67')
  await page.getByLabel('Добавить основной канал').check()
  await page.getByLabel('Получатель').selectOption({ label: 'Контактное лицо' })
  await page.getByRole('button', { name: 'Telegram', exact: true }).click()
  await page.getByLabel('Телефон или адрес канала').fill('@anna_sidorova')
  await page.getByRole('button', { name: 'Сохранить клиента' }).click()
  await expect(page.getByRole('heading', { name: 'Петя Сидоров', exact: true })).toBeVisible()
  await expect(page.getByText('Анна Сидорова', { exact: true })).toBeVisible()
  await expect(page.getByText('Основной', { exact: true })).toBeVisible()
  await expect(page.getByText('@anna_sidorova · Анна Сидорова', { exact: true })).toBeVisible()

  await page.getByRole('link', { name: 'Назад', exact: true }).click()
  await page.getByLabel('Поиск клиента').fill('Петя')
  await expect(page.getByRole('link', { name: /Петя Сидоров/ })).toBeVisible()
  await page.getByRole('link', { name: /Петя Сидоров/ }).click()
  await expect(page.getByRole('heading', { name: 'Петя Сидоров', exact: true })).toBeVisible()

  await page.goto('/settings/services')
  await page.getByRole('link', { name: /Первичная диагностика/ }).click()
  await page.getByRole('button', { name: 'Удалить услугу', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Удалить', exact: true }).click()
  await expect(page.getByText('Пока нет услуг. Добавьте первую.')).toBeVisible()
  await expect(page.getByText('Архив')).toHaveCount(0)

  expect(pageErrors).toEqual([])
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
})
