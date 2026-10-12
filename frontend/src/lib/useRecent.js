import { useEffect, useState } from 'react'
import { api } from './api'
import { recentlyViewed } from './localList'

/**
 * Listings this browser viewed recently, newest first, fetched fresh so prices and
 * availability are current. Listings that were removed or hidden simply drop out.
 */
export function useRecentlyViewed(excludeSlug = null) {
  const [result, setResult] = useState({ key: null, products: [] })
  // Read on every render (a small local list) so moving between listings picks up the one just left.
  const key = recentlyViewed().filter((s) => s !== excludeSlug).join(',')

  useEffect(() => {
    if (!key) return
    let live = true
    api.get('/products', { params: { slugs: key, per_page: 24 } })
      .then((res) => {
        if (!live) return
        const bySlug = new Map(res.data.data.map((p) => [p.slug, p]))
        setResult({ key, products: key.split(',').map((s) => bySlug.get(s)).filter(Boolean) })
      })
      .catch(() => live && setResult({ key, products: [] }))
    return () => { live = false }
  }, [key])

  // Results for another listing (before the new fetch lands) are not shown.
  return key && result.key === key ? result.products : []
}
