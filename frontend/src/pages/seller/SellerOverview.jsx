import { Plus } from 'lucide-react'
import { Link } from 'react-router-dom'
import { Alert, CountLine, ErrorState, Spinner, Stats, TodoList } from '../../components/ui'
import { STATUS_LABELS, formatETB } from '../../lib/format'
import { SELLER_ORDER_LABELS, StoreStatus, useSellerSummary } from './shared'

const money = (s) => formatETB(s) || 'ETB 0'

export default function SellerOverview() {
  // The layout already loads /seller/summary for its menu counts; reuse it.
  const { data, error, reload } = useSellerSummary()
  if (error && !data) return <ErrorState error={error} onRetry={reload} />
  if (!data) return <Spinner />
  const s = data.data
  const { store, balance: b } = s
  const canWrite = ['pending', 'approved'].includes(store.status)
  const approved = store.status === 'approved'

  return (
    <>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold tracking-tight text-stone-900">{store.store_name}</h1>
        {canWrite && <Link to="/seller/products/new" className="btn-primary"><Plus className="size-4" aria-hidden="true" />Add listing</Link>}
      </div>
      <StoreStatus store={store} />
      {b.over_limit && (
        <div className="mb-4"><Alert tone="error">
          Your unpaid commission is over the limit, so new cash orders cannot be confirmed. <Link to="/seller/balance" className="font-semibold underline">See how to pay</Link>
        </Alert></div>
      )}

      <section aria-labelledby="todo" className="mb-6">
        <h2 id="todo" className="mb-2 text-sm font-semibold text-stone-500">To do</h2>
        <TodoList items={[
          [s.orders.pending_confirmation, 'order waiting for your confirmation', 'orders waiting for your confirmation', '/seller/orders?status=pending_confirmation'],
          [s.requests?.waiting, 'request from Betoch waiting for your answer', 'requests from Betoch waiting for your answer', '/seller/requests'],
          [s.products.rejected, 'listing needs changes', 'listings need changes', '/seller/listings?status=rejected'],
          approved && [s.products.draft, 'draft not sent for review yet', 'drafts not sent for review yet', '/seller/listings?status=draft'],
          [s.reviews.low_unanswered, 'low rating without your reply', 'low ratings without your reply', '/seller/reviews?unanswered=1'],
          approved && store.offers_delivery && !store.has_pin && [true, 'Set your store on the map so delivery is priced by distance', '', '/seller/profile'],
        ]} />
      </section>

      <section aria-labelledby="money" className="mb-6">
        <h2 id="money" className="mb-2 text-sm font-semibold text-stone-500">Your store</h2>
        <Stats items={[
          { label: 'Delivered sales', value: money(s.delivered_sales_santim), to: '/seller/orders?status=delivered' },
          b.balance_santim < 0
            ? { label: 'The marketplace owes you', value: money(-b.balance_santim), to: '/seller/balance' }
            : { label: 'Commission you owe', value: money(b.balance_santim), to: '/seller/balance', tone: b.over_limit ? 'bad' : b.balance_santim > 0 ? 'warn' : undefined },
          { label: 'Rating', value: s.reviews.count ? `${s.reviews.average} / 5` : 'None yet', hint: s.reviews.count ? `${s.reviews.count} ${s.reviews.count === 1 ? 'review' : 'reviews'}` : undefined, to: '/seller/reviews' },
          { label: 'Live listings', value: s.products.published || 0, to: '/seller/listings?status=published' },
        ]} />
      </section>

      <section aria-labelledby="glance">
        <h2 id="glance" className="mb-2 text-sm font-semibold text-stone-500">At a glance</h2>
        <div className="card divide-y divide-stone-100">
          <CountLine label="Orders" counts={s.orders} labels={SELLER_ORDER_LABELS} link={(k) => `/seller/orders?status=${k}`} />
          <CountLine label="Listings" counts={s.products} labels={STATUS_LABELS} link={(k) => `/seller/listings?status=${k}`} />
        </div>
      </section>
    </>
  )
}
