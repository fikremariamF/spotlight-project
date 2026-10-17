import { ArrowLeft } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { toast } from 'sonner'
import { ErrorState, PageHeader, Spinner, StatusBadge } from '../../../components/ui'
import { api, parseError } from '../../../lib/api'
import { formatDateTime } from '../../../lib/format'
import { useFetch } from '../../../lib/useFetch'
import { ADJUSTMENTS, Change, Err, MOVEMENT_LABELS, StockBadge, birr, money, useSubmit } from './shared'

function DetailsForm({ productId, item, onDone, readOnly }) {
  const [v, setV] = useState({
    sku: item.sku || '', cost_price: birr(item.own_cost_price_santim),
    min_stock_level: item.min_stock_level ?? '', reorder_quantity: item.reorder_quantity ?? '',
  })
  const { busy, errors, submit } = useSubmit(onDone)
  const key = item.variant_id ?? 'listing'
  const field = (name, label, props = {}) => (
    <label className="text-xs text-stone-600" htmlFor={`${key}-${name}`}>{label}
      <input id={`${key}-${name}`} className="input mt-1" value={v[name]} disabled={readOnly} aria-invalid={errors[name] ? true : undefined}
        onChange={(e) => setV({ ...v, [name]: e.target.value })} {...props} />
    </label>
  )
  const save = (e) => {
    e.preventDefault()
    submit(() => api.put(`/seller/erp/inventory/${productId}`, {
      variant_id: item.variant_id, sku: v.sku.trim() || null, cost_price: String(v.cost_price).trim().replace(/,/g, '') || null,
      min_stock_level: v.min_stock_level === '' ? null : Number(v.min_stock_level),
      reorder_quantity: v.reorder_quantity === '' ? null : Number(v.reorder_quantity),
    }), 'Saved')
  }
  return (
    <form onSubmit={save} noValidate className="space-y-2">
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        {field('sku', 'SKU', { maxLength: 40, placeholder: 'Optional' })}
        {field('cost_price', 'Cost per unit (ETB)', { inputMode: 'decimal', placeholder: item.variant_id && item.cost_price_santim !== null && item.own_cost_price_santim === null ? `${birr(item.cost_price_santim)} (listing)` : 'What you pay' })}
        {field('min_stock_level', 'Low-stock level', { inputMode: 'numeric', placeholder: `Store default` })}
        {field('reorder_quantity', 'Usual reorder', { inputMode: 'numeric', placeholder: 'Optional' })}
      </div>
      <Err errors={errors} />
      {!readOnly && <div className="flex justify-end"><button type="submit" className="btn-secondary" disabled={busy}>{busy ? 'Saving...' : 'Save details'}</button></div>}
    </form>
  )
}

