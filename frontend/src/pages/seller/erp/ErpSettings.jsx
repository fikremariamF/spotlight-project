import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { Alert, ErrorState, Field, PageHeader, Spinner } from '../../../components/ui'
import { api, parseError } from '../../../lib/api'
import { useFetch } from '../../../lib/useFetch'

export default function ErpSettings() {
  const res = useFetch('/seller/erp/settings')
  const [v, setV] = useState(null)
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState(null)
  const [busy, setBusy] = useState(false)
  const current = res.data?.data
  useEffect(() => {
    if (current) setV({ low_stock_level: String(current.low_stock_level), low_stock_alerts: current.low_stock_alerts, cost_from_purchases: current.cost_from_purchases, sku_prefix: current.sku_prefix || '' })
  }, [current])

  if (res.loading && !v) return <Spinner />
  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />
  if (!v) return null

  async function save(e) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setMessage(null)
    try {
      const r = await api.put('/seller/erp/settings', { low_stock_level: Number(v.low_stock_level), low_stock_alerts: v.low_stock_alerts, cost_from_purchases: v.cost_from_purchases, sku_prefix: v.sku_prefix.trim() || null })
      res.setData(r.data)
      toast.success('Settings saved')
    } catch (err) {
      const p = parseError(err)
      setErrors(p.fields)
      setMessage(Object.keys(p.fields).length ? null : p.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="max-w-xl">
      <PageHeader title="ERP settings" subtitle="Only your store uses these." />
      {message && <div className="mb-4"><Alert tone="error">{message}</Alert></div>}
      <form className="card space-y-4 p-5" onSubmit={save} noValidate>
        <Field label="Low-stock level" name="low_stock_level" error={errors.low_stock_level} hint="An item counts as low when this many or fewer are available. Each listing or option can set its own in Inventory.">
          <input id="low_stock_level" className="input w-32" inputMode="numeric" value={v.low_stock_level} onChange={(e) => setV({ ...v, low_stock_level: e.target.value })} />
        </Field>
        <label className="flex items-start gap-2 text-sm">
          <input type="checkbox" className="mt-0.5 size-4 accent-brand-700" checked={v.low_stock_alerts} onChange={(e) => setV({ ...v, low_stock_alerts: e.target.checked })} />
          <span>Tell me when an item gets low or runs out<span className="block text-xs text-stone-500">In your alerts, and by email if email alerts are on in Alert settings.</span></span>
        </label>
        <label className="flex items-start gap-2 text-sm">
          <input type="checkbox" className="mt-0.5 size-4 accent-brand-700" checked={v.cost_from_purchases} onChange={(e) => setV({ ...v, cost_from_purchases: e.target.checked })} />
          <span>Update cost prices when goods arrive<span className="block text-xs text-stone-500">The new cost is the average of the units you had and the units received, including their share of delivery charges and discounts. Turn off to keep the cost prices you type in.</span></span>
        </label>
        <Field label="SKU prefix" name="sku_prefix" error={errors.sku_prefix} hint="Letters and numbers, up to 10. Used when you create SKUs, for example BF-00012.">
          <input id="sku_prefix" className="input w-40 uppercase" maxLength={10} value={v.sku_prefix} onChange={(e) => setV({ ...v, sku_prefix: e.target.value })} placeholder="BF" />
        </Field>
        <div className="flex justify-end"><button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Saving...' : 'Save settings'}</button></div>
      </form>
    </div>
  )
}
