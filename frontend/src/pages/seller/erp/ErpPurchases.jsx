import { Search } from 'lucide-react'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { EmptyState, ErrorState, PageHeader, Pagination, Spinner } from '../../../components/ui'
import { toQuery } from '../../../lib/format'
import { useFetch } from '../../../lib/useFetch'
import { Badge, PO_STATUS, money, useReadOnly } from './shared'

const FILTERS = [['', 'All'], ['draft', 'Drafts'], ['open', 'Waiting for goods'], ['late', 'Late'], ['partially_received', 'Partly received'], ['received', 'Received'], ['cancelled', 'Cancelled']]

export default function ErpPurchases() {
  const readOnly = useReadOnly()
  const [params, setParams] = useSearchParams()
  const filters = {
    status: params.get('status') || '', supplier_id: params.get('supplier_id') || '', q: params.get('q') || '',
    from: params.get('from') || '', to: params.get('to') || '', page: params.get('page') || '',
  }
  const [form, setForm] = useState({ q: filters.q, from: filters.from, to: filters.to })
  const res = useFetch(`/seller/erp/purchases?${toQuery(filters)}`)
  const suppliers = useFetch('/seller/erp/suppliers?brief=1')
  const set = (next) => setParams(toQuery({ ...filters, page: '', ...next }))
  const rows = res.data?.data || []
  const counts = res.data?.meta?.counts || {}
  const filtered = filters.status || filters.supplier_id || filters.q || filters.from || filters.to

  return (
    <>
      <PageHeader title="Purchases" subtitle="Orders you place with suppliers. Stock goes up only when goods arrive and you receive them here."
        actions={!readOnly && <Link to="/seller/erp/purchases/new" className="btn-primary">New purchase order</Link>} />

      <div className="mb-4 space-y-3">
        <div className="flex flex-wrap gap-2">
          {FILTERS.map(([key, label]) => (
            <button key={key || 'all'} type="button" aria-pressed={filters.status === key} onClick={() => set({ status: key })}
              className={`chip ${filters.status === key ? 'border-brand-600 bg-brand-50 font-semibold text-brand-800' : ''} ${key === 'late' && counts.late ? 'text-red-800' : ''}`}>
              {label}{counts[key] ? ` (${counts[key]})` : ''}
            </button>
          ))}
        </div>
        <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); set(form) }}>
          <label className="min-w-0 flex-1 text-sm" htmlFor="po-q"><span className="sr-only">Search by order number or supplier reference</span>
            <span className="relative block">
              <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" aria-hidden="true" />
              <input id="po-q" type="search" className="input pl-9" placeholder="PO number or supplier reference" value={form.q} onChange={(e) => setForm({ ...form, q: e.target.value })} maxLength={60} />
            </span>
          </label>
          <label className="text-sm">Supplier
            <select className="input mt-1 w-auto max-w-[14rem]" value={filters.supplier_id} onChange={(e) => set({ supplier_id: e.target.value })}>
              <option value="">All suppliers</option>
              {(suppliers.data?.data || []).map((s) => <option key={s.id} value={s.id}>{s.name}{s.is_active ? '' : ' (inactive)'}</option>)}
            </select>
          </label>
          <label className="text-sm">Ordered from<input type="date" className="input mt-1 w-auto" value={form.from} onChange={(e) => setForm({ ...form, from: e.target.value })} /></label>
          <label className="text-sm">to<input type="date" className="input mt-1 w-auto" value={form.to} min={form.from || undefined} onChange={(e) => setForm({ ...form, to: e.target.value })} /></label>
          <button type="submit" className="btn-secondary">Show</button>
          {filtered && <button type="button" className="btn-ghost" onClick={() => { setForm({ q: '', from: '', to: '' }); setParams('') }}>Clear</button>}
        </form>
      </div>

      {res.loading && !res.data ? <Spinner /> : res.error ? <ErrorState error={res.error} onRetry={res.reload} /> : rows.length === 0 ? (
        <EmptyState title={filtered ? 'Nothing matches' : 'No purchase orders yet'}
          action={!filtered && !readOnly && <Link to="/seller/erp/purchases/new" className="btn-primary">Create a purchase order</Link>}>
          {filtered ? 'Try another filter.' : 'A purchase order lists what you are buying from a supplier. When the goods arrive, receive them here and your stock goes up.'}
        </EmptyState>
      ) : <div className="card"><PurchaseTable orders={rows} /></div>}
      <Pagination meta={res.data?.meta} onPage={(page) => setParams(toQuery({ ...filters, page }))} />
    </>
  )
}

/** Purchase orders with what was received and what is still to pay. */
export function PurchaseTable({ orders, flush = false, hideSupplier = false }) {
  const px = flush ? 'pr-3' : 'px-4'
  return (
    <div className="relative overflow-x-auto">
      <table className={`rtable w-full text-left text-sm ${flush ? 'rtable-flush' : ''}`}>
        <thead className="text-xs text-stone-500 uppercase">
          <tr>
            <th className={`${px} py-2`}>Order</th>{!hideSupplier && <th className="px-3 py-2">Supplier</th>}<th className="px-3 py-2">Ordered</th><th className="px-3 py-2">Expected</th>
            <th className="px-3 py-2">Status</th><th className="px-3 py-2 text-right">Total</th><th className="px-3 py-2 text-right">Received</th><th className={`${flush ? '' : 'px-4'} py-2 text-right`}>To pay</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-stone-100">
          {orders.map((o) => (
            <tr key={o.id}>
              <td className={`rtable-title ${px} py-2.5`}><Link to={`/seller/erp/purchases/${o.id}`} className="font-semibold text-brand-700 hover:underline">{o.number}</Link></td>
              {!hideSupplier && <td data-label="Supplier" className="px-3 py-2.5">{o.supplier?.name}</td>}
              <td data-label="Ordered" className="px-3 py-2.5 whitespace-nowrap">{o.order_date}</td>
              <td data-label="Expected" className={`px-3 py-2.5 whitespace-nowrap ${o.late ? 'font-semibold text-red-700' : ''}`}>{o.expected_date || ''}</td>
              <td data-label="Status" className="px-3 py-2.5"><Badge map={PO_STATUS} value={o.status} late={o.late} /></td>
              <td data-label="Total" className="px-3 py-2.5 text-right tabular-nums">{money(o.total_santim)}</td>
              <td data-label="Received" className="px-3 py-2.5 text-right tabular-nums">{o.received_value_santim ? money(o.received_value_santim) : ''}</td>
              <td data-label="To pay" className={`${flush ? '' : 'px-4'} py-2.5 text-right tabular-nums`}>{o.balance_santim > 0 ? <strong>{money(o.balance_santim)}</strong> : o.balance_santim < 0 ? <span className="text-emerald-700">{money(-o.balance_santim)} paid ahead</span> : ''}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
