export const templateDemoValues: Record<string, string> = {
  date: '18.09.2026',
  time: '14:30',
  service: 'Логопедическое занятие',
  client_name: 'Петя Сидоров',
  contact_name: 'Анна Сидорова',
}

export function renderTemplatePreview(body: string): string {
  return body.replace(/\{([a-z_]+)\}/g, (placeholder, name: string) => templateDemoValues[name] ?? placeholder)
}
