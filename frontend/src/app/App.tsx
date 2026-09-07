import { Navigate, Route, Routes } from 'react-router-dom'

import { AppShell } from '@/app/layout/AppShell'
import { RequireAuthentication } from '@/features/auth/RequireAuthentication'
import { SectionPlaceholderPage } from '@/pages/SectionPlaceholderPage'
import { TodayPage } from '@/pages/TodayPage'
import { LoginPage } from '@/pages/LoginPage'
import { SettingsPage } from '@/pages/SettingsPage'
import { SpecialistsPage } from '@/pages/SpecialistsPage'
import { SpecialistPage } from '@/pages/SpecialistPage'
import { WorkingHoursPage, MySchedulePage } from '@/pages/WorkingHoursPage'

export function App() {
  return (
    <Routes>
      <Route path="login" element={<LoginPage />} />
      <Route element={<RequireAuthentication />}>
        <Route element={<AppShell />}>
          <Route index element={<TodayPage />} />
          <Route path="calendar" element={<SectionPlaceholderPage title="Календарь" />} />
          <Route path="clients" element={<SectionPlaceholderPage title="Клиенты" />} />
          <Route path="waiting" element={<SectionPlaceholderPage title="Ожидание" />} />
          <Route path="notifications" element={<SectionPlaceholderPage title="Уведомления" />} />
          <Route path="specialists" element={<SpecialistsPage />} />
          <Route path="specialists/:id" element={<SpecialistPage />} />
          <Route path="specialists/:id/hours" element={<WorkingHoursPage />} />
          <Route path="statistics" element={<SectionPlaceholderPage title="Статистика" />} />
          <Route path="settings" element={<SettingsPage />} />
          <Route path="my-schedule" element={<MySchedulePage />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Route>
    </Routes>
  )
}
