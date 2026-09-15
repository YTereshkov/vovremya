import type { AuthUser } from '@/features/auth/api/auth'
import type { CalendarAppointment } from '@/features/calendar/api'
import type { Specialist } from '@/features/workforce/api'

export interface OfflineScheduleSnapshot {
  administratorId: string
  organizationId: string
  timezone: string
  from: string
  to: string
  syncedAt: string
  appointments: CalendarAppointment[]
  specialists: Specialist[]
}

interface OfflineState {
  identity: AuthUser
  snapshot: OfflineScheduleSnapshot | null
}

const DATABASE_NAME = 'vovremya-offline-v1'
const STORE_NAME = 'state'
const CURRENT_KEY = 'current'
let databasePromise: Promise<IDBDatabase> | null = null

function database(): Promise<IDBDatabase> {
  if (!databasePromise) {
    databasePromise = new Promise((resolve, reject) => {
      const request = indexedDB.open(DATABASE_NAME, 1)
      request.onupgradeneeded = () => request.result.createObjectStore(STORE_NAME)
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
      request.onblocked = () => reject(new Error('Offline storage is blocked.'))
    })
  }
  return databasePromise
}

async function readState(): Promise<OfflineState | null> {
  const db = await database()
  return new Promise((resolve, reject) => {
    const request = db.transaction(STORE_NAME, 'readonly').objectStore(STORE_NAME).get(CURRENT_KEY)
    request.onsuccess = () => resolve((request.result as OfflineState | undefined) ?? null)
    request.onerror = () => reject(request.error)
  })
}

function sameIdentity(left: AuthUser, right: AuthUser): boolean {
  return left.id === right.id && left.organization.id === right.organization.id
}

export async function readOfflineIdentity(): Promise<AuthUser | null> {
  return (await readState())?.identity ?? null
}

export async function readOfflineSnapshot(identity: AuthUser): Promise<OfflineScheduleSnapshot | null> {
  const state = await readState()
  return state && sameIdentity(state.identity, identity)
    && state.snapshot?.administratorId === identity.id
    && state.snapshot.organizationId === identity.organization.id
    ? state.snapshot : null
}

export async function saveOfflineIdentity(identity: AuthUser): Promise<void> {
  const db = await database()
  await new Promise<void>((resolve, reject) => {
    const transaction = db.transaction(STORE_NAME, 'readwrite')
    const store = transaction.objectStore(STORE_NAME)
    const request = store.get(CURRENT_KEY)
    request.onsuccess = () => {
      const current = request.result as OfflineState | undefined
      store.put({ identity, snapshot: current && sameIdentity(current.identity, identity) ? current.snapshot : null } satisfies OfflineState, CURRENT_KEY)
    }
    transaction.oncomplete = () => resolve()
    transaction.onerror = () => reject(transaction.error)
    transaction.onabort = () => reject(transaction.error)
  })
}

export async function saveOfflineSnapshot(snapshot: OfflineScheduleSnapshot): Promise<void> {
  const db = await database()
  await new Promise<void>((resolve, reject) => {
    const transaction = db.transaction(STORE_NAME, 'readwrite')
    const store = transaction.objectStore(STORE_NAME)
    const request = store.get(CURRENT_KEY)
    request.onsuccess = () => {
      const current = request.result as OfflineState | undefined
      if (current?.identity.id === snapshot.administratorId && current.identity.organization.id === snapshot.organizationId) {
        store.put({ ...current, snapshot } satisfies OfflineState, CURRENT_KEY)
      } else {
        transaction.abort()
      }
    }
    transaction.oncomplete = () => resolve()
    transaction.onerror = () => reject(transaction.error)
    transaction.onabort = () => reject(transaction.error)
  })
}

export async function clearOfflineState(): Promise<void> {
  const db = await database()
  await new Promise<void>((resolve, reject) => {
    const transaction = db.transaction(STORE_NAME, 'readwrite')
    transaction.objectStore(STORE_NAME).delete(CURRENT_KEY)
    transaction.oncomplete = () => resolve()
    transaction.onerror = () => reject(transaction.error)
    transaction.onabort = () => reject(transaction.error)
  })
}
