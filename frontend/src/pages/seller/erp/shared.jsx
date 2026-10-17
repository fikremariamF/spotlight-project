import { Download, Search } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useOutletContext, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { api, parseError } from '../../../lib/api'
import { formatETB, toQuery } from '../../../lib/format'
import { useFetch } from '../../../lib/useFetch'

export const money = (s) => (s === null || s === undefined ? 'Not set' : formatETB(s) || 'ETB 0')

/** Santim to a plain birr string for a form field: 800050 -> "8000.50", 800000 -> "8000". */
export const birr = (santim) => (santim === null || santim === undefined ? '' : (santim / 100).toFixed(2).replace(/\.00$/, ''))

/** What a person typed as birr, cleaned for the API ("8,000.50" -> "8000.50"). */
export const cleanBirr = (v) => String(v ?? '').trim().replace(/[,\s]/g, '')

/**
 * Birr text to integer santim the way the server reads it, or null when it is not a valid amount.
 * No floating point: "0.1" + "0.2" must add up to exactly 30 santim.
 */
export function toSantim(v) {
  const t = cleanBirr(v)
  if (t === '') return 0
  const m = /^(\d{1,9})(?:\.(\d{1,2}))?$/.exec(t)
  return m ? Number(m[1]) * 100 + Number((m[2] || '').padEnd(2, '0')) : null
}

/** A store that is suspended or rejected can look at its records but not change them. */
export function useReadOnly() {
  const summary = useOutletContext()
  return ['suspended', 'rejected'].includes(summary?.data?.data?.store?.status)
}

/**
 * Shared submit helper: sends, keeps the errors for the form, hands back the response body.
 * `lines` holds messages for numbered rows ("items.2.quantity" -> lines[2]).
 */
export function useSubmit(onDone) {
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState({})
  const [lines, setLines] = useState({})
  async function submit(request, success) {
    setBusy(true)
    setErrors({})
    setLines({})
    try {
      const r = await request()
      onDone?.(r.data)
      if (success) toast.success(success)
      return r.data
    } catch (err) {
      const p = parseError(err)
      const byLine = {}
      for (const [key, messages] of Object.entries(err?.response?.data?.errors || {})) {
        const m = /^items\.(\d+)\./.exec(key)
        if (m) byLine[m[1]] ??= messages[0]
      }
      setLines(byLine)
      setErrors(Object.keys(p.fields).length ? p.fields : { form: p.message })
      return null
    } finally {
      setBusy(false)
    }
  }
  return { busy, errors, lines, submit, setErrors }
}

/** The first error of a form, for small forms that show one message. */
export function Err({ errors }) {
  const first = Object.values(errors)[0]
  return first ? <p role="alert" className="text-xs font-medium text-red-700">{Array.isArray(first) ? first[0] : first}</p> : null
}

export const MOVEMENT_LABELS = {
  opening: 'Starting stock',
  edit: 'Changed on listing',
  count: 'Stock count',
  damaged: 'Damaged',
  lost: 'Lost',
  returned: 'Returned',
  found: 'Found',
  order_reserved: 'Ordered',
  order_released: 'Order cancelled',
  purchase_received: 'Received from supplier',
  transfer_out: 'Moved out',
  transfer_in: 'Moved in',
  transfer_returned: 'Transfer cancelled',
  offline_sale: 'Sold (shop sale)',
  offline_sale_voided: 'Shop sale voided',
  sale_returned: 'Returned by customer',
  sale_return_voided: 'Return undone',
}

export const PO_STATUS = {
  draft: ['Draft', 'bg-stone-200 text-stone-700'],
  ordered: ['Ordered', 'bg-sky-100 text-sky-800'],
  partially_received: ['Partly received', 'bg-amber-100 text-amber-800'],
  received: ['Received', 'bg-emerald-100 text-emerald-800'],
  cancelled: ['Cancelled', 'bg-red-100 text-red-800'],
}

export const TRANSFER_STATUS = {
  in_transit: ['On the way', 'bg-amber-100 text-amber-800'],
  received: ['Received', 'bg-emerald-100 text-emerald-800'],
  cancelled: ['Cancelled', 'bg-stone-200 text-stone-700'],
}

