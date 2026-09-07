import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ChevronRight, Plus, Search } from 'lucide-react'
import { useSpecialists, useWorkforceKey, workforceRequest, type ProfileInput, type Specialist } from '@/features/workforce/api'
import { Avatar, Feedback, Modal, ProfileForm, WorkforceFrame, fieldClass, surfaceClass } from '@/features/workforce/components'

export function SpecialistsPage() {
  const query = useSpecialists()
  const [search, setSearch] = useState('')
  const [adding, setAdding] = useState(false)
  const navigate = useNavigate()
  const client = useQueryClient()
  const key = useWorkforceKey()
  const create = useMutation({
    mutationFn: (data: ProfileInput) => workforceRequest<Specialist>('', 'POST', data),
    onSuccess: async (s) => { await client.invalidateQueries({ queryKey: key }); navigate(`/specialists/${s.id}`) },
  })
  const filtered = (query.data ?? []).filter((s) => `${s.name} ${s.specialization}`.toLocaleLowerCase('ru').includes(search.toLocaleLowerCase('ru')))
  return <WorkforceFrame title="Специалисты" action={<button className="flex items-center gap-1 text-primary" onClick={() => { create.reset(); setAdding(true) }}><Plus className="size-5" /><span>Специалист</span></button>}>
    <label className="relative block"><Search aria-hidden="true" className="absolute left-3 top-3 size-5 text-muted" /><input className={`${fieldClass} pl-11`} value={search} onChange={(e) => setSearch(e.target.value)} aria-label="Поиск специалиста" placeholder="Поиск специалиста" /></label>
    <Feedback error={query.error} />
    {query.isPending ? <p role="status" className="mt-8 text-muted">Загружаем специалистов...</p> : null}
    {!query.isPending && !query.error && filtered.length === 0 ? <p className="mt-8 text-muted">{search ? 'Специалисты не найдены.' : 'Пока нет специалистов. Добавьте первого.'}</p> : null}
    {[true, false].map((working) => {
      const group = filtered.filter((s) => (s.todayIntervals.length > 0) === working)
      return group.length ? <section key={String(working)} className="mt-7">
        <h2 className="mb-4 text-lg font-semibold">{working ? 'Работают сегодня' : 'Сегодня не работают'}</h2>
        <div className="space-y-3">{group.map((s) => <Link className={`${surfaceClass} flex items-center gap-4 transition-colors hover:bg-white`} key={s.id} to={`/specialists/${s.id}`}>
          <Avatar name={s.name} /><div className="min-w-0 flex-1"><p className="break-words font-semibold">{s.name} <span className="font-normal text-muted">· {s.specialization}</span></p>
            <p className="mt-1 text-sm text-muted">{working ? `Сегодня ${s.todayIntervals.map((i) => `${i.start}–${i.end}`).join(', ')}` : 'Нет рабочих часов на сегодня'}</p>
            {s.administratorId ? <span className="mt-3 inline-block rounded bg-primary-soft px-2 py-1 text-xs text-primary">Также администратор</span> : null}
          </div><ChevronRight aria-hidden="true" className="size-5 shrink-0 text-muted" />
        </Link>)}</div>
      </section> : null
    })}
    {adding ? <Modal title="Новый специалист" close={() => !create.isPending && setAdding(false)}><ProfileForm onSave={(data) => create.mutate(data)} pending={create.isPending} error={create.error} close={() => setAdding(false)} /></Modal> : null}
  </WorkforceFrame>
}
