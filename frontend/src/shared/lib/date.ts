export function formatNumericDate(value: string): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (!match) return value

  return `${match[3]}.${match[2]}.${match[1]}`
}

export function formatNumericDateTime(value: string, timeZone: string): string {
  const parts = new Intl.DateTimeFormat('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
    timeZone,
  }).formatToParts(new Date(value))
  const values = Object.fromEntries(parts.map((part) => [part.type, part.value]))

  return `${values.day}.${values.month}.${values.year} · ${values.hour}:${values.minute}`
}
