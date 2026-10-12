import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Alert, ErrorState, PageHeader, Pagination, Spinner } from '../../components/ui'
import { ENTRY_LABELS, METHOD_LABELS, formatDateTime, formatETB } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'


/**
 * Statement rows shared by the seller and admin views. Positive amounts are owed to the platform.
 */
export function LedgerTable({ entries, admin = false }) {
  if (!entries.length) return <p className="text-sm text-stone-500">Nothing yet. Entries are added when an order is marked delivered.</p>
  return (
    <div className="relative overflow-x-auto">
      <table className="w-full min-w-[560px] text-left text-sm">
        <thead className="text-xs text-stone-500 uppercase">
          <tr><th className="py-2 pr-3 font-semibold">Date</th><th className="py-2 pr-3 font-semibold">What</th><th className="py-2 pr-3 font-semibold">Details</th><th className="py-2 text-right font-semibold">Amount</th></tr>
        </thead>
        <tbody className="divide-y divide-stone-100">
          {entries.map((e) => (
            <tr key={e.id}>
              <td className="py-2 pr-3 whitespace-nowrap text-stone-600">{formatDateTime(e.created_at)}</td>
              <td className="py-2 pr-3">{ENTRY_LABELS[e.type]}</td>
              <td className="py-2 pr-3 text-stone-600">
                {e.order && <Link to={`${admin ? '/admin' : '/seller'}/orders/${e.order.id}`} className="text-brand-700 hover:underline">{e.order.number}</Link>}
                {e.method && `${METHOD_LABELS[e.method]}${e.reference ? ` · ${e.reference}` : ''}`}
                {e.note && !e.order && <span className="block text-xs">{e.note}</span>}
                {admin && e.recorded_by && <span className="block text-xs text-stone-400">by {e.recorded_by}</span>}
              </td>
              <td className={`py-2 text-right font-medium whitespace-nowrap ${e.amount_santim < 0 ? 'text-emerald-700' : 'text-stone-900'}`}>
                {e.amount_santim < 0 ? `- ${formatETB(-e.amount_santim)}` : formatETB(e.amount_santim)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export function BalanceCards({ b, admin = false }) {
  const online = b.online_sales_total_santim > 0 || b.payouts_total_santim > 0
  return (
    <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
      <div className={`card p-4 ${b.over_limit ? 'border-red-300 bg-red-50' : ''}`}>
        <p className="text-sm text-stone-600">{b.balance_santim < 0 ? (admin ? 'Marketplace owes the store' : 'The marketplace owes you') : 'Owed to the marketplace'}</p>
        <p className="text-2xl font-bold">{formatETB(Math.abs(b.balance_santim))}</p>
        {b.credit_limit_santim && <p className="text-xs text-stone-500">Limit {formatETB(b.credit_limit_santim)}</p>}
      </div>
      <div className="card p-4"><p className="text-sm text-stone-600">Commission charged</p><p className="text-2xl font-bold">{formatETB(b.commission_total_santim)}</p></div>
      <div className="card p-4"><p className="text-sm text-stone-600">Paid so far</p><p className="text-2xl font-bold">{formatETB(b.paid_total_santim)}</p></div>
      <div className="card p-4"><p className="text-sm text-stone-600">Adjustments</p><p className="text-2xl font-bold">{b.adjustments_total_santim < 0 ? '- ' : ''}{formatETB(Math.abs(b.adjustments_total_santim))}</p></div>
      {online && <div className="card p-4"><p className="text-sm text-stone-600">Online sales held</p><p className="text-2xl font-bold">{formatETB(b.online_sales_total_santim)}</p></div>}
      {online && <div className="card p-4"><p className="text-sm text-stone-600">{admin ? 'Paid out to the store' : 'Paid out to you'}</p><p className="text-2xl font-bold">{formatETB(b.payouts_total_santim)}</p></div>}
    </div>
  )
}

export default function SellerBalance() {
  const balance = useFetch('/seller/balance')
  const [page, setPage] = useState(1)
  const entries = useFetch(`/seller/balance/entries?page=${page}`)

  if (balance.loading && !balance.data) return <Spinner />
  if (balance.error) return <div className="container-page py-10"><ErrorState error={balance.error} /></div>
  const b = balance.data.data

  return (
    <div className="container-page py-8">
      <PageHeader title="Balance" subtitle="Cash orders: you collect the money and owe the marketplace its commission. Online orders: the marketplace holds the payment and owes you the sale minus commission once delivered." actions={<Link to="/seller/orders" className="btn-secondary">Orders</Link>} />
      {b.over_limit && <div className="mb-4"><Alert tone="error">Your unpaid commission is over the limit, so you cannot confirm new cash orders. Orders paid online still go ahead. Pay your balance and the marketplace team will record it.</Alert></div>}
      {b.owed_to_seller_santim > 0 && <div className="mb-4"><Alert tone="success">The marketplace owes you {formatETB(b.owed_to_seller_santim)} from online sales. The team sends it to you and records each payout here.</Alert></div>}
      <BalanceCards b={b} />
      <div className="card mt-4 p-4 text-sm">
        <h2 className="font-semibold text-stone-900">How to pay commission</h2>
        <p className="mt-1 whitespace-pre-line text-stone-700">{b.instructions || 'Contact the marketplace team to arrange payment.'}</p>
        <p className="mt-2 text-xs text-stone-500">Payments show here once the team records them. Keep your transfer reference.</p>
      </div>
      <section className="card mt-6 p-4">
        <h2 className="mb-2 font-semibold text-stone-900">Statement</h2>
        {entries.loading && !entries.data ? <Spinner /> : entries.error ? <ErrorState error={entries.error} /> : (
          <>
            <LedgerTable entries={entries.data.data} />
            <Pagination meta={entries.data.meta} onPage={setPage} />
          </>
        )}
      </section>
    </div>
  )
}