export const SALE_STATUS = {
  completed: ['Completed', 'bg-emerald-100 text-emerald-800'],
  voided: ['Voided', 'bg-stone-200 text-stone-700'],
  recorded: ['Recorded', 'bg-emerald-100 text-emerald-800'],
}

export function Badge({ map, value, late = false }) {
  const [label, tone] = map[value] || [value, 'bg-stone-100 text-stone-700']
  return (
    <span className="inline-flex flex-wrap gap-1">
      <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap ${tone}`}>{label}</span>
      {late && <span className="inline-flex rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold whitespace-nowrap text-red-800">Late</span>}
    </span>
  )
}

export const PAY_METHODS = { cash: 'Cash', bank_transfer: 'Bank transfer', telebirr: 'telebirr', cbe_birr: 'CBE Birr', cheque: 'Cheque', other: 'Other' }

/** "Main store" and "Bole shop" style names with "(closed)" where it applies. */
export const placeName = (w) => (w ? `${w.name}${w.is_active === false ? ' (closed)' : ''}` : '')

export const itemKey = (i) => `${i.product_id}:${i.variant_id ?? 0}`
export const itemName = (i) => `${i.name}${i.label ? ` (${i.label})` : ''}`

/** Adjustments a store records by hand, with what the number means for each. */
export const ADJUSTMENTS = [
  { type: 'count', label: 'Stock count', hint: 'How many you counted at this location. The difference is recorded.' },
  { type: 'damaged', label: 'Damaged', hint: 'Units that can no longer be sold.' },
  { type: 'lost', label: 'Lost or stolen', hint: 'Units that are missing.' },
  { type: 'returned', label: 'Returned by a customer', hint: 'Units back in stock after a return.' },
  { type: 'found', label: 'Found or added', hint: 'Extra units, for example found in a count.' },
]

const STATE = {
  ok: ['In stock', 'bg-emerald-100 text-emerald-800'],
  low: ['Low stock', 'bg-amber-100 text-amber-800'],
  out: ['Out of stock', 'bg-red-100 text-red-800'],
  made_to_order: ['Made to order', 'bg-stone-200 text-stone-700'],
}

export function StockBadge({ state }) {
  const [label, tone] = STATE[state] || [state, 'bg-stone-100 text-stone-700']
  return <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap ${tone}`}>{label}</span>
}

/** "+3" or "-2" with a color; 0 for a count that matched. */
export function Change({ n }) {
  return <span className={`font-semibold tabular-nums ${n > 0 ? 'text-emerald-700' : n < 0 ? 'text-red-700' : 'text-stone-500'}`}>{n > 0 ? `+${n}` : n}</span>
}

const PERIODS = [['today', 'Today'], ['7d', '7 days'], ['30d', '30 days'], ['custom', 'Custom']]
// For money over longer spans: calendar months and the year so far.
const LONG_PERIODS = [['month', 'This month'], ['last_month', 'Last month'], ['30d', '30 days'], ['year', 'This year'], ['custom', 'Custom']]

/**
 * Today / 7 days / 30 days / custom dates (or, with `long`, months and the year), kept in the address bar so a refresh or a shared link
 * shows the same range. Returns the query string for the API.
 */
export function usePeriod(defaultPeriod = '30d') {
  const [params, setParams] = useSearchParams()
  const period = params.get('period') || defaultPeriod
  const from = params.get('from') || ''
  const to = params.get('to') || ''
  const query = toQuery(period === 'custom' ? { period, from, to } : { period })
  // A new range starts again from the first page.
  const set = (next) => setParams(toQuery({ ...Object.fromEntries(params), page: '', ...next }), { replace: true })
  return { period, from, to, query, set }
}

