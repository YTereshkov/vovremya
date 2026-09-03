import { Navigate, Route, Routes } from 'react-router-dom'

import { AppShell } from '@/app/layout/AppShell'
import { SectionPlaceholderPage } from '@/pages/SectionPlaceholderPage'
import { TodayPage } from '@/pages/TodayPage'

export function App() {
  return (
    <Routes>
      <Route element={<AppShell />}>
        <Route index element={<TodayPage />} />
        <Route path="calendar" element={<SectionPlaceholderPage title="Календарь" />} />
        <Route path="clients" element={<SectionPlaceholderPage title="Клиенты" />} />
        <Route path="waiting" element={<SectionPlaceholderPage title="Ожидание" />} />
        <Route path="notifications" element={<SectionPlaceholderPage title="Уведомления" />} />
        <Route path="specialists" element={<SectionPlaceholderPage title="Специалисты" />} />
        <Route path="statistics" element={<SectionPlaceholderPage title="Статистика" />} />
        <Route path="settings" element={<SectionPlaceholderPage title="Настройки" />} />
        <Route path="my-schedule" element={<SectionPlaceholderPage title="Моё расписание" />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Route>
    </Routes>
  )
}
