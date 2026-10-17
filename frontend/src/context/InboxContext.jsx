import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { api } from '../lib/api'
import { useAuth } from './AuthContext'

const InboxContext = createContext({ unread: 0, refreshUnread: () => {} })
const POLL_MS = 60000

// Customers: Betoch Support answers they have not read. Stores: requests from Betoch they have not read.
const COUNT_URL = { customer: '/support/unread-count', seller: '/seller/requests/unread-count' }

/**
 * Unread count for the header badge. Polls while the tab is visible; pages that read or send
 * messages call refreshUnread() to update it at once.
 */
export function InboxProvider({ children }) {
  const { user } = useAuth()
  const [unread, setUnread] = useState(0)
  const url = COUNT_URL[user?.role]

  const refreshUnread = useCallback(async () => {
    if (!url) return
    try {
      const res = await api.get(url)
      setUnread(res.data.data.unread)
    } catch {
      // A failed poll keeps the last known count.
    }
  }, [url])

  useEffect(() => {
    if (!url) {
      setUnread(0)
      return undefined
    }
    refreshUnread()
    const timer = setInterval(() => {
      if (document.visibilityState === 'visible') refreshUnread()
    }, POLL_MS)
    const onVisible = () => document.visibilityState === 'visible' && refreshUnread()
    document.addEventListener('visibilitychange', onVisible)
    return () => {
      clearInterval(timer)
      document.removeEventListener('visibilitychange', onVisible)
    }
  }, [url, refreshUnread])

  const value = useMemo(() => ({ unread, refreshUnread }), [unread, refreshUnread])
  return <InboxContext.Provider value={value}>{children}</InboxContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useInbox() {
  return useContext(InboxContext)
}
