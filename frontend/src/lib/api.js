import axios from 'axios'

const TOKEN_KEY = 'mp_token'

export const tokenStore = {
  get() {
    try {
      return localStorage.getItem(TOKEN_KEY)
    } catch {
      return null
    }
  },
  set(token) {
    try {
      if (token) localStorage.setItem(TOKEN_KEY, token)
      else localStorage.removeItem(TOKEN_KEY)
    } catch {
      // Storage unavailable (private mode): the session lasts until the tab closes.
    }
  },
}

export const api = axios.create({
  baseURL: `${import.meta.env.VITE_API_URL || ''}/api`,
  headers: { Accept: 'application/json' },
  timeout: 30000,
})

api.interceptors.request.use((config) => {
  const token = tokenStore.get()
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

const unauthorizedListeners = new Set()
export function onUnauthorized(listener) {
  unauthorizedListeners.add(listener)
  return () => unauthorizedListeners.delete(listener)
}

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401 && tokenStore.get()) {
      tokenStore.set(null)
      unauthorizedListeners.forEach((fn) => fn())
    }
    return Promise.reject(error)
  },
)

/**
 * Turn an axios error into { message, fields } for display.
 * fields maps input names to their first validation message.
 */
export function parseError(error) {
  const res = error?.response
  if (!res) {
    return { message: 'Could not reach the server. Check your connection and try again.', fields: {} }
  }
  const fields = {}
  if ([422, 429].includes(res.status) && res.data?.errors) {
    for (const [key, messages] of Object.entries(res.data.errors)) {
      // "images.0" style keys collapse onto their field.
      fields[key.split('.')[0]] ??= messages[0]
    }
  }
  const fallback = {
    401: 'Please sign in to continue.',
    403: 'You do not have permission to do that.',
    404: 'We could not find what you were looking for.',
    409: 'That change conflicts with existing data.',
    413: 'The upload is too large.',
    429: 'Too many attempts. Please wait a minute and try again.',
  }[res.status]
  // Server messages are shown for validation and business-rule errors; generic statuses use friendly copy.
  const useServer = res.data?.message && [403, 409, 422].includes(res.status)
  const message = useServer ? res.data.message : fallback || 'Something went wrong. Please try again.'
  return { message, fields }
}
