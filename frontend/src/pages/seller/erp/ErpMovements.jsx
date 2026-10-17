import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { ErrorState, PageHeader, Pagination, Spinner } from '../../../components/ui'
import { toQuery } from '../../../lib/format'
import { useFetch } from '../../../lib/useFetch'
import { MovementTable } from './ErpItem'
import { CsvButton, MOVEMENT_LABELS } from './shared'

export default function ErpMovements() {
  const [params, setParams] = useSearchParams()
  const filters = { product_id: params.get('product_id') || '', warehouse_id: params.get('warehouse_id') || '', type: params.get('type') || '', from: params.get('from') || '', to: params.get('to') || '', page: params.get('page') || '' }
  const [dates, setDates] = useState({ from: filters.from, to: filters.to })
  const res = useFetch(`/seller/erp/movements?${toQuery(filters)}`)
  const places = useFetch('/seller/erp/warehouses')
  const set = (next) => setParams(toQuery({ ...filters, page: '', ...next }))
  const rows = res.data?.data || []

  return (
    <>
      <PageHeader title="Stock history" subtitle="Every change to available stock: orders, deliveries from suppliers, moves between locations, edits, counts and write-offs. Nothing here can be edited or deleted."
        actions={<CsvButton url={`/seller/erp/movements.csv?${toQuery({ ...filters, page: '' })}`} filename={`stock-history${filters.from ? `-${filters.from}` : ''}${filters.to ? `-to-${filters.to}` : ''}.csv`} />} />
      <form className="mb-4 flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); set(dates) }}>
        <label className="text-sm">What
          <select className="input mt-1 w-auto" value={filters.type} onChange={(e) => set({ type: e.target.value })}>
            <option value="">Everything</option>
            {Object.entries(MOVEMENT_LABELS).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
          </select>
        </label>
        {places.data?.data?.length > 1 && (
          <label className="text-sm">Location
            <select className="input mt-1 w-auto" value={filters.warehouse_id} onChange={(e) => set({ warehouse_id: e.target.value })}>
              <option value="">All locations</option>
              {places.data.data.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
            </select>
          </label>
        )}
        <label className="text-sm">From<input type="date" className="input mt-1 w-auto" value={dates.from} onChange={(e) => setDates({ ...dates, from: e.target.value })} /></label>
        <label className="text-sm">To<input type="date" className="input mt-1 w-auto" value={dates.to} onChange={(e) => setDates({ ...dates, to: e.target.value })} /></label>
        <button type="submit" className="btn-secondary">Show</button>
        {filters.product_id && <button type="button" className="btn-ghost" onClick={() => set({ product_id: '' })}>All items</button>}
      </form>
      {res.loading && !res.data ? <Spinner /> : res.error ? <ErrorState error={res.error} onRetry={res.reload} /> : (
        <section className="card p-4">
          <MovementTable movements={rows} withProduct />
        </section>
      )}
      <Pagination meta={res.data?.meta} onPage={(page) => setParams(toQuery({ ...filters, page }))} />
    </>
  )
}
