import { Search } from 'lucide-react'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { EmptyState, ErrorState, PageHeader, Pagination, Spinner, Stats } from '../../../components/ui'
import { toQuery } from '../../../lib/format'
import { useFetch } from '../../../lib/useFetch'
import { money, useReadOnly } from './shared'

const FILTERS = [['', 'All'], ['active', 'Active'], ['inactive', 'Inactive'], ['owing', 'You owe them']]
const SORTS = [['name', 'Name'], ['owed', 'Most owed'], ['recent', 'Latest order']]

/** "ETB 5,000" owed, or "ETB 200 paid ahead" when payments are more than what was bought. */
export function Owed({ santim }) {
  if (santim < 0) return <span className="text-emerald-700">{money(-santim)} paid ahead</span>
  return <span className={santim > 0 ? 'font-semibold text-stone-900' : 'text-stone-500'}>{money(santim)}</span>
}

export default function ErpSuppliers() {
  const readOnly = useReadOnly()
  const [params, setParams] = useSearchParams()
  const filters = { q: params.get('q') || '', show: params.get('show') || '', sort: params.get('sort') || 'name', page: params.get('page') || '' }
  const [q, setQ] = useState(filters.q)
  const query = toQuery({
    q: filters.q, sort: filters.sort, page: filters.page,
    status: ['active', 'inactive'].includes(filters.show) ? filters.show : '', owing: filters.show === 'owing',
  })
  const res = useFetch(`/seller/erp/suppliers?${query}`)
  const set = (next) => setParams(toQuery({ ...filters, page: '', ...next }))
  const rows = res.data?.data || []
  const meta = res.data?.meta

  return (
    <>
      <PageHeader title="Suppliers" subtitle="Who you buy from, what you bought and what you still owe. Only your store sees this."
        actions={!readOnly && <Link to="/seller/erp/suppliers/new" className="btn-primary">Add supplier</Link>} />
      {meta && (
        <Stats className="mb-4" items={[
          { label: 'You owe suppliers', value: money(meta.owed_santim), tone: meta.owed_santim > 0 ? 'warn' : undefined, hint: 'invoices, or goods received without one, minus payments' },
          { label: 'Received, no invoice yet', value: money(meta.received_not_invoiced_santim), hint: 'valued at the purchase order prices' },
        ]} />
      )}
      <div className="mb-4 space-y-3">
        <form role="search" className="flex gap-2" onSubmit={(e) => { e.preventDefault(); set({ q }) }}>
          <label htmlFor="sup-q" className="sr-only">Search suppliers</label>
          <div className="relative flex-1">
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" aria-hidden="true" />
            <input id="sup-q" type="search" className="input pl-9" placeholder="Name, contact person, phone or email" value={q} onChange={(e) => setQ(e.target.value)} maxLength={100} />
          </div>
          <button type="submit" className="btn-secondary">Search</button>
        </form>
        <div className="flex flex-wrap items-center gap-2">
          {FILTERS.map(([key, label]) => (
            <button key={key || 'all'} type="button" aria-pressed={filters.show === key} onClick={() => set({ show: key })}
              className={`chip ${filters.show === key ? 'border-brand-600 bg-brand-50 font-semibold text-brand-800' : ''}`}>{label}</button>
          ))}
          <label className="ml-auto flex items-center gap-2 text-sm text-stone-600">Sort
            <select className="input w-auto py-1.5" value={filters.sort} onChange={(e) => set({ sort: e.target.value })}>
              {SORTS.map(([key, label]) => <option key={key} value={key}>{label}</option>)}
            </select>
          </label>
        </div>
      </div>

      {res.loading && !res.data ? <Spinner /> : res.error ? <ErrorState error={res.error} onRetry={res.reload} /> : rows.length === 0 ? (
        <EmptyState title={filters.q || filters.show ? 'Nothing matches' : 'No suppliers yet'}
          action={!filters.q && !filters.show && !readOnly && <Link to="/seller/erp/suppliers/new" className="btn-primary">Add your first supplier</Link>}>
          {filters.q || filters.show ? 'Try another search or filter.' : 'Add the workshops and wholesalers you buy from, then send them purchase orders.'}
        </EmptyState>
      ) : (
        <div className="card relative overflow-x-auto">
          <table className="rtable w-full text-left text-sm">
            <thead className="text-xs text-stone-500 uppercase">
              <tr><th className="px-4 py-2">Supplier</th><th className="px-3 py-2 text-right">Open orders</th><th className="px-3 py-2 text-right">Bought</th><th className="px-3 py-2 text-right">Paid</th><th className="px-4 py-2 text-right">Owed</th></tr>
            </thead>
            <tbody className="divide-y divide-stone-100">
              {rows.map((s) => (
                <tr key={s.id} className={s.is_active ? '' : 'text-stone-500'}>
                  <td className="rtable-title px-4 py-3">
                    <Link to={`/seller/erp/suppliers/${s.id}`} className="font-semibold text-stone-900 hover:underline">{s.name}</Link>
                    {!s.is_active && <span className="ml-2 rounded-full bg-stone-200 px-2 py-0.5 text-xs font-semibold text-stone-700">Inactive</span>}
                    <span className="block text-xs font-normal text-stone-500">{[s.contact_person, s.phone, s.last_order_date && `last order ${String(s.last_order_date).slice(0, 10)}`].filter(Boolean).join(' · ')}</span>
                  </td>
                  <td data-label="Open orders" className="px-3 py-3 text-right tabular-nums">{s.open_orders || ''}</td>
                  <td data-label="Bought" className="px-3 py-3 text-right tabular-nums">{money(s.bought_santim)}</td>
                  <td data-label="Paid" className="px-3 py-3 text-right tabular-nums">{money(s.paid_santim)}</td>
                  <td data-label="Owed" className="px-4 py-3 text-right tabular-nums"><Owed santim={s.owed_santim} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Pagination meta={meta} onPage={(page) => setParams(toQuery({ ...filters, page }))} />
    </>
  )
}