export function PeriodPicker({ value, long = false }) {
  const [form, setForm] = useState({ from: value.from, to: value.to })
  return (
    <div className="flex flex-wrap items-end gap-2">
      <div role="group" aria-label="Period" className="inline-flex flex-wrap rounded-xl border border-stone-300 bg-white p-0.5">
        {(long ? LONG_PERIODS : PERIODS).map(([key, label]) => (
          <button key={key} type="button" aria-pressed={value.period === key}
            onClick={() => value.set({ period: key, ...(key === 'custom' ? form : { from: '', to: '' }) })}
            className={`rounded-lg px-3 py-1.5 text-sm ${value.period === key ? 'bg-brand-700 font-semibold text-white' : 'text-stone-700 hover:bg-stone-100'}`}>
            {label}
          </button>
        ))}
      </div>
      {value.period === 'custom' && (
        <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); value.set({ period: 'custom', ...form }) }}>
          <label className="text-sm">From<input type="date" className="input mt-1 w-auto" value={form.from} onChange={(e) => setForm({ ...form, from: e.target.value })} /></label>
          <label className="text-sm">To<input type="date" className="input mt-1 w-auto" value={form.to} onChange={(e) => setForm({ ...form, to: e.target.value })} /></label>
          <button type="submit" className="btn-secondary">Show</button>
        </form>
      )}
    </div>
  )
}

/** Download a CSV from the API (it needs the sign-in token, so a plain link would not work). */
export function CsvButton({ url, filename, label = 'Download CSV' }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  async function download() {
    setBusy(true)
    setError(null)
    try {
      const r = await api.get(url, { responseType: 'blob' })
      const href = URL.createObjectURL(r.data)
      const a = document.createElement('a')
      a.href = href
      a.download = filename
      a.click()
      URL.revokeObjectURL(href)
    } catch (err) {
      setError(parseError(err).message)
    } finally {
      setBusy(false)
    }
  }
  return (
    <span className="inline-flex flex-col">
      <button type="button" className="btn-secondary" onClick={download} disabled={busy}>
        <Download className="size-4" aria-hidden="true" />{busy ? 'Preparing...' : label}
      </button>
      {error && <span role="alert" className="mt-1 text-xs text-red-700">{error}</span>}
    </span>
  )
}

/**
 * Simple bar chart of one value per day, with the numbers available to screen readers as a table.
 * `days`: [{ date, value }].
 */
export function DayBars({ days, format = (v) => v, label }) {
  const max = Math.max(1, ...days.map((d) => d.value))
  const total = days.reduce((n, d) => n + d.value, 0)
  if (total === 0) return <p className="py-6 text-center text-sm text-stone-500">Nothing in this period yet.</p>
  return (
    <figure>
      <div className="flex h-36 items-end gap-px" aria-hidden="true">
        {days.map((d) => (
          <div key={d.date} className="group relative flex h-full flex-1 items-end">
            <div className="w-full rounded-t bg-brand-600 group-hover:bg-brand-800" style={{ height: `${Math.max(d.value ? 3 : 0, (100 * d.value) / max)}%` }} />
            <span className="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 rounded bg-stone-900 px-2 py-1 text-xs whitespace-nowrap text-white group-hover:block">
              {d.date}: {format(d.value)}
            </span>
          </div>
        ))}
      </div>
      <div className="mt-1 flex justify-between text-xs text-stone-500" aria-hidden="true"><span>{days[0]?.date}</span><span>{days[days.length - 1]?.date}</span></div>
      <table className="sr-only">
        <caption>{label}</caption>
        <tbody>{days.filter((d) => d.value).map((d) => <tr key={d.date}><th scope="row">{d.date}</th><td>{format(d.value)}</td></tr>)}</tbody>
      </table>
    </figure>
  )
}

/**
 * Search the store's in-stock listings and options to add to a purchase order or a transfer.
 * `atLocation` shows the units at that location; `supplierId` lists that supplier's usual items first.
 * `forSale` also lists made-to-order listings and shows the selling price.
 */
