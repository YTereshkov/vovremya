import {
  BarChart3,
  Bell,
  CalendarCheck2,
  CalendarDays,
  ClipboardList,
  Hourglass,
  Menu,
  Settings,
  UserRound,
  UsersRound,
  type LucideIcon,
} from 'lucide-react'

export interface NavigationItem {
  label: string
  to: string
  icon: LucideIcon
  badge?: number
}

export const desktopNavigation: NavigationItem[] = [
  { label: 'Сегодня', to: '/', icon: CalendarCheck2 },
  { label: 'Календарь', to: '/calendar', icon: CalendarDays },
  { label: 'Клиенты', to: '/clients', icon: UsersRound },
  { label: 'Ожидание', to: '/waiting', icon: Hourglass },
  { label: 'Уведомления', to: '/notifications', icon: Bell },
  { label: 'Специалисты', to: '/specialists', icon: UserRound },
  { label: 'Статистика', to: '/statistics', icon: BarChart3 },
  { label: 'Услуги', to: '/settings/services', icon: ClipboardList },
  { label: 'Настройки', to: '/settings', icon: Settings },
]

export const mobileNavigation: NavigationItem[] = [
  ...desktopNavigation.slice(0, 2),
  ...desktopNavigation.slice(4, 5),
  ...desktopNavigation.slice(3, 4),
  { label: 'Ещё', to: '/more', icon: Menu },
]

export const myScheduleNavigation: NavigationItem = { label: 'Моё расписание', to: '/my-schedule', icon: CalendarDays }

export const mobileMoreNavigation: NavigationItem[] = [
  ...desktopNavigation.slice(2, 3),
  ...desktopNavigation.slice(5),
  myScheduleNavigation,
]

export function getNavigationBadge(item: NavigationItem, unreadCount = 0): number | undefined {
  if (item.to === '/notifications') return import.meta.env.DEV ? 3 : unreadCount
  return item.badge
}
