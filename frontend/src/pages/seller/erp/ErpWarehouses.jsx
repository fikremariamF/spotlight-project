import { ArrowRight, MapPin } from 'lucide-react'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { EmptyState, ErrorState, PageHeader, Pagination, Spinner } from '../../../components/ui'
import { api } from '../../../lib/api'
import { formatDateTime, toQuery } from '../../../lib/format'
import { useFetch } from '../../../lib/useFetch'
import { Badge, Err, TRANSFER_STATUS, money, useReadOnly, useSubmit } from './shared'

const TRANSFER_FILTERS = [['', 'All'], ['in_transit', 'On the way'], ['received', 'Received'], ['cancelled', 'Cancelled']]

export default function ErpWarehouses() {
  const readOnly = useReadOnly()
  const res = useFetch('/seller/erp/warehouses')
  const [params, setParams] = useSearchParams()
  const filters = { status: params.get('status') || '', page: params.get('page') || '' }
  const transfers = useFetch(`/seller/erp/transfers?${toQuery(filters)}`)
  const [adding, setAdding] = useState(false)
  const list = res.data?.data || []
  const open = list.filter((w) => w.is_active)
  const max = res.data?.meta?.max ?? 20

  return (
    <>
      <PageHeader title="Warehouses" subtitle="Where your stock is kept: your shop, a store room or a workshop. Marketplace orders take units from your main location first."
        actions={!readOnly && <>
          {open.length > 1 && <Link to="/seller/erp/transfers/new" className="btn-primary">Move stock</Link>}
          {list.length < max && <button type="button" className="btn-secondary" onClick={() => setAdding(true)} disabled={adding}>Add location</button>}
        </>} />

      {adding && (
        <section className="card mb-5 p-4">
          <h2 className="mb-3 font-semibold text-stone-900">New location</h2>
          <LocationForm onDone={() => { setAdding(false); res.reload() }} onCancel={() => setAdding(false)} />
        </section>
      )}

      {res.loading && !res.data ? <Spinner /> : res.error ? <ErrorState error={res.error} onRetry={res.reload} /> : (
        <>
          <ul className="grid grid-cols-[minmax(0,1fr)] gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {list.map((w) => (
              <li key={w.id}>
                <Link to={`/seller/erp/warehouses/${w.id}`} className={`card block h-full p-4 hover:border-brand-300 ${w.is_active ? '' : 'opacity-70'}`}>
                  <span className="flex flex-wrap items-center gap-2">
                    <span className="font-semibold text-stone-900">{w.name}</span>
                    {w.code && <span className="text-xs text-stone-500">{w.code}</span>}
                    {w.is_default && <span className="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-800">Main</span>}
                    {!w.is_active && <span className="rounded-full bg-stone-200 px-2 py-0.5 text-xs font-semibold text-stone-700">Closed</span>}
                  </span>
                  {w.address_line && <span className="mt-1 flex items-start gap-1 text-sm text-stone-600"><MapPin className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />{w.address_line}</span>}
                  <span className="mt-3 grid grid-cols-3 gap-2 text-sm">
                    <span><span className="block text-xs text-stone-500">Units</span><strong className="tabular-nums">{w.units}</strong></span>
                    <span><span className="block text-xs text-stone-500">Items</span><strong className="tabular-nums">{w.items}</strong></span>
                    <span><span className="block text-xs text-stone-500">At cost</span><strong className="tabular-nums">{money(w.value_cost_santim)}</strong></span>
                  </span>
                  {(w.incoming_transfers > 0 || w.missing_cost > 0) && (
                    <span className="mt-2 block text-xs text-stone-500">
                      {[w.incoming_transfers > 0 && `${w.incoming_transfers} ${w.incoming_transfers === 1 ? 'transfer' : 'transfers'} on the way here`, w.missing_cost > 0 && `${w.missing_cost} items have no cost price`].filter(Boolean).join(' · ')}
                    </span>
                  )}
                </Link>
              </li>
            ))}
          </ul>
          {open.length < 2 && <p className="mt-3 text-sm text-stone-500">Add a second location to move stock between them. With one location, everything stays as it is.</p>}
          {list.length >= max && <p className="mt-3 text-sm text-stone-500">You have the most locations a store can have ({max}).</p>}
        </>
      )}

      <section className="mt-8">
        <h2 className="mb-3 text-lg font-semibold text-stone-900">Transfers</h2>
        <div className="mb-3 flex flex-wrap gap-2">
          {TRANSFER_FILTERS.map(([key, label]) => (
            <button key={key || 'all'} type="button" aria-pressed={filters.status === key} onClick={() => setParams(toQuery({ status: key }))}
              className={`chip ${filters.status === key ? 'border-brand-600 bg-brand-50 font-semibold text-brand-800' : ''}`}>
              {label}{key === 'in_transit' && transfers.data?.meta?.in_transit ? ` (${transfers.data.meta.in_transit})` : ''}
            </button>
          ))}
        </div>
        {transfers.loading && !transfers.data ? <Spinner /> : transfers.error ? <ErrorState error={transfers.error} onRetry={transfers.reload} />
          : transfers.data.data.length === 0 ? <EmptyState title={filters.status ? 'No transfers here' : 'No transfers yet'}>Moving stock from one location to another shows up here.</EmptyState>
            : <div className="card"><TransferTable transfers={transfers.data.data} /></div>}
        <Pagination meta={transfers.data?.meta} onPage={(page) => setParams(toQuery({ ...filters, page }))} />
      </section>
    </>
  )
}