function AdjustForm({ productId, item, onDone }) {
  const [v, setV] = useState({ type: 'count', quantity: '', warehouse_id: item.locations?.[0]?.warehouse_id ?? '', note: '' })
  const [confirm, setConfirm] = useState(false)
  const { busy, errors, submit } = useSubmit(onDone)
  const adj = ADJUSTMENTS.find((a) => a.type === v.type)
  const here = item.locations?.find((l) => l.warehouse_id === Number(v.warehouse_id))?.quantity ?? item.available
  const qty = Number(v.quantity)
  const change = v.type === 'count' ? qty - here : ['damaged', 'lost'].includes(v.type) ? -qty : qty
  const valid = v.quantity !== '' && Number.isInteger(qty) && qty >= 0 && (v.type === 'count' || qty > 0)
  const open = (item.locations || []).filter((l) => l.is_active)

  async function send() {
    const ok = await submit(() => api.post(`/seller/erp/inventory/${productId}/adjust`, {
      variant_id: item.variant_id, warehouse_id: v.warehouse_id || null, type: v.type, quantity: qty,
      note: v.note.trim() || null, expected_available: item.available,
    }), 'Stock updated')
    setConfirm(false)
    if (ok) setV((s) => ({ ...s, quantity: '', note: '' }))
  }

  return (
    <form noValidate className="space-y-2" onSubmit={(e) => { e.preventDefault(); if (valid) setConfirm(true) }}>
      <div className="grid gap-2 sm:grid-cols-[1.3fr_1fr_0.8fr]">
        <label className="text-xs text-stone-600" htmlFor={`adj-type-${item.variant_id ?? 'l'}`}>What happened
          <select id={`adj-type-${item.variant_id ?? 'l'}`} className="input mt-1" value={v.type} onChange={(e) => { setV({ ...v, type: e.target.value }); setConfirm(false) }}>
            {ADJUSTMENTS.map((a) => <option key={a.type} value={a.type}>{a.label}</option>)}
          </select>
        </label>
        {open.length > 1 ? (
          <label className="text-xs text-stone-600" htmlFor={`adj-wh-${item.variant_id ?? 'l'}`}>Location
            <select id={`adj-wh-${item.variant_id ?? 'l'}`} className="input mt-1" value={v.warehouse_id} onChange={(e) => { setV({ ...v, warehouse_id: e.target.value }); setConfirm(false) }}>
              {open.map((l) => <option key={l.warehouse_id} value={l.warehouse_id}>{l.name} ({l.quantity})</option>)}
            </select>
          </label>
        ) : <p className="text-xs text-stone-600">Location<span className="mt-2 block text-sm text-stone-900">{open[0]?.name || 'Main store'}</span></p>}
        <label className="text-xs text-stone-600" htmlFor={`adj-q-${item.variant_id ?? 'l'}`}>{v.type === 'count' ? 'Counted' : 'Units'}
          <input id={`adj-q-${item.variant_id ?? 'l'}`} className="input mt-1" inputMode="numeric" value={v.quantity} onChange={(e) => { setV({ ...v, quantity: e.target.value }); setConfirm(false) }} />
        </label>
      </div>
      <p className="text-xs text-stone-500">{adj.hint}</p>
      <label className="block text-xs text-stone-600" htmlFor={`adj-n-${item.variant_id ?? 'l'}`}>Note{['damaged', 'lost'].includes(v.type) ? ' (required)' : ''}
        <input id={`adj-n-${item.variant_id ?? 'l'}`} className="input mt-1" maxLength={255} value={v.note} onChange={(e) => setV({ ...v, note: e.target.value })} placeholder="For your records" />
      </label>
      <Err errors={errors} />
      {confirm ? (
        <div className="flex flex-wrap items-center justify-end gap-2 rounded-lg bg-amber-50 p-2 text-sm" role="alertdialog" aria-label="Confirm stock change">
          <span className="text-amber-900">{change === 0 ? 'The count matches; this records that it was checked.' : <>Change available stock by <Change n={change} /> to {Math.max(0, here + change)} here?</>}</span>
          <button type="button" className="btn-ghost" onClick={() => setConfirm(false)} disabled={busy}>Back</button>
          <button type="button" className="btn-primary" onClick={send} disabled={busy} autoFocus>{busy ? 'Saving...' : 'Confirm'}</button>
        </div>
      ) : (
        <div className="flex justify-end"><button type="submit" className="btn-primary" disabled={!valid}>Record</button></div>
      )}
    </form>
  )
}

