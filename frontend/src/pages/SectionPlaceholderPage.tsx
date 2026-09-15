import type { ReactNode } from 'react'

interface SectionPlaceholderPageProps {
  title: string
  action?: ReactNode
}

export function SectionPlaceholderPage({ title, action }: SectionPlaceholderPageProps) {
  return (
    <section className="mx-auto min-h-screen w-full max-w-[1306px] px-5 pb-10 pt-[calc(env(safe-area-inset-top)+36px)] sm:px-8 lg:px-7 lg:py-8">
      <header className="flex items-center justify-between gap-4"><h1 className="text-[32px] font-semibold leading-tight tracking-[-0.025em] lg:text-[36px]">{title}</h1>{action}</header>
      <div aria-label={title} className="mt-7 min-h-[420px] rounded-2xl border border-border bg-surface" />
    </section>
  )
}
