const dateFormatterCache = new Map<string, Intl.DateTimeFormat>()

export function todayInTimezone(timezone: string): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: timezone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date())
  const value = Object.fromEntries(parts.map((part) => [part.type, part.value]))
  return `${value.year}-${value.month}-${value.day}`
}

export function shiftDate(date: string, days: number): string {
  const value = new Date(`${date}T00:00:00Z`)
  value.setUTCDate(value.getUTCDate() + days)
  return value.toISOString().slice(0, 10)
}

export function startOfWeek(date: string): string {
  const weekday = new Date(`${date}T00:00:00Z`).getUTCDay()
  return shiftDate(date, -((weekday + 6) % 7))
}

export function weekDates(date: string): string[] {
  const start = startOfWeek(date)
  return Array.from({ length: 7 }, (_, index) => shiftDate(start, index))
}

export function formatDate(date: string, options: Intl.DateTimeFormatOptions): string {
  const key = JSON.stringify(options)
  let formatter = dateFormatterCache.get(key)
  if (!formatter) {
    formatter = new Intl.DateTimeFormat('ru-RU', { ...options, timeZone: 'UTC' })
    dateFormatterCache.set(key, formatter)
  }
  return formatter.format(new Date(`${date}T00:00:00Z`))
}

export function longDate(date: string): string {
  return formatDate(date, { weekday: 'long', day: 'numeric', month: 'long' })
}