export function TransferTable({ transfers, flush = false, compact = false }) {
  // In a narrow card a short list reads better than a squeezed table.
  if (compact) {
    return (
      <ul className="divide-y divide-stone-100 text-sm">
        {transfers.map((t) => (
          <li key={t.id} className="py-2">
            <p className="flex flex-wrap items-center justify-between gap-2">
              <Link to={`/seller/erp/transfers/${t.id}`} className="font-semibold whitespace-nowrap text-brand-700 hover:underline">{t.number}</Link>
              <Badge map={TRANSFER_STATUS} value={t.status} />
            </p>
            <p className="flex flex-wrap items-center gap-1 text-stone-600">{t.from.name}<ArrowRight className="size-3.5 text-stone-400" aria-label="to" />{t.to.name}<span className="text-stone-500">· {t.units} units · {formatDateTime(t.dispatched_at)}</span></p>
          </li>
        ))}
      </ul>
    )
  }
  return (
    <div className="relative overflow-x-auto">
      <table className={`rtable w-full text-left text-sm ${flush ? 'rtable-flush' : ''}`}>
        <thead className="text-xs text-stone-500 uppercase">
          <tr><th className={`${flush ? 'pr-3' : 'px-4'} py-2`}>Transfer</th><th className="px-3 py-2">From and to</th><th className="px-3 py-2 text-right">Units</th><th className="px-3 py-2">Status</th><th className={`${flush ? '' : 'px-4'} py-2`}>Sent</th></tr>
        </thead>
        <tbody className="divide-y divide-stone-100">
          {transfers.map((t) => (
            <tr key={t.id}>
              <td className={`rtable-title ${flush ? 'pr-3' : 'px-4'} py-2.5`}><Link to={`/seller/erp/transfers/${t.id}`} className="font-semibold whitespace-nowrap text-brand-700 hover:underline">{t.number}</Link></td>
              <td data-label="From and to" className="px-3 py-2.5"><span className="inline-flex flex-wrap items-center gap-1">{t.from.name}<ArrowRight className="size-3.5 text-stone-400" aria-label="to" />{t.to.name}</span></td>
              <td data-label="Units" className="px-3 py-2.5 text-right tabular-nums">{t.units}</td>
              <td data-label="Status" className="px-3 py-2.5"><Badge map={TRANSFER_STATUS} value={t.status} /></td>
              <td data-label="Sent" className={`${flush ? '' : 'px-4'} py-2.5 whitespace-nowrap text-stone-600`}>{formatDateTime(t.dispatched_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/** Add a location, or edit one (`location` given). */
export function LocationForm({ location = null, onDone, onCancel }) {
  const [v, setV] = useState({
    name: location?.name || '', code: location?.code || '', address_line: location?.address_line || '',
    contact_name: location?.contact_name || '', phone: location?.phone || '', notes: location?.notes || '',
  })
  const { busy, errors, submit } = useSubmit()
  const field = (name, label, props = {}) => (
    <label className="text-sm text-stone-700" htmlFor={`loc-${name}`}>{label}
      <input id={`loc-${name}`} className="input mt-1" value={v[name]} aria-invalid={errors[name] ? true : undefined} onChange={(e) => setV({ ...v, [name]: e.target.value })} {...props} />
      {errors[name] && <span className="mt-1 block text-xs font-medium text-red-700">{errors[name]}</span>}
    </label>
  )
  async function save(e) {
    e.preventDefault()
    const payload = Object.fromEntries(Object.entries(v).map(([k, x]) => [k, x.trim() || null]))
    const body = await submit(() => (location ? api.put(`/seller/erp/warehouses/${location.id}`, payload) : api.post('/seller/erp/warehouses', payload)), location ? 'Location saved' : 'Location added')
    if (body) onDone(body.data)
  }
  const other = Object.fromEntries(Object.entries(errors).filter(([k]) => !(k in v)))
  return (
    <form noValidate onSubmit={save} className="space-y-3">
      <div className="grid gap-3 sm:grid-cols-2">
        {field('name', 'Name', { maxLength: 100, placeholder: 'For example Bole shop', required: true })}
        {field('code', 'Short code', { maxLength: 20, placeholder: 'Optional, for example BOLE' })}
        {field('address_line', 'Address', { maxLength: 255, placeholder: 'Area, street, building' })}
        {field('contact_name', 'Person in charge', { maxLength: 100 })}
        {field('phone', 'Phone', { maxLength: 20, inputMode: 'tel' })}
        {field('notes', 'Notes', { maxLength: 500 })}
      </div>
      <Err errors={other} />
      <div className="flex justify-end gap-2">
        {onCancel && <button type="button" className="btn-ghost" onClick={onCancel} disabled={busy}>Cancel</button>}
        <button type="submit" className="btn-primary" disabled={busy || v.name.trim().length < 2}>{busy ? 'Saving...' : location ? 'Save location' : 'Add location'}</button>
      </div>
    </form>
  )
}
