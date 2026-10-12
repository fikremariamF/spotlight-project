import { useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import OrderView from '../../components/OrderView'
import { Alert, ErrorState, PageHeader, Spinner } from '../../components/ui'
import { api, parseError } from '../../lib/api'
import { ORDER_STATUS_LABELS, toQuery } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'
import { CancelForm, OrderList } from '../Orders'

export function AdminOrders() {
  const [params, setParams] = useSearchParams()
  const status = params.get('status') || ''
  const q = params.get('q') || ''
  const [term, setTerm] = useState(q)

  return (
    <>
      <PageHeader title="Orders" subtitle="Sellers run fulfilment. Admins can look up any order and cancel it with a reason." />
      <form className="mb-4 flex flex-wrap gap-2" onSubmit={(e) => { e.preventDefault(); setParams(toQuery({ status, q: term.trim() })) }}>
        <label htmlFor="order-status" className="sr-only">Status</label>
        <select id="order-status" className="input w-auto" value={status} onChange={(e) => setParams(toQuery({ status: e.target.value, q }))}>
          <option value="">All statuses</option>
          {Object.entries(ORDER_STATUS_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
        <label htmlFor="order-q" className="sr-only">Order number</label>
        <input id="order-q" className="input w-48" placeholder="ORD-000123" value={term} onChange={(e) => setTerm(e.target.value)} />
        <button type="submit" className="btn-secondary">Find</button>
      </form>
      <OrderList key={`${status}|${q}`} url={`/admin/orders?${toQuery({ status, q })}`} viewer="admin" base="/admin/orders" />
    </>
  )
}

export function AdminOrderDetail() {
  const { id } = useParams()
  const order = useFetch(`/admin/orders/${id}`)
  const [error, setError] = useState(null)

  if (order.loading && !order.data) return <Spinner />
  if (order.error) return <ErrorState error={order.error} />
  const o = order.data.data

  async function cancel(reason) {
    setError(null)
    try {
      const res = await api.post(`/admin/orders/${o.id}/cancel`, { reason })
      order.setData(res.data)
    } catch (err) {
      setError(parseError(err).message)
    }
  }

  return (
    <>
      <Link to="/admin/orders" className="text-sm text-brand-700 hover:underline">Back to orders</Link>
      <div className="mt-3">
        {error && <div className="mb-4"><Alert tone="error">{error}</Alert></div>}
        <OrderView order={o} viewer="admin" actions={!['delivered', 'cancelled'].includes(o.status) && (
          <CancelForm onCancel={cancel} label="Cancel as admin" hint={o.status === 'awaiting_payment'
            ? 'Not paid yet: this cancels every order in the same checkout and returns the stock.'
            : `Use for disputes, refused deliveries or a seller who cannot fulfil. Reserved stock is returned.${o.payment_status === 'paid' ? ' The customer paid online, so a refund is added to Payments for you to send.' : ''}`} />
        )} />
      </div>
    </>
  )
}
