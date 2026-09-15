import { expect, test } from '@playwright/test'

const run = process.env.PWA_E2E_RUN

test('installable PWA keeps a tenant-bound schedule read-only offline', async ({ page, context }, testInfo) => {
  test.skip(!run, 'Use bash bin/test-pwa-e2e to provision isolated disposable tenants.')
  const device = testInfo.project.name.startsWith('mobile') ? 'mobile' : 'desktop'
  const email = `calendar-e2e-${run}-${device}@example.test`

  await page.goto('/')
  await page.getByLabel('Email', { exact: true }).fill(email)
  await page.getByLabel('Пароль', { exact: true }).fill('Calendar-E2E-test-only')
  await page.getByRole('button', { name: 'Войти', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Сегодня', exact: true })).toBeVisible()
  await expect(page.getByRole('link', { name: /09:00, Петя Сидоров/ })).toBeVisible()

  const manifest = await page.evaluate(async () => {
    const response = await fetch('/manifest.webmanifest')
    return response.json() as Promise<{ display: string; icons: Array<{ sizes: string }> }>
  })
  expect(manifest.display).toBe('standalone')
  expect(manifest.icons.map((icon) => icon.sizes)).toEqual(expect.arrayContaining(['192x192', '512x512']))
  await expect.poll(() => page.evaluate(async (expectedEmail) => {
    await navigator.serviceWorker.ready
    const db = await new Promise<IDBDatabase>((resolve, reject) => {
      const request = indexedDB.open('vovremya-offline-v1')
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
    const state = await new Promise<{ identity?: { email: string }; snapshot?: { appointments: unknown[] } } | null>((resolve, reject) => {
      const request = db.transaction('state').objectStore('state').get('current')
      request.onsuccess = () => resolve(request.result ?? null)
      request.onerror = () => reject(request.error)
    })
    return state?.identity?.email === expectedEmail && Boolean(state.snapshot?.appointments.length)
  }, email)).toBe(true)

  await page.reload()
  await expect(page.getByRole('heading', { name: 'Сегодня', exact: true })).toBeVisible()
  await page.getByRole('link', { name: 'Календарь', exact: true }).first().click()
  await expect(page.getByRole('heading', { name: 'Календарь', exact: true })).toBeVisible()
  expect(await page.evaluate(async () => Boolean(navigator.serviceWorker.controller && await caches.match('/')))).toBe(true)

  await context.setOffline(true)
  if (device === 'desktop') await page.reload()
  await expect(page.getByRole('heading', { name: 'Календарь', exact: true })).toBeVisible()
  await expect(page.getByText('Нет подключения к интернету')).toBeVisible()
  await expect(page.getByText('Доступно: 7 прошедших дней, сегодня и 30 дней вперёд')).toBeVisible()
  await expect(page.getByText('Только просмотр. Изменения доступны после подключения.')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Новое занятие' })).toBeDisabled()
  await expect(page.getByRole('link', { name: /09:00, Петя Сидоров/ })).toHaveCount(0)
  if (device === 'desktop') await expect(page.getByRole('region', { name: 'Календарь дня' })).toContainText('Петя Сидоров')
  else await expect(page.getByRole('region', { name: 'Список занятий' })).toContainText('Петя Сидоров')

  if (device === 'desktop') {
    await page.goto('/appointments/new')
    await expect(page).toHaveURL(/\/calendar$/)
  }
  await page.getByLabel('Дата календаря').fill('2099-01-01')
  await expect(page.getByText('За выбранный период нет сохранённых данных.')).toBeVisible()

  const refreshed = page.waitForResponse((response) => response.url().includes('/api/calendar?') && response.ok())
  await context.setOffline(false)
  await page.evaluate(() => window.dispatchEvent(new Event('online')))
  await refreshed
  await expect(page.getByText('Нет подключения к интернету')).toHaveCount(0)
  await expect(page.getByRole('link', { name: 'Новое занятие' })).toBeVisible()

  if (device === 'desktop') {
    await page.route('**/api/**', (route) => route.abort('failed'))
    await page.getByRole('button', { name: 'Сегодня', exact: true }).click()
    await expect(page.getByText('Нет подключения к интернету')).toBeVisible()
    await page.unroute('**/api/**')
    await expect(page.getByText('Нет подключения к интернету')).toHaveCount(0, { timeout: 15_000 })
    await expect(page.getByRole('link', { name: 'Новое занятие' })).toBeVisible()

    const firstOrganization = await page.evaluate(async () => {
      const db = await new Promise<IDBDatabase>((resolve) => {
        const request = indexedDB.open('vovremya-offline-v1')
        request.onsuccess = () => resolve(request.result)
      })
      return new Promise<string>((resolve) => {
        const request = db.transaction('state').objectStore('state').get('current')
        request.onsuccess = () => resolve(request.result.identity.organization.id)
      })
    })
    await page.getByRole('button', { name: 'Выйти' }).click()
    await expect(page.getByRole('button', { name: 'Войти', exact: true })).toBeVisible()
    await expect.poll(() => page.evaluate(async () => {
      const db = await new Promise<IDBDatabase>((resolve) => {
        const request = indexedDB.open('vovremya-offline-v1')
        request.onsuccess = () => resolve(request.result)
      })
      return new Promise<boolean>((resolve) => {
        const request = db.transaction('state').objectStore('state').get('current')
        request.onsuccess = () => resolve(request.result === undefined)
      })
    })).toBe(true)
    await page.getByLabel('Email', { exact: true }).fill(`calendar-e2e-${run}-mobile@example.test`)
    await page.getByLabel('Пароль', { exact: true }).fill('Calendar-E2E-test-only')
    await page.getByRole('button', { name: 'Войти', exact: true }).click()
    await expect(page.getByText(`calendar-e2e-${run}-mobile@example.test`)).toBeVisible()
    await expect.poll(() => page.evaluate(async (previousOrganization) => {
      const db = await new Promise<IDBDatabase>((resolve) => {
        const request = indexedDB.open('vovremya-offline-v1')
        request.onsuccess = () => resolve(request.result)
      })
      return new Promise<boolean>((resolve) => {
        const request = db.transaction('state').objectStore('state').get('current')
        request.onsuccess = () => resolve(request.result?.identity.organization.id !== previousOrganization
          && request.result?.snapshot?.organizationId === request.result?.identity.organization.id)
      })
    }, firstOrganization)).toBe(true)
  }
})