export function ItemPicker({ onPick, added = [], warehouseId, supplierId, label = 'Add items', disabled = false, forSale = false }) {
  const [open, setOpen] = useState(false)
  const [q, setQ] = useState('')
  const [term, setTerm] = useState('')
  useEffect(() => {
    const t = setTimeout(() => setTerm(q.trim()), 250)
    return () => clearTimeout(t)
  }, [q])
  const res = useFetch(open ? `/seller/erp/items?${toQuery({ q: term, warehouse_id: warehouseId, supplier_id: supplierId, for: forSale ? 'sale' : '' })}` : null)
  const rows = res.data?.data || []

  if (!open) {
    return <button type="button" className="btn-secondary" onClick={() => setOpen(true)} disabled={disabled}>{label}</button>
  }
  return (
    <div className="rounded-xl border border-stone-200 bg-stone-50 p-3">
      <div className="flex gap-2">
        <label htmlFor="pick-q" className="sr-only">Search your items</label>
        <div className="relative flex-1">
          <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" aria-hidden="true" />
          <input id="pick-q" type="search" className="input pl-9" placeholder="Name, SKU, color or size" value={q} onChange={(e) => setQ(e.target.value)} maxLength={100} autoFocus />
        </div>
        <button type="button" className="btn-ghost" onClick={() => setOpen(false)}>Done</button>
      </div>
      <div className="mt-2 max-h-72 overflow-y-auto" aria-live="polite">
        {res.loading && !res.data ? <p className="p-3 text-sm text-stone-500">Searching...</p>
          : res.error ? <p role="alert" className="p-3 text-sm text-red-700">{res.error.message}</p>
            : rows.length === 0 ? <p className="p-3 text-sm text-stone-500">{forSale ? (term ? 'Nothing matches.' : 'You have no listings yet.') : term ? 'Nothing matches. Only in-stock listings can be added.' : 'You have no in-stock listings yet.'}</p> : (
              <ul className="divide-y divide-stone-100 rounded-lg bg-white">
                {rows.map((it) => {
                  const on = added.includes(itemKey(it))
                  return (
                    <li key={itemKey(it)}>
                      <button type="button" disabled={on} onClick={() => onPick(it)}
                        className="flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:bg-stone-50 disabled:cursor-default disabled:opacity-60">
                        {it.image ? <img src={it.image} alt="" className="size-9 shrink-0 rounded-md object-cover" /> : <span className="size-9 shrink-0 rounded-md bg-stone-100" />}
                        <span className="min-w-0 flex-1">
                          <span className="block truncate font-medium text-stone-900">{itemName(it)}</span>
                          <span className="block text-xs text-stone-500">
                            {(forSale && it.stock_type === 'made_to_order'
                              ? [it.sku, 'made to order', money(it.price_santim)]
                              : [it.sku, it.at_location !== null && it.at_location !== undefined ? `${it.at_location} here` : null, `${it.available} available`,
                                forSale ? money(it.price_santim) : it.cost_price_santim !== null ? `cost ${money(it.cost_price_santim)}` : null,
                                supplierId && it.supplier_id === supplierId ? 'usual supplier' : null]).filter(Boolean).join(' · ')}
                          </span>
                        </span>
                        <span className="text-xs font-semibold text-brand-700">{on ? 'Added' : 'Add'}</span>
                      </button>
                    </li>
                  )
                })}
              </ul>
            )}
      </div>
    </div>
  )
}

/** Ask for a short reason, then run `onConfirm(reason)`. Used for cancelling, closing and voiding. */
export function ReasonForm({ label, button, tone = 'danger', onConfirm, onBack, busy, errors = {}, hint }) {
  const [reason, setReason] = useState('')
  return (
    <form noValidate className="space-y-2 rounded-lg border border-stone-200 bg-stone-50 p-3" onSubmit={(e) => { e.preventDefault(); onConfirm(reason.trim()) }}>
      <label className="block text-sm text-stone-700" htmlFor={`reason-${button}`}>{label}
        <input id={`reason-${button}`} className="input mt-1" maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} autoFocus />
      </label>
      {hint && <p className="text-xs text-stone-500">{hint}</p>}
      <Err errors={errors} />
      <div className="flex justify-end gap-2">
        <button type="button" className="btn-ghost" onClick={onBack} disabled={busy}>Back</button>
        <button type="submit" className={tone === 'danger' ? 'btn-danger' : 'btn-primary'} disabled={busy || reason.trim().length < 3}>{busy ? 'Saving...' : button}</button>
      </div>
    </form>
  )
}
