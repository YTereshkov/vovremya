import { useSyncExternalStore } from 'react'

const listeners = new Set<() => void>()
let connected = typeof navigator === 'undefined' ? true : navigator.onLine

function update(value: boolean) {
  if (connected === value) return
  connected = value
  listeners.forEach((listener) => listener())
}

if (typeof window !== 'undefined') {
  window.addEventListener('online', () => update(true))
  window.addEventListener('offline', () => update(false))
}

export function isConnected(): boolean { return connected }
export function markConnected(): void {
  if (typeof navigator === 'undefined' || navigator.onLine) update(true)
}
export function markDisconnected(): void { update(false) }

function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

export function useConnectivity(): boolean {
  return useSyncExternalStore(subscribe, isConnected, () => true)
}
