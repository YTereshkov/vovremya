import { Outlet } from 'react-router-dom'

import { DesktopSidebar } from '@/app/layout/DesktopSidebar'
import { MobileNavigation } from '@/app/layout/MobileNavigation'
import { OfflineSnapshotSync } from '@/features/offline/snapshot'

export function AppShell() {
  return (
    <div className="app-backdrop min-h-screen text-ink lg:grid lg:grid-cols-[230px_minmax(0,1fr)]">
      <OfflineSnapshotSync />
      <DesktopSidebar />
      <main className="min-w-0 pb-24 lg:pb-0">
        <Outlet />
      </main>
      <MobileNavigation />
    </div>
  )
}
