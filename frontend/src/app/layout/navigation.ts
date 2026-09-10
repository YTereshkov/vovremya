import {
  BarChart3,
  Bell,
  CalendarCheck2,
  CalendarDays,
  CalendarRange,
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
  { label: 'Настройки', to: '/settings', icon: Settings },
]

export const mobileNavigation: NavigationItem[] = [
  { label: 'Сегодня', to: '/', icon: CalendarRange },
  { label: 'Календарь', to: '/calendar', icon: CalendarDays },
  { label: 'Клиенты', to: '/clients', icon: UsersRound },
  { label: 'Ожидание', to: '/waiting', icon: Hourglass },
  { label: 'Ещё', to: '/settings', icon: Menu },
]
