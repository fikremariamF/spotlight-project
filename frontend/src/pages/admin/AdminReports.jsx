import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Alert, ErrorState, PageHeader, Spinner } from '../../components/ui'
import { api, parseError } from '../../lib/api'
import { formatETB, toQuery } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'

function Stat({ label, value, hint }) {
  return (
    <div className="card p-4">
      <p className="text-sm text-stone-600">{label}</p>
      <p className="text-xl font-bold text-stone-900">{value}</p>
      {hint && <p className="text-xs text-stone-500">{hint}</p>}
    </div>
  )
}

const money = (s) => formatETB(s) || 'ETB 0'

export default function AdminReports() {
  const [params, setParams] = useSearchParams()
  const from = params.get('from') || ''
  const to = params.get('to') || ''
  const [form, setForm] = useState({ from, to })
  const res = useFetch(`/admin/reports/summary?${toQuery({ from, to })}`)
  const [downloadError, setDownloadError] = useState(null)

  async function download() {
    setDownloadError(null)
    try {
      const r = await api.get(`/admin/reports/orders.csv?${toQuery({ from, to })}`, { responseType: 'blob' })
      const url = URL.createObjectURL(r.data)
      const a = document.createElement('a')
      a.href = url
      a.download = `orders-${res.data.data.from}-to-${res.data.data.to}.csv`
      a.click()
      URL.revokeObjectURL(url)
    } catch (err) {
      setDownloadError(parseError(err).message)
    }
  }

  const d = res.data?.data
  const maxDay = d ? Math.max(1, ...d.days.map((x) => x.gross_santim)) : 1
  const activeDays = d ? d.days.filter((x) => x.orders > 0).reverse() : []

  return (
    <>
      <PageHeader title="Reports" subtitle="Dates are in Addis Ababa time. Gross sales are what customers paid sellers; commission is the marketplace's revenue. Neither is profit." />
      <form className="mb-4 flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); setParams(toQuery(form)) }}>
        <label className="text-sm">From<input type="date" className="input mt-1 w-auto" value={form.from} onChange={(e) => setForm({ ...form, from: e.target.value })} /></label>
        <label className="text-sm">To<input type="date" className="input mt-1 w-auto" value={form.to} onChange={(e) => setForm({ ...form, to: e.target.value })} /></label>
        <button type="submit" className="btn-secondary">Show</button>
        {d && <button type="button" className="btn-ghost" onClick={download}>Download orders (CSV)</button>}
      </form>
      {downloadError && <div className="mb-4"><Alert tone="error">{downloadError}</Alert></div>}
      {res.loading && !res.data ? <Spinner /> : res.error ? <ErrorState error={res.error} /> : (
        <>
          <p className="mb-3 text-sm text-stone-600">{d.from} to {d.to}</p>
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <Stat label="Gross sales (delivered)" value={money(d.delivered.gross_santim)} hint={`${d.delivered.orders} orders`} />
            <Stat label="Commission earned" value={money(d.delivered.commission_santim)} hint={`on ${money(d.delivered.items_santim)} of items`} />
            <Stat label="Commission collected" value={money(d.commission_collected_santim)} hint="payments recorded from sellers" />
            <Stat label="Orders placed" value={d.placed.orders} hint={money(d.placed.value_santim)} />
            <Stat label="Delivery fees (to sellers)" value={money(d.delivered.delivery_fees_santim)} />
            <Stat label="Cancelled" value={d.cancelled.orders} hint={`customer ${d.cancelled.by_customer} · seller ${d.cancelled.by_seller} · admin ${d.cancelled.by_admin}`} />
          </div>
          {d.online && (
            <>
              <h2 className="mt-6 mb-3 font-semibold text-stone-900">Online payments</h2>
              <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                <Stat label="Received online" value={money(d.online.paid_santim)} hint="customer payments in this period" />
                <Stat label="Paid out to stores" value={money(d.online.paid_out_santim)} />
                <Stat label="Refunded" value={money(d.online.refunded_santim)} />
                <Stat label="Refunds to send now" value={money(d.online.refunds_due_santim)} hint="all open refunds, any date" />
                <Stat label="Unpaid, cancelled" value={d.online.unpaid_cancelled_orders} hint="online orders never paid; not counted above" />
              </div>
            </>
          )}

          <section className="card mt-6 p-4">
            <h2 className="mb-3 font-semibold text-stone-900">Top stores by delivered sales</h2>
            {d.top_sellers.length === 0 ? <p className="text-sm text-stone-500">No delivered orders in this period.</p> : (
              <div className="relative overflow-x-auto">
                <table className="w-full min-w-[420px] text-left text-sm">
                  <thead className="text-xs text-stone-500 uppercase"><tr><th className="py-2 pr-3">Store</th><th className="py-2 pr-3 text-right">Orders</th><th className="py-2 pr-3 text-right">Gross</th><th className="py-2 text-right">Commission</th></tr></thead>
                  <tbody className="divide-y divide-stone-100">
                    {d.top_sellers.map((s) => <tr key={s.slug}><td className="py-2 pr-3">{s.store_name}</td><td className="py-2 pr-3 text-right">{s.orders}</td><td className="py-2 pr-3 text-right">{money(s.gross_santim)}</td><td className="py-2 text-right">{money(s.commission_santim)}</td></tr>)}
                  </tbody>
                </table>
              </div>
            )}
          </section>

          <section className="card mt-6 p-4">
            <h2 className="mb-1 font-semibold text-stone-900">Delivered sales by day</h2>
            <p className="mb-3 text-xs text-stone-500">Days with no deliveries are left out.</p>
            {activeDays.length === 0 ? <p className="text-sm text-stone-500">No deliveries in this period.</p> : (
            <div className="relative overflow-x-auto">
              <table className="w-full min-w-[420px] text-left text-sm">
                <thead className="text-xs text-stone-500 uppercase"><tr><th className="py-2 pr-3">Day</th><th className="py-2 pr-3 text-right">Orders</th><th className="py-2 pr-3">Gross</th><th className="py-2 text-right">Commission</th></tr></thead>
                <tbody className="divide-y divide-stone-100">
                  {activeDays.map((x) => (
                    <tr key={x.date}>
                      <td className="py-1.5 pr-3 whitespace-nowrap">{x.date}</td>
                      <td className="py-1.5 pr-3 text-right">{x.orders}</td>
                      <td className="py-1.5 pr-3">
                        <span className="flex items-center gap-2">
                          <span className="h-2 rounded bg-brand-600" style={{ width: `${Math.round((80 * x.gross_santim) / maxDay)}px` }} aria-hidden="true" />
                          {x.gross_santim ? money(x.gross_santim) : <span className="text-stone-400">0</span>}
                        </span>
                      </td>
                      <td className="py-1.5 text-right">{x.commission_santim ? money(x.commission_santim) : <span className="text-stone-400">0</span>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            )}
          </section>
        </>
      )}
    </>
  )
}
