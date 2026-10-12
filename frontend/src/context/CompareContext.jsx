import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import { readList, writeList } from '../lib/localList'

const KEY = 'mp_compare'
export const COMPARE_MAX = 4

const CompareContext = createContext(null)

/** Listings picked for side-by-side comparison, kept in this browser and shared across tabs. */
export function CompareProvider({ children }) {
  const [slugs, setSlugs] = useState(() => readList(KEY).slice(0, COMPARE_MAX))
  // The latest list, so two quick clicks don't both start from the same render. Kept in memory too,
  // because storage can be blocked (private windows) and then every read would come back empty.
  const latest = useRef(slugs)

  useEffect(() => {
    const onStorage = (e) => {
      if (e.key !== KEY) return
      latest.current = readList(KEY).slice(0, COMPARE_MAX)
      setSlugs(latest.current)
    }
    window.addEventListener('storage', onStorage)
    return () => window.removeEventListener('storage', onStorage)
  }, [])

  const save = useCallback((next) => {
    latest.current = next
    setSlugs(next)
    writeList(KEY, next)
  }, [])

  /** Returns 'added', 'removed' or 'full'. */
  const toggle = useCallback((slug) => {
    const current = latest.current
    if (current.includes(slug)) {
      save(current.filter((s) => s !== slug))
      return 'removed'
    }
    if (current.length >= COMPARE_MAX) return 'full'
    save([...current, slug])
    return 'added'
  }, [save])

  const remove = useCallback((slug) => save(latest.current.filter((s) => s !== slug)), [save])
  const clear = useCallback(() => save([]), [save])

  const value = useMemo(() => ({ slugs, toggle, remove, clear, has: (slug) => slugs.includes(slug) }), [slugs, toggle, remove, clear])
  return <CompareContext.Provider value={value}>{children}</CompareContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useCompare() {
  const ctx = useContext(CompareContext)
  if (!ctx) throw new Error('useCompare must be used inside CompareProvider')
  return ctx
}
