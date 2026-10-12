// A small list of listing slugs kept in this browser (recently viewed, compare).
// Storage can be unavailable (private mode, blocked site data): every call falls back quietly.

export function readList(key) {
  try {
    const value = JSON.parse(localStorage.getItem(key) || '[]')
    return Array.isArray(value) ? value.filter((s) => typeof s === 'string' && s.length > 0 && s.length <= 200) : []
  } catch {
    return []
  }
}

export function writeList(key, list) {
  try {
    localStorage.setItem(key, JSON.stringify(list))
  } catch {
    // Not saved; the list still works for this page view.
  }
}

const RECENT_KEY = 'mp_recent'
export const RECENT_MAX = 12

/** Remember a listing as just viewed, newest first. */
export function rememberViewed(slug) {
  const next = [slug, ...readList(RECENT_KEY).filter((s) => s !== slug)].slice(0, RECENT_MAX)
  writeList(RECENT_KEY, next)
}

export function recentlyViewed() {
  return readList(RECENT_KEY)
}
