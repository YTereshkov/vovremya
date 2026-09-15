import { Navigate, Route, Routes } from 'react-router-dom'

import { AppShell } from '@/app/layout/AppShell'
import { ScrollToTop } from '@/app/ScrollToTop'
import { RequireAuthentication } from '@/features/auth/RequireAuthentication'
import { HealthProbe } from '@/features/health/HealthProbe'
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
import { AppointmentResultPage } from '@/pages/AppointmentResultPage'
import { CalendarPage } from '@/pages/CalendarPage'
import { RegularScheduleFormPage } from '@/pages/RegularScheduleFormPage'
import { RegularSchedulePage } from '@/pages/RegularSchedulePage'
import { MessageTemplatesPage } from '@/pages/MessageTemplatesPage'
import { NotificationsPage } from '@/pages/NotificationsPage'
import { WaitingPage } from '@/pages/WaitingPage'
import { SpecialistAbsencePage } from '@/pages/SpecialistAbsencePage'
import { ClientAbsencePage } from '@/pages/ClientAbsencePage'
import { AppointmentTransferPage } from '@/pages/AppointmentTransferPage'
import { WaitingListFormPage } from '@/pages/WaitingListFormPage'
import { DeliveryReportPage } from '@/pages/DeliveryReportPage'
import { StatisticsPage } from '@/pages/StatisticsPage'

export function App() {
  return (
    <>
      <ScrollToTop />
      <HealthProbe />
      <Routes>
      <Route path="login" element={<LoginPage />} />
      <Route element={<RequireAuthentication />}>
        <Route path="appointments/new" element={<AppointmentFormPage />} />
        <Route path="appointments/:id/transfer" element={<AppointmentTransferPage />} />
        <Route path="clients/:id/waiting-list" element={<WaitingListFormPage />} />
        <Route path="regular-schedules/new" element={<RegularScheduleFormPage />} />
        <Route element={<AppShell />}>
          <Route index element={<TodayPage />} />
          <Route path="calendar" element={<CalendarPage />} />
          <Route path="appointments/:id" element={<AppointmentPage />} />
          <Route path="appointments/:id/result" element={<AppointmentResultPage />} />
          <Route path="regular-schedules/:id" element={<RegularSchedulePage />} />
          <Route path="clients" element={<ClientsPage />} />
          <Route path="clients/new" element={<ClientFormPage />} />
          <Route path="clients/:id" element={<ClientPage />} />
          <Route path="clients/:id/edit" element={<ClientFormPage />} />
          <Route path="clients/:id/absence" element={<ClientAbsencePage />} />
          <Route path="waiting" element={<WaitingPage />} />
          <Route path="notifications" element={<NotificationsPage />} />
          <Route path="notifications/delivery" element={<DeliveryReportPage />} />
          <Route path="notifications/delivery/:absenceId" element={<DeliveryReportPage />} />
          <Route path="specialists" element={<SpecialistsPage />} />
          <Route path="specialists/:id" element={<SpecialistPage />} />
          <Route path="specialists/:id/hours" element={<WorkingHoursPage />} />
          <Route path="specialists/:id/absence" element={<SpecialistAbsencePage />} />
          <Route path="statistics" element={<StatisticsPage />} />
          <Route path="more" element={<Navigate to="/" replace />} />
          <Route path="settings" element={<SettingsPage />} />
          <Route path="settings/services" element={<ServicesPage />} />
          <Route path="settings/services/new" element={<ServiceFormPage />} />
          <Route path="settings/services/:id" element={<ServiceFormPage />} />
          <Route path="settings/message-templates" element={<MessageTemplatesPage />} />
          <Route path="my-schedule" element={<MySchedulePage />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Route>
      </Routes>
    </>
  )
}
