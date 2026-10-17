import { Link, useOutletContext } from 'react-router-dom'
import { CountLine, ErrorState, Spinner, Stats, TodoList } from '../../components/ui'
import { ORDER_STATUS_LABELS, STATUS_LABELS, formatETB } from '../../lib/format'

const money = (s) => formatETB(s) || 'ETB 0'

export default function AdminOverview() {
  // The layout already loads /admin/summary for its menu counts; reuse it.
  const { data, error, reload } = useOutletContext()
  if (error && !data) return <ErrorState error={error} onRetry={reload} />
  if (!data) return <Spinner />
  const s = data.data

  return (
    <>
      <h1 className="mb-4 text-2xl font-bold tracking-tight text-stone-900">Overview</h1>

      <section aria-labelledby="todo" className="mb-6">
        <h2 id="todo" className="mb-2 text-sm font-semibold text-stone-500">To do</h2>
        <TodoList items={[
          [s.support?.escalated, 'escalated complaint', 'escalated complaints', '/admin/support?quick=escalated', 'bad'],
          [s.support?.needs_reply, 'customer inquiry waiting for a reply', 'customer inquiries waiting for a reply', '/admin/support?quick=needs_reply'],
          [s.support?.store_answers, 'store answer to review', 'store answers to review', '/admin/support?tab=requests&status=answered'],
          [s.sellers.pending, 'store application', 'store applications', '/admin/sellers?status=pending'],
          [s.products.pending_review, 'listing to review', 'listings to review', '/admin/listings'],
          [s.refunds_due, 'refund to send', 'refunds to send', '/admin/payments'],
          [s.reviews_low, 'low rating in the last 30 days', 'low ratings in the last 30 days', '/admin/reviews?filter=low'],
          [s.orders.pending_confirmation, 'order waiting for its store to confirm', 'orders waiting for their stores to confirm', '/admin/orders?status=pending_confirmation'],
        ]} />
      </section>

      <section aria-labelledby="money" className="mb-6">
        <h2 id="money" className="mb-2 text-sm font-semibold text-stone-500">Money <span className="font-normal">(delivered orders; commission is revenue, not profit)</span></h2>
        <Stats items={[
          { label: 'Gross sales', value: money(s.money.delivered_gross_santim) },
          { label: 'Commission earned', value: money(s.money.commission_earned_santim), hint: `at ${s.money.commission_rate_percent}% now`, to: '/admin/settings' },
          { label: 'Stores owe commission', value: money(s.money.commission_outstanding_santim), to: '/admin/settlements?owing=1', tone: s.money.commission_outstanding_santim > 0 ? 'warn' : undefined },
          { label: 'Open orders', value: money(s.money.open_orders_value_santim) },
        ]} />
      </section>

      <section aria-labelledby="glance">
        <h2 id="glance" className="mb-2 text-sm font-semibold text-stone-500">At a glance</h2>
        <div className="card divide-y divide-stone-100">
          <CountLine label="Orders" counts={s.orders} labels={ORDER_STATUS_LABELS} link={(k) => `/admin/orders?status=${k}`} />
          <CountLine label="Listings" counts={s.products} labels={STATUS_LABELS} link={(k) => `/admin/listings?status=${k}`} />
          <CountLine label="Stores" counts={s.sellers} labels={STATUS_LABELS} link={(k) => `/admin/sellers?status=${k}`} />
          <CountLine label="Customers">
            <Link to="/admin/users?role=customer" className="hover:text-brand-800 hover:underline"><span className="font-semibold text-stone-900">{s.customers}</span> accounts</Link>
          </CountLine>
        </div>
      </section>
    </>
  )
}