export default function ErpItem() {
  const { id } = useParams()
  const res = useFetch(`/seller/erp/inventory/${id}`)
  const summary = useFetch('/seller/summary')
  const readOnly = ['suspended', 'rejected'].includes(summary.data?.data?.store?.status)
  const p = res.data?.data
  const onDone = (body) => res.setData(body)

  if (res.loading && !p) return <Spinner />
  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />

  const generate = async () => {
    try {
      res.setData((await api.post(`/seller/erp/inventory/${id}/sku`)).data)
      toast.success('SKUs created')
    } catch (err) {
      toast.error(parseError(err).message)
    }
  }
  const missingSku = p.items.some((i) => !i.sku)

  return (
    <>
      <Link to="/seller/erp/inventory" className="mb-3 inline-flex items-center gap-1 text-sm text-stone-600 hover:underline"><ArrowLeft className="size-4" aria-hidden="true" />Inventory</Link>
      <PageHeader title={p.name}
        subtitle={<span className="inline-flex flex-wrap items-center gap-2"><StatusBadge status={p.status} /><StockBadge state={p.state} />{p.category && <span>{p.category}</span>}</span>}
        actions={<>
          {!readOnly && missingSku && <button type="button" className="btn-secondary" onClick={generate}>Create missing SKUs</button>}
          <Link to={`/seller/products/${p.id}/edit`} className="btn-secondary">Edit listing</Link>
          {p.status === 'published' && <Link to={`/products/${p.slug}`} className="btn-ghost">View in shop</Link>}
        </>} />
      {readOnly && <p className="mb-4 rounded-lg bg-stone-100 px-4 py-3 text-sm text-stone-700">Your store cannot change records right now. You can still look at them.</p>}

      <UsualSupplier productId={p.id} supplier={p.supplier} onDone={onDone} readOnly={readOnly} />

      {p.has_variants && (
        <section className="card mb-4 p-4">
          <h2 className="mb-2 font-semibold text-stone-900">Listing cost price</h2>
          <p className="mb-2 text-sm text-stone-600">Used for options without their own cost.</p>
          <ListingCost productId={p.id} cost={p.cost_price_santim} onDone={onDone} readOnly={readOnly} />
        </section>
      )}

      <div className="space-y-4">
        {p.items.map((it) => (
          <section key={it.variant_id ?? 'listing'} className="card p-4" aria-label={it.label || p.name}>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <h2 className="font-semibold text-stone-900">{it.label || 'Stock'}</h2>
              <span className="flex flex-wrap items-center gap-3 text-sm text-stone-600">
                {it.tracked && <span><strong className="text-stone-900 tabular-nums">{it.available}</strong> available</span>}
                {it.held > 0 && <span>{it.held} held for orders</span>}
                <span>{it.sold} sold</span>
                <StockBadge state={it.state} />
              </span>
            </div>
            {it.tracked && it.locations?.length > 1 && (
              <ul className="mb-3 flex flex-wrap gap-2 text-sm">
                {it.locations.map((l) => <li key={l.warehouse_id} className="chip">{l.name}: <strong className="ml-1 tabular-nums">{l.quantity}</strong></li>)}
              </ul>
            )}
            <div className="grid gap-5 lg:grid-cols-2">
              <div>
                <h3 className="mb-2 text-sm font-semibold text-stone-500">Details</h3>
                <DetailsForm key={`${it.sku}-${it.own_cost_price_santim}-${it.min_stock_level}-${it.reorder_quantity}`} productId={p.id} item={it} onDone={onDone} readOnly={readOnly} />
                <p className="mt-2 text-xs text-stone-500">
                  Value: {it.value_cost_santim === null ? 'set a cost price' : money(it.value_cost_santim)} at cost, {money(it.value_price_santim)} at your price ({money(it.price_santim)} each). Low stock at {it.low_level} or fewer.
                </p>
              </div>
              {it.tracked && !readOnly && (
                <div>
                  <h3 className="mb-2 text-sm font-semibold text-stone-500">Record a stock change</h3>
                  <AdjustForm key={it.available} productId={p.id} item={it} onDone={onDone} />
                </div>
              )}
            </div>
          </section>
        ))}
      </div>

      <section className="card mt-5 p-4">
        <div className="mb-3 flex items-center justify-between"><h2 className="font-semibold text-stone-900">Stock history</h2><Link to={`/seller/erp/movements?product_id=${p.id}`} className="text-sm font-semibold text-brand-700 hover:underline">All of it</Link></div>
        <MovementTable movements={p.movements} />
      </section>
    </>
  )
}

