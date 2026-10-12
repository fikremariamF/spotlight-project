import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { api } from '../lib/api'
import { useAuth } from './AuthContext'

const AlertsContext = createContext({ unread: 0, refreshAlerts: () => {} })
const POLL_MS = 60000

/**
 * Unread in-app alert count for the header bell. Polls while the tab is visible, like the inbox badge;
 * the alerts page calls refreshAlerts() after marking alerts read.
 */
export function AlertsProvider({ children }) {
  const { user } = useAuth()
  const [unread, setUnread] = useState(0)
  const enabled = Boolean(user)

  const refreshAlerts = useCallback(async () => {
    if (!enabled) return
    try {
      const res = await api.get('/notifications/unread-count')
      setUnread(res.data.data.unread)
    } catch {
      // A failed poll keeps the last known count.
    }
  }, [enabled])

  useEffect(() => {
    if (!enabled) return undefined
    refreshAlerts()
    const timer = setInterval(() => {
      if (document.visibilityState === 'visible') refreshAlerts()
    }, POLL_MS)
    const onVisible = () => document.visibilityState === 'visible' && refreshAlerts()
    document.addEventListener('visibilitychange', onVisible)
    return () => {
      clearInterval(timer)
      document.removeEventListener('visibilitychange', onVisible)
    }
  }, [enabled, refreshAlerts])

  // Signed out shows 0 without waiting for a request; the stale count is replaced on the next sign-in poll.
  const shown = enabled ? unread : 0
  const value = useMemo(() => ({ unread: shown, refreshAlerts }), [shown, refreshAlerts])
  return <AlertsContext.Provider value={value}>{children}</AlertsContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAlerts() {
  return useContext(AlertsContext)
}
