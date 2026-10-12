import { useState } from 'react'
import { Link, useLocation, useParams } from 'react-router-dom'
import OrderView, { OrderStatusBadge } from '../components/OrderView'
import { Alert, EmptyState, ErrorState, PageHeader, Pagination, Spinner } from '../components/ui'
import { api, parseError } from '../lib/api'
import { formatDateTime, formatETB } from '../lib/format'
import { PayActions } from './Payment'
import { ItemReview } from '../components/Reviews'
import { useFetch } from '../lib/useFetch'

export function OrderList({ url, viewer, base }) {
  const [page, setPage] = useState(1)
  const list = useFetch(`${url}${url.includes('?') ? '&' : '?'}page=${page}`)

  if (list.loading && !list.data) return <Spinner />
  if (list.error) return <ErrorState error={list.error} onRetry={list.reload} />
  if (!list.data.data.length) {
    return (
      <EmptyState title="No orders here" action={viewer === 'customer' && <Link to="/search" className="btn-primary">Browse furniture</Link>}>
        {viewer === 'seller' ? 'New orders from customers show up here.' : viewer === 'customer' ? 'Orders you place appear here.' : null}
      </EmptyState>
    )
  }

  return (
    <>
      <ul className="card divide-y divide-stone-100">
        {list.data.data.map((o) => (
          <li key={o.id}>
            <Link to={`${base}/${o.id}`} className="flex flex-wrap items-center gap-x-4 gap-y-1 p-4 hover:bg-stone-50">
              <span className="font-semibold text-stone-900">{o.number}</span>
              <OrderStatusBadge order={o} />
              <span className="text-sm text-stone-600">
                {viewer === 'seller' ? o.customer?.name : o.seller?.store_name}
                {viewer === 'admin' && o.customer && ` · ${o.customer.name}`}
              </span>
              <span className="text-sm text-stone-500">{formatDateTime(o.created_at)}</span>
              <span className="ml-auto font-semibold">{formatETB(o.total_santim)}{o.delivery_fee_confirmed ? '' : ' + delivery'}</span>
            </Link>
          </li>
        ))}
      </ul>
      <Pagination meta={list.data.meta} onPage={setPage} />
    </>
  )
}

export function Orders() {
  const location = useLocation()
  const placed = location.state?.placed

  return (
    <div className="container-page max-w-4xl py-8">
      <PageHeader title="Your orders" />
      {placed && (
        <div className="mb-4">
          <Alert tone="success">
            Order placed: {placed.orders.map((o) => o.number).join(', ')}. Each seller will confirm their part before preparing it. Pay cash when it arrives.
          </Alert>
        </div>
      )}
      <OrderList url="/orders" viewer="customer" base="/orders" />
    </div>
  )
}

export function CancelForm({ onCancel, label = 'Cancel order', hint }) {
  const [open, setOpen] = useState(false)
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)

  if (!open) return <button type="button" className="btn-ghost text-red-700" onClick={() => setOpen(true)}>{label}</button>
  return (
    <form className="space-y-2" onSubmit={async (e) => {
      e.preventDefault()
      setBusy(true)
      await onCancel(reason)
      setBusy(false)
    }}>
      <label htmlFor="cancel-reason" className="label">Reason</label>
      <textarea id="cancel-reason" className="input min-h-16" maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
      {hint && <p className="text-xs text-stone-500">{hint}</p>}
      <div className="flex gap-2">
        <button type="submit" className="btn-danger" disabled={busy || reason.trim().length < 3}>{label}</button>
        <button type="button" className="btn-ghost" onClick={() => setOpen(false)}>Keep order</button>
      </div>
    </form>
  )
}

export function OrderDetail() {
  const { id } = useParams()
  const order = useFetch(`/orders/${id}`)
  const [error, setError] = useState(null)

  if (order.loading && !order.data) return <Spinner />
  if (order.error) return <div className="container-page py-10"><ErrorState error={order.error} /></div>
  const o = order.data.data
  const canCancel = ['pending_confirmation', 'confirmed'].includes(o.status)

  async function cancel(reason) {
    setError(null)
    try {
      const res = await api.post(`/orders/${o.id}/cancel`, { reason })
      order.setData(res.data)
    } catch (err) {
      setError(parseError(err).message)
      order.reload()
    }
  }

  return (
    <div className="container-page max-w-5xl py-8">
      <Link to="/orders" className="text-sm text-brand-700 hover:underline">Back to orders</Link>
      <div className="mt-3">
        {error && <div className="mb-4"><Alert tone="error">{error}</Alert></div>}
        <OrderView order={o} viewer="customer" actions={o.status === 'awaiting_payment' ? (
          <div className="rounded-lg border border-orange-200 bg-orange-50 p-3">
            <p className="mb-2 text-sm text-stone-800">Pay {formatETB(o.total_santim)} online to send this order to the seller. If you placed orders with several sellers together, one payment covers all of them.</p>
            <PayActions checkoutId={o.checkout_id} dueAt={o.payment_due_at} onChanged={order.reload} />
          </div>
        ) : canCancel && (
          <CancelForm onCancel={cancel} hint={o.payment_status === 'paid' ? 'You can cancel until the seller starts preparing your order. The full amount you paid is refunded.' : 'You can cancel until the seller starts preparing your order.'} />
        )} itemExtra={o.status === 'delivered' ? (item) => <ItemReview key={`${item.id}-${item.review?.id}`} order={o} item={item} onSaved={order.reload} /> : undefined} />
      </div>
    </div>
  )
}
