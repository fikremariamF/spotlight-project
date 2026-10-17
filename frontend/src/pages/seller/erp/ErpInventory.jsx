import { Search } from 'lucide-react'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { EmptyState, ErrorState, PageHeader, Pagination, Spinner, StatusBadge } from '../../../components/ui'
import { toQuery } from '../../../lib/format'
import { useFetch } from '../../../lib/useFetch'
import { CsvButton, StockBadge, money } from './shared'

const STOCK_FILTERS = [['', 'All'], ['low', 'Low stock'], ['out', 'Out of stock'], ['ok', 'In stock'], ['made_to_order', 'Made to order']]
const SORTS = [['updated', 'Recently changed'], ['name', 'Name'], ['stock', 'Units available'], ['sku', 'SKU'], ['price', 'Price']]

export default function ErpInventory() {
  const [params, setParams] = useSearchParams()
  const filters = {
    q: params.get('q') || '', stock: params.get('stock') || '', status: params.get('status') || '', supplier_id: params.get('supplier_id') || '',
    sort: params.get('sort') || 'updated', dir: params.get('dir') || '', page: params.get('page') || '',
  }
  const [q, setQ] = useState(filters.q)
  const query = toQuery(filters)
  const res = useFetch(`/seller/erp/inventory?${query}`)
  const suppliers = useFetch('/seller/erp/suppliers?brief=1')
  const set = (next) => setParams(toQuery({ ...filters, page: '', ...next }))
  const rows = res.data?.data || []

  return (
    <>
      <PageHeader title="Inventory" subtitle="Every listing and option with its stock, value and history. Change stock here or on the listing."
        actions={<CsvButton url={`/seller/erp/inventory.csv?${toQuery({ ...filters, page: '' })}`} filename="inventory.csv" />} />

      <div className="mb-4 space-y-3">
        <form role="search" className="flex gap-2" onSubmit={(e) => { e.preventDefault(); set({ q }) }}>
          <label htmlFor="inv-q" className="sr-only">Search by name, SKU or option</label>
          <div className="relative flex-1">
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" aria-hidden="true" />
            <input id="inv-q" type="search" className="input pl-9" placeholder="Name, SKU, color or size" value={q} onChange={(e) => setQ(e.target.value)} maxLength={100} />
          </div>
          <button type="submit" className="btn-secondary">Search</button>
        </form>
        <div className="flex flex-wrap items-center gap-2">
          {STOCK_FILTERS.map(([key, label]) => (
            <button key={key || 'all'} type="button" aria-pressed={filters.stock === key} onClick={() => set({ stock: key })}
              className={`chip ${filters.stock === key ? 'border-brand-600 bg-brand-50 font-semibold text-brand-800' : ''}`}>{label}</button>
          ))}
          {suppliers.data?.data?.length > 0 && (
            <label className="flex items-center gap-2 text-sm text-stone-600">Supplier
              <select className="input w-auto max-w-[12rem] py-1.5" value={filters.supplier_id} onChange={(e) => set({ supplier_id: e.target.value })}>
                <option value="">Any</option>
                {suppliers.data.data.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
              </select>
            </label>
          )}
          <label className="ml-auto flex items-center gap-2 text-sm text-stone-600">Sort
            <select className="input w-auto py-1.5" value={filters.sort} onChange={(e) => set({ sort: e.target.value, dir: '' })}>
              {SORTS.map(([key, label]) => <option key={key} value={key}>{label}</option>)}
            </select>
          </label>
          <button type="button" className="btn-ghost px-2" onClick={() => set({ dir: filters.dir === 'desc' ? 'asc' : 'desc' })}
            aria-label={filters.dir === 'desc' ? 'Sorted high to low, switch to low to high' : 'Sorted low to high, switch to high to low'}>
            {filters.sort === 'updated' ? (filters.dir === 'asc' ? 'Oldest first' : 'Newest first') : filters.dir === 'desc' ? 'High to low' : 'Low to high'}
          </button>
        </div>
      </div>

      {res.loading && !res.data ? <Spinner /> : res.error ? <ErrorState error={res.error} onRetry={res.reload} /> : rows.length === 0 ? (
        <EmptyState title={filters.q || filters.stock || filters.supplier_id ? 'Nothing matches' : 'No listings yet'}>
          {filters.q || filters.stock || filters.supplier_id ? 'Try another search or filter.' : <>Stock items are your listings. <Link to="/seller/products/new" className="font-semibold text-brand-700 underline">Add a listing</Link> to start.</>}
        </EmptyState>
      ) : (
        <div className="card relative overflow-x-auto">
          <table className="rtable w-full text-left text-sm">
            <thead className="text-xs text-stone-500 uppercase">
              <tr><th className="px-4 py-2">Item</th><th className="px-3 py-2 text-right">Available</th><th className="px-3 py-2 text-right">Held</th><th className="px-3 py-2 text-right">Sold</th><th className="px-3 py-2">Stock</th><th className="px-4 py-2 text-right">Value at cost</th></tr>
            </thead>
            <tbody className="divide-y divide-stone-100">
              {rows.map((r) => (
                <InventoryRow key={r.id} r={r} />
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Pagination meta={res.data?.meta} onPage={(page) => setParams(toQuery({ ...filters, page }))} />
      {res.data?.meta?.total > 0 && <p className="mt-3 text-xs text-stone-500">{res.data.meta.total} listings. Held units are taken by orders that are not delivered yet. Value at cost counts available and held units.</p>}
    </>
  )
}

function InventoryRow({ r }) {
  return (
    <>
      <tr className="align-top">
        <td className="rtable-title px-4 py-3">
          <Link to={`/seller/erp/inventory/${r.id}`} className="flex items-center gap-3 hover:underline">
            {r.image ? <img src={r.image} alt="" className="size-10 shrink-0 rounded-lg object-cover" /> : <span className="size-10 shrink-0 rounded-lg bg-stone-100" />}
            <span className="min-w-0">
              <span className="block font-semibold text-stone-900">{r.name}</span>
              <span className="flex flex-wrap items-center gap-2 text-xs text-stone-500">
                {r.sku && <span>{r.sku}</span>}{r.has_variants && <span>{r.items.length} options</span>}<StatusBadge status={r.status} />
              </span>
            </span>
          </Link>
        </td>
        <td data-label="Available" className="px-3 py-3 text-right font-semibold tabular-nums">{r.stock_type === 'made_to_order' ? '-' : r.available}</td>
        <td data-label="Held" className="px-3 py-3 text-right tabular-nums">{r.held || ''}</td>
        <td data-label="Sold" className="px-3 py-3 text-right tabular-nums">{r.sold || ''}</td>
        <td data-label="Stock" className="px-3 py-3"><StockBadge state={r.state} /></td>
        <td data-label="Value at cost" className="px-4 py-3 text-right tabular-nums">
          {r.stock_type === 'made_to_order' ? '' : r.missing_cost ? <span className="text-amber-800">{r.value_cost_santim ? money(r.value_cost_santim) : ''} cost missing</span> : money(r.value_cost_santim)}
        </td>
      </tr>
      {r.has_variants && r.items.map((it) => (
        <tr key={it.variant_id} className="bg-stone-50/60 text-stone-700">
          <td className="rtable-title py-1.5 pr-3 pl-16 text-sm">{it.label}{it.sku && <span className="ml-2 text-xs text-stone-500">{it.sku}</span>}</td>
          <td data-label="Available" className="px-3 py-1.5 text-right tabular-nums">{it.tracked ? it.available : '-'}</td>
          <td data-label="Held" className="px-3 py-1.5 text-right tabular-nums">{it.held || ''}</td>
          <td data-label="Sold" className="px-3 py-1.5 text-right tabular-nums">{it.sold || ''}</td>
          <td data-label="Stock" className="px-3 py-1.5"><StockBadge state={it.state} /></td>
          <td data-label="Value at cost" className="px-4 py-1.5 text-right tabular-nums">{it.tracked ? money(it.value_cost_santim) : ''}</td>
        </tr>
      ))}
    </>
  )
}