/** The supplier this listing is usually bought from: listed first when you add items to their orders. */
function UsualSupplier({ productId, supplier, onDone, readOnly }) {
  const list = useFetch('/seller/erp/suppliers?brief=1')
  const [value, setValue] = useState(supplier ? String(supplier.id) : '')
  const { busy, errors, submit } = useSubmit(onDone)
  const options = (list.data?.data || []).filter((x) => x.is_active || String(x.id) === value)
  if (!supplier && !readOnly && list.data && options.length === 0) return null
  return (
    <form noValidate className="card mb-4 flex flex-wrap items-end gap-2 p-4" onSubmit={(e) => { e.preventDefault(); submit(() => api.put(`/seller/erp/inventory/${productId}`, { supplier_id: value ? Number(value) : null }), 'Saved') }}>
      <label className="text-sm text-stone-700" htmlFor="usual-supplier">Usual supplier
        <select id="usual-supplier" className="input mt-1 w-64 max-w-full" value={value} disabled={readOnly} onChange={(e) => setValue(e.target.value)}>
          <option value="">None</option>
          {options.map((x) => <option key={x.id} value={x.id}>{x.name}{x.is_active ? '' : ' (inactive)'}</option>)}
        </select>
      </label>
      {!readOnly && value !== (supplier ? String(supplier.id) : '') && <button type="submit" className="btn-secondary" disabled={busy}>Save</button>}
      {supplier && <Link to={`/seller/erp/suppliers/${supplier.id}`} className="btn-ghost">See supplier</Link>}
      <Err errors={errors} />
    </form>
  )
}

function ListingCost({ productId, cost, onDone, readOnly }) {
  const [value, setValue] = useState(birr(cost))
  const { busy, errors, submit } = useSubmit(onDone)
  return (
    <form noValidate className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); submit(() => api.put(`/seller/erp/inventory/${productId}`, { cost_price: value.trim().replace(/,/g, '') || null }), 'Saved') }}>
      <label className="text-xs text-stone-600" htmlFor="listing-cost">Cost per unit (ETB)<input id="listing-cost" className="input mt-1 w-40" inputMode="decimal" value={value} disabled={readOnly} onChange={(e) => setValue(e.target.value)} /></label>
      {!readOnly && <button type="submit" className="btn-secondary" disabled={busy}>Save</button>}
      <Err errors={errors} />
    </form>
  )
}

/** Where a stock change's note leads: the transfer, shop sale or marketplace sale it came from. */
function noteLink(m) {
  if (m.reference_type === 'stock_transfer') return `/seller/erp/transfers/${m.reference_id}`
  if (m.reference_type === 'offline_sale') return `/seller/erp/sales/offline/${m.reference_id}`
  if (m.reference_type === 'sale_return' && m.order) return `/seller/erp/sales/orders/${m.order.id}`
  return null
}

export function MovementTable({ movements, withProduct = false }) {
  if (!movements.length) return <p className="text-sm text-stone-500">No stock changes yet.</p>
  return (
    <div className="relative overflow-x-auto">
      <table className="rtable rtable-flush w-full text-left text-sm">
        <thead className="text-xs text-stone-500 uppercase">
          <tr><th className="py-2 pr-3">When</th>{withProduct && <th className="py-2 pr-3">Item</th>}<th className="py-2 pr-3">What</th><th className="py-2 pr-3 text-right">Change</th><th className="py-2 pr-3 text-right">After</th><th className="py-2">By</th></tr>
        </thead>
        <tbody className="divide-y divide-stone-100">
          {movements.map((m) => (
            <tr key={m.id}>
              <td className="rtable-title py-2 pr-3 whitespace-nowrap">{formatDateTime(m.created_at)}</td>
              {withProduct && <td data-label="Item" className="py-2 pr-3">{m.product ? <Link to={`/seller/erp/inventory/${m.product.id}`} className="hover:underline">{m.product.name}</Link> : ''}{m.variant && <span className="text-stone-500"> ({m.variant})</span>}</td>}
              <td data-label="What" className="py-2 pr-3">
                {MOVEMENT_LABELS[m.type] || m.type}{!withProduct && m.variant && <span className="text-stone-500"> · {m.variant}</span>}
                {m.order && <> · <Link to={`/seller/orders/${m.order.id}`} className="text-brand-700 hover:underline">{m.order.number}</Link></>}
                {m.warehouse && <span className="text-stone-500"> · {m.warehouse}</span>}
                {m.note && <span className="block text-xs text-stone-500">{noteLink(m) ? <Link to={noteLink(m)} className="hover:underline">{m.note}</Link> : m.note}</span>}
              </td>
              <td data-label="Change" className="py-2 pr-3 text-right"><Change n={m.quantity} /></td>
              <td data-label="After" className="py-2 pr-3 text-right tabular-nums">{m.available_after}</td>
              <td data-label="By" className="py-2 text-stone-600">{m.by || (m.order ? 'Order' : 'System')}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
