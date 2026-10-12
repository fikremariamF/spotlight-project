import { createContext, useContext, useEffect, useState } from 'react'
import { api } from '../lib/api'

const fallback = {
  name: import.meta.env.VITE_BRAND_NAME || 'Furniture Marketplace',
  currency: 'ETB',
  max_product_images: 8,
  max_image_kb: 5120,
}

const ConfigContext = createContext(fallback)

export function ConfigProvider({ children }) {
  const [config, setConfig] = useState(fallback)

  useEffect(() => {
    api
      .get('/config')
      .then((res) => setConfig({ ...fallback, ...res.data }))
      .catch(() => {})
  }, [])

  useEffect(() => {
    document.title = config.name
  }, [config.name])

  return <ConfigContext.Provider value={config}>{children}</ConfigContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useConfig() {
  return useContext(ConfigContext)
}

/**
 * How customers can pay right now, in shop wording. Cash is assumed until the config says otherwise.
 */
// eslint-disable-next-line react-refresh/only-export-components
export function usePaymentWording() {
  const config = useContext(ConfigContext)
  const online = config.online_payments?.available === true
  const cash = config.cod_enabled !== false
  if (online && cash) return { title: 'Pay online or on delivery', body: 'telebirr, CBE Birr, M-Pesa or card, or cash when your furniture arrives.', stat: 'Online or cash', short: 'Pay online or cash on delivery.' }
  if (online) return { title: 'Pay online', body: 'telebirr, CBE Birr, M-Pesa or card, on a secure Chapa page.', stat: 'Online', short: 'Pay online.' }
  return { title: 'Pay on delivery', body: 'Pay in cash when your furniture arrives.', stat: 'Cash on delivery', short: 'Pay cash on delivery.' }
}
