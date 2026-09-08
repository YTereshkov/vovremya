import { expect, test } from '@playwright/test'

const run = process.env.REGULAR_SCHEDULES_E2E_RUN
const weekdayNames = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота']

function tomorrowInMoscow(): { date: string; weekday: number; followingWeekday: number } {
  const now = new Date()
  const local = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Moscow', year: 'numeric', month: '2-digit', day: '2-digit' }).format(now)
  const tomorrow = new Date(`${local}T12:00:00Z`)
  tomorrow.setUTCDate(tomorrow.getUTCDate() + 1)
  const date = tomorrow.toISOString().slice(0, 10)
  const jsWeekday = tomorrow.getUTCDay()
  const followingJsWeekday = (jsWeekday + 1) % 7

  return {
    date,
    weekday: jsWeekday === 0 ? 7 : jsWeekday,
    followingWeekday: followingJsWeekday === 0 ? 7 : followingJsWeekday,
  }
}

test('creates and manages a regular schedule', async ({ page }, testInfo) => {
  test.skip(!run, 'Use bash bin/test-regular-schedules-e2e to provision isolated disposable tenants.')
  const device = testInfo.project.name.startsWith('mobile') ? 'mobile' : 'desktop'
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))
  const tomorrow = tomorrowInMoscow()

  await page.goto('/regular-schedules/new')
  await page.getByLabel('Email', { exact: true }).fill(`calendar-e2e-${run}-${device}@example.test`)
  await page.getByLabel('Пароль', { exact: true }).fill('Calendar-E2E-test-only')
  await page.getByRole('button', { name: 'Войти', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Новое занятие', exact: true })).toBeVisible()
  if (device === 'desktop') await page.getByLabel('Специалист').selectOption({ label: 'Юлия Иванова' })
  await page.getByLabel('Начало расписания').fill(tomorrow.date)
  await page.getByLabel('День недели').selectOption(String(tomorrow.weekday))
  await page.getByLabel(`Время, ${weekdayNames[new Date(`${tomorrow.date}T12:00:00Z`).getUTCDay()]}`).fill('16:00')
  await page.getByRole('button', { name: 'Добавить день', exact: true }).click()
  await page.getByLabel('День недели').nth(1).selectOption(String(tomorrow.followingWeekday))
  await page.getByLabel(/Время,/).nth(1).fill('17:30')
  await page.getByLabel(/Продолжительность,/).nth(1).fill('60')
  await page.getByRole('button', { name: 'Создать расписание', exact: true }).click()

  await expect(page.getByRole('status')).toContainText('создано')
  await page.getByRole('link', { name: 'Открыть расписание', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Регулярное расписание', exact: true })).toBeVisible()
  await expect(page.getByText('16:00 · 45 минут', { exact: true })).toBeVisible()
  await expect(page.getByText('17:30 · 60 минут', { exact: true })).toBeVisible()

  await page.getByLabel(/Изменить /).first().click()
  const edit = page.getByRole('dialog', { name: /Изменить / })
  await edit.getByLabel('Новое время').fill('16:30')
  await edit.getByRole('button', { name: 'Сохранить', exact: true }).click()
  await expect(page.getByText('16:30 · 45 минут', { exact: true })).toBeVisible()

  await page.getByLabel(/Удалить /).last().click()
  const remove = page.getByRole('dialog', { name: /Удалить .* из расписания/ })
  await remove.getByRole('button', { name: 'Подтвердить', exact: true }).click()
  await expect(page.getByText('17:30 · 60 минут', { exact: true })).toHaveCount(0)

  await page.getByRole('button', { name: 'Завершить', exact: true }).click()
  const finish = page.getByRole('dialog', { name: 'Завершить регулярные занятия' })
  await finish.getByRole('button', { name: 'Подтвердить', exact: true }).click()
  await expect(page.getByText('Расписание завершено', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Завершить', exact: true })).toHaveCount(0)

  expect(errors).toEqual([])
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
})
