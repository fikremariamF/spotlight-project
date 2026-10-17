import { Search } from 'lucide-react'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { EmptyState, ErrorState, PageHeader, Pagination, Spinner, Stats } from '../../../components/ui'
import { toQuery } from '../../../lib/format'
import { useFetch } from '../../../lib/useFetch'
import { Badge, CsvButton, DayBars, PeriodPicker, SALE_STATUS, money, usePeriod, useReadOnly } from './shared'

const SOURCES = [['', 'All sales'], ['marketplace', 'Marketplace'], ['offline', 'Shop sales']]
const STATUSES = [['', 'Any'], ['owing', 'Customer owes (any date)'], ['voided', 'Voided']]
const TABS = [['list', 'Sales'], ['items', 'By item'], ['days', 'By day']]

/** Every sale of the store: delivered marketplace orders and sales it made itself, with invoices. */
export default function ErpSales() {
  const readOnly = useReadOnly()
  const period = usePeriod()
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') || 'list'
  const filters = { source: params.get('source') || '', status: params.get('status') || '', q: params.get('q') || '', page: params.get('page') || '' }
  const [q, setQ] = useState(filters.q)
  const report = useFetch(`/seller/erp/sales/report?${period.query}`)
  const list = useFetch(tab === 'list' ? `/seller/erp/sales?${period.query}&${toQuery(filters)}` : null)
  const set = (next) => setParams(toQuery({ ...Object.fromEntries(params), page: '', ...next }), { replace: true })
  const r = report.data?.data
  const rows = list.data?.data || []
  const filtered = filters.source || filters.status || filters.q
  const inProgress = r ? Object.values(r.in_progress || {}).reduce((n, c) => n + c, 0) : 0
  const csvName = r ? `sales-${r.range.from}-to-${r.range.to}.csv` : 'sales.csv'

  return (
    <>
      <PageHeader title="Sales & invoices"
        subtitle="Marketplace orders count on the day they were delivered; your own shop sales on their sale date. Returns count on the day the goods came back."
        actions={<>
          {!readOnly && <Link to="/seller/erp/sales/new" className="btn-primary">New shop sale</Link>}
          <CsvButton url={`/seller/erp/sales.csv?${period.query}`} filename={csvName} />
        </>} />
      <div className="mb-4"><PeriodPicker value={period} /></div>

      {report.loading && !r ? <Spinner /> : report.error ? <ErrorState error={report.error} onRetry={report.reload} /> : (
        <>
          <Stats items={[
            { label: 'Net sales', value: money(r.totals.net_santim), hint: 'items and delivery, less returns' },
            { label: 'Sales', value: r.totals.sales, hint: `${r.marketplace.orders} marketplace, ${r.offline.sales} shop` },
            { label: 'Returns', value: money(r.totals.returns_santim), hint: r.returns.returns ? `${r.returns.returns} returns, ${r.returns.units} units` : 'none' },
            { label: 'Gross profit (estimate)', value: money(r.totals.gross_profit_santim), hint: r.totals.lines_without_cost ? `${r.totals.lines_without_cost} lines without a cost price left out` : 'sales minus their cost', tone: r.totals.lines_without_cost ? 'warn' : undefined },
            { label: 'Customers owe you', value: money(r.customers.owe_santim), hint: r.customers.overdue_sales ? `${r.customers.overdue_sales} past the pay-by date` : `${r.customers.owing_sales} shop sales, as of now`, tone: r.customers.overdue_sales ? 'bad' : undefined, to: '/seller/erp/sales?status=owing' },
            { label: 'Orders on their way', value: inProgress, hint: 'not delivered yet', to: '/seller/orders' },
          ]} />
          {r.customers.credit_santim > 0 && (
            <p className="mt-3 rounded-lg bg-amber-50 px-4 py-2 text-sm text-amber-900">You hold {money(r.customers.credit_santim)} for customers: returns you credited but did not pay back. Give it back with the next return, or keep it as their credit.</p>
          )}
        </>
      )}

      <div role="tablist" aria-label="Show" className="mt-5 mb-4 flex gap-1 border-b border-stone-200">
        {TABS.map(([key, label]) => (
          <button key={key} type="button" role="tab" aria-selected={tab === key} onClick={() => set({ tab: key === 'list' ? '' : key })}
            className={`-mb-px border-b-2 px-3 py-2 text-sm ${tab === key ? 'border-brand-700 font-semibold text-brand-800' : 'border-transparent text-stone-600 hover:text-stone-900'}`}>{label}</button>
        ))}
      </div>

      {tab === 'list' && (
        <>
          <div className="mb-4 space-y-3">
            <div className="flex flex-wrap gap-2">
              {SOURCES.map(([key, label]) => (
                <button key={key || 'all'} type="button" aria-pressed={filters.source === key} onClick={() => set({ source: key })}
                  className={`chip ${filters.source === key ? 'border-brand-600 bg-brand-50 font-semibold text-brand-800' : ''}`}>{label}</button>
              ))}
              <span className="mx-1 hidden w-px bg-stone-200 sm:block" aria-hidden="true" />
              {STATUSES.map(([key, label]) => (
                <button key={key || 'any'} type="button" aria-pressed={filters.status === key} onClick={() => set({ status: key })}
                  className={`chip ${filters.status === key ? 'border-brand-600 bg-brand-50 font-semibold text-brand-800' : ''}`}>{label}</button>
              ))}
            </div>
            <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); set({ q: q.trim() }) }}>
              <label htmlFor="sales-q" className="sr-only">Search by number or customer</label>
              <span className="relative block min-w-0 flex-1">
                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" aria-hidden="true" />
                <input id="sales-q" type="search" className="input pl-9" placeholder="Sale or order number, customer name or phone" value={q} onChange={(e) => setQ(e.target.value)} maxLength={100} />
              </span>
              <button type="submit" className="btn-secondary">Search</button>
              {filtered && <button type="button" className="btn-ghost" onClick={() => { setQ(''); set({ source: '', status: '', q: '' }) }}>Clear</button>}
            </form>
          </div>
          {list.loading && !list.data ? <Spinner /> : list.error ? <ErrorState error={list.error} onRetry={list.reload} /> : rows.length === 0 ? (
            <EmptyState title={filtered ? 'Nothing matches' : 'No sales in this period'}
              action={!filtered && !readOnly && <Link to="/seller/erp/sales/new" className="btn-primary">Record a shop sale</Link>}>
              {filtered ? 'Try another filter or a longer period.' : 'Delivered marketplace orders show here by themselves. Sales you make at your shop or by phone you record here, so your stock and figures stay complete.'}
            </EmptyState>
          ) : <div className="card"><SalesTable sales={rows} /></div>}
          <Pagination meta={list.data?.meta} onPage={(page) => set({ page })} />
        </>
      )}

      {tab === 'items' && r && <ByItem products={r.products} />}
      {tab === 'days' && r && (
        <section className="card p-4">
          <h2 className="mb-3 font-semibold text-stone-900">Item sales by day</h2>
          <DayBars days={r.days.map((d) => ({ date: d.date, value: d.items_santim }))} format={money} label="Item sales by day" />
          <div className="relative mt-4 overflow-x-auto">
            <table className="rtable rtable-flush w-full text-left text-sm">
              <thead className="text-xs text-stone-500 uppercase">
                <tr><th className="py-2 pr-3">Day</th><th className="py-2 pr-3 text-right">Marketplace</th><th className="py-2 pr-3 text-right">Shop</th><th className="py-2 text-right">Returns</th></tr>
              </thead>
              <tbody className="divide-y divide-stone-100">
                {r.days.filter((d) => d.items_santim || d.returns_santim).reverse().map((d) => (
                  <tr key={d.date}>
                    <td className="rtable-title py-2 pr-3 whitespace-nowrap">{d.date}</td>
                    <td data-label="Marketplace" className="py-2 pr-3 text-right tabular-nums">{d.orders ? `${money(d.marketplace_santim)} (${d.orders})` : ''}</td>
                    <td data-label="Shop" className="py-2 pr-3 text-right tabular-nums">{d.sales ? `${money(d.offline_santim)} (${d.sales})` : ''}</td>
                    <td data-label="Returns" className="py-2 text-right tabular-nums">{d.returns_santim ? `-${money(d.returns_santim)}` : ''}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </>
  )
}

export function SalesTable({ sales }) {
  return (
    <div className="relative overflow-x-auto">
      <table className="rtable w-full text-left text-sm">
        <thead className="text-xs text-stone-500 uppercase">
          <tr><th className="px-4 py-2">Sale</th><th className="px-3 py-2">Date</th><th className="px-3 py-2">Customer</th><th className="px-3 py-2">Items</th><th className="px-3 py-2 text-right">Total</th><th className="px-4 py-2 text-right">Customer owes</th></tr>
        </thead>
        <tbody className="divide-y divide-stone-100">
          {sales.map((s) => {
            const voided = s.status === 'voided'
            return (
              <tr key={`${s.kind}-${s.id}`} className={voided ? 'text-stone-400' : ''}>
                <td className="rtable-title px-4 py-2.5">
                  <Link to={`/seller/erp/sales/${s.kind === 'marketplace' ? 'orders' : 'offline'}/${s.id}`} className={`font-semibold text-brand-700 hover:underline ${voided ? 'line-through' : ''}`}>{s.number}</Link>{' '}
                  <span className={`ml-1 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${s.kind === 'marketplace' ? 'bg-sky-100 text-sky-800' : 'bg-violet-100 text-violet-800'}`}>{s.kind === 'marketplace' ? 'Marketplace' : 'Shop'}</span>
                  {voided && <> <Badge map={SALE_STATUS} value="voided" /></>}
                </td>
                <td data-label="Date" className="px-3 py-2.5 whitespace-nowrap">{s.date}</td>
                <td data-label="Customer" className="px-3 py-2.5">{s.customer?.name || <span className="text-stone-400">Not given</span>}</td>
                <td data-label="Items" className="px-3 py-2.5">{s.summary}</td>
                <td data-label="Total" className="px-3 py-2.5 text-right tabular-nums">
                  <span className={voided ? 'line-through' : ''}>{money(s.total_santim)}</span>
                  {s.returned_santim > 0 && <span className="block text-xs text-amber-800">-{money(s.returned_santim)} returned</span>}
                </td>
                <td data-label="Customer owes" className="px-4 py-2.5 text-right tabular-nums">
                  {s.balance_santim > 0 ? <strong className="text-red-700">{money(s.balance_santim)}</strong>
                    : s.balance_santim < 0 ? <span className="text-amber-800">you owe {money(-s.balance_santim)}</span>
                      : voided ? '' : <span className="text-emerald-700">Paid</span>}
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

function ByItem({ products }) {
  if (!products.length) return <EmptyState title="Nothing sold in this period">Sales and returns show here by item.</EmptyState>
  return (
    <div className="card relative overflow-x-auto">
      <table className="rtable w-full text-left text-sm">
        <thead className="text-xs text-stone-500 uppercase">
          <tr><th className="px-4 py-2">Item</th><th className="px-3 py-2 text-right">Sold</th><th className="px-3 py-2 text-right">Returned</th><th className="px-3 py-2 text-right">Net sales</th><th className="px-4 py-2 text-right">Profit (estimate)</th></tr>
        </thead>
        <tbody className="divide-y divide-stone-100">
          {products.map((p) => (
            <tr key={`${p.product_id}:${p.variant_id}`}>
              <td className="rtable-title px-4 py-2.5">
                {p.product_id ? <Link to={`/seller/erp/inventory/${p.product_id}`} className="hover:underline">{p.name}{p.label ? ` (${p.label})` : ''}</Link> : <span className="text-stone-600">Custom orders and other lines</span>}
              </td>
              <td data-label="Sold" className="px-3 py-2.5 text-right tabular-nums">{p.units}<span className="block text-xs text-stone-500">{[p.marketplace_units && `${p.marketplace_units} marketplace`, p.offline_units && `${p.offline_units} shop`].filter(Boolean).join(', ')}</span></td>
              <td data-label="Returned" className="px-3 py-2.5 text-right tabular-nums">{p.returned_units || ''}</td>
              <td data-label="Net sales" className="px-3 py-2.5 text-right font-semibold tabular-nums">{money(p.net_santim)}</td>
              <td data-label="Profit (estimate)" className="px-4 py-2.5 text-right tabular-nums">{p.costed && p.product_id ? money(p.net_santim - p.cost_santim) : <span className="text-xs text-stone-500">no cost price</span>}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
