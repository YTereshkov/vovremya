import { Navigate, Route, Routes } from 'react-router-dom'

import { AppShell } from '@/app/layout/AppShell'
import { ScrollToTop } from '@/app/ScrollToTop'
import { RequireAuthentication } from '@/features/auth/RequireAuthentication'
import { SectionPlaceholderPage } from '@/pages/SectionPlaceholderPage'
import { TodayPage } from '@/pages/TodayPage'
import { LoginPage } from '@/pages/LoginPage'
import { SettingsPage } from '@/pages/SettingsPage'
import { SpecialistsPage } from '@/pages/SpecialistsPage'
import { SpecialistPage } from '@/pages/SpecialistPage'
import { WorkingHoursPage, MySchedulePage } from '@/pages/WorkingHoursPage'
import { ServicesPage } from '@/pages/ServicesPage'
import { ServiceFormPage } from '@/pages/ServiceFormPage'
import { ClientsPage } from '@/pages/ClientsPage'
import { ClientFormPage } from '@/pages/ClientFormPage'
import { ClientPage } from '@/pages/ClientPage'
import { AppointmentFormPage } from '@/pages/AppointmentFormPage'
import { AppointmentPage } from '@/pages/AppointmentPage'
import { CalendarPage } from '@/pages/CalendarPage'

export function App() {
  return (
    <>
      <ScrollToTop />
      <Routes>
      <Route path="login" element={<LoginPage />} />
      <Route element={<RequireAuthentication />}>
        <Route path="appointments/new" element={<AppointmentFormPage />} />
        <Route element={<AppShell />}>
          <Route index element={<TodayPage />} />
          <Route path="calendar" element={<CalendarPage />} />
          <Route path="appointments/:id" element={<AppointmentPage />} />
          <Route path="clients" element={<ClientsPage />} />
          <Route path="clients/new" element={<ClientFormPage />} />
          <Route path="clients/:id" element={<ClientPage />} />
          <Route path="clients/:id/edit" element={<ClientFormPage />} />
          <Route path="waiting" element={<SectionPlaceholderPage title="Ожидание" />} />
          <Route path="notifications" element={<SectionPlaceholderPage title="Уведомления" />} />
          <Route path="specialists" element={<SpecialistsPage />} />
          <Route path="specialists/:id" element={<SpecialistPage />} />
          <Route path="specialists/:id/hours" element={<WorkingHoursPage />} />
          <Route path="statistics" element={<SectionPlaceholderPage title="Статистика" />} />
          <Route path="settings" element={<SettingsPage />} />
          <Route path="settings/services" element={<ServicesPage />} />
          <Route path="settings/services/new" element={<ServiceFormPage />} />
          <Route path="settings/services/:id" element={<ServiceFormPage />} />
          <Route path="my-schedule" element={<MySchedulePage />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Route>
      </Routes>
    </>
  )
}
