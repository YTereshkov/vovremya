import { expect, test } from '@playwright/test'

test('redirects an unauthenticated visitor to login', async ({ page }) => {
  const consoleErrors: string[] = []
  const pageErrors: string[] = []
  const unauthorizedPaths: string[] = []
  page.on('console', (message) => {
    if (message.type() === 'error' && !message.text().includes('status of 401 (Unauthorized)')) {
      consoleErrors.push(message.text())
    }
  })
  page.on('pageerror', (error) => pageErrors.push(error.message))
  page.on('response', (response) => {
    if (response.status() === 401) {
      unauthorizedPaths.push(new URL(response.url()).pathname)
    }
  })

  await page.goto('/')

  await expect(page).toHaveTitle('Vovremya')
  await expect(page).toHaveURL(/\/login$/)
  await expect(page.getByRole('heading', { name: 'Вход в кабинет' })).toBeVisible()
  await expect(page.getByLabel('Email')).toBeVisible()
  await expect(page.getByLabel('Пароль')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Войти' })).toBeVisible()

  await page.getByLabel('Email').fill('missing@example.test')
  await page.getByLabel('Пароль').fill('wrong-password')
  await page.getByRole('button', { name: 'Войти' }).click()

  await expect(page.getByRole('alert')).toHaveText('Не удалось войти. Проверьте email и пароль.')
  expect(unauthorizedPaths).toEqual(expect.arrayContaining(['/api/me', '/api/login']))
  expect(consoleErrors).toEqual([])
  expect(pageErrors).toEqual([])
})
