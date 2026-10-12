import { useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import OrderView from '../../components/OrderView'
import { Alert, ErrorState, Field, PageHeader, Spinner } from '../../components/ui'
import { api, parseError } from '../../lib/api'
import { ORDER_STATUS_LABELS, formatETB, orderActionLabel, toQuery } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'
import { CancelForm, OrderList } from '../Orders'

const TABS = ['', 'pending_confirmation', 'confirmed', 'processing', 'ready', 'dispatched', 'delivered', 'cancelled']

export function SellerOrders() {
  const [params, setParams] = useSearchParams()
  const status = params.get('status') || ''
  const summary = useFetch('/seller/orders/summary')
  const counts = summary.data?.data.counts || {}

  return (
    <div className="container-page py-8">
      <PageHeader title="Orders" subtitle="Confirm new orders quickly: customers wait for your confirmation before anything happens."
        actions={<><Link to="/seller" className="btn-secondary">Listings</Link><Link to="/seller/balance" className="btn-secondary">Balance</Link></>} />
      {summary.data?.data.balance.over_limit && (
        <div className="mb-4"><Alert tone="error">Your unpaid commission is over the limit, so you cannot confirm new cash orders. <Link to="/seller/balance" className="font-semibold underline">See how to pay</Link></Alert></div>
      )}
      {summary.data && (
        <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3">
          <div className="card p-4"><p className="text-sm text-stone-600">Waiting for you</p><p className="text-2xl font-bold">{counts.pending_confirmation || 0}</p></div>
          <div className="card p-4"><p className="text-sm text-stone-600">Delivered sales</p><p className="text-2xl font-bold">{formatETB(summary.data.data.delivered_sales_santim)}</p></div>
          <Link to="/seller/balance" className="card p-4 hover:border-brand-300"><p className="text-sm text-stone-600">Commission you owe</p><p className="text-2xl font-bold">{formatETB(Math.max(0, summary.data.data.balance.balance_santim)) || 'ETB 0'}</p></Link>
        </div>
      )}
      <div className="mb-4 flex gap-1 relative overflow-x-auto border-b border-stone-200" role="tablist">
        {TABS.map((t) => (
          <button key={t} type="button" role="tab" aria-selected={status === t} onClick={() => setParams(t ? { status: t } : {})}
            className={`shrink-0 border-b-2 px-3 py-2 text-sm font-medium ${status === t ? 'border-brand-700 text-brand-800' : 'border-transparent text-stone-600 hover:text-stone-900'}`}>
            {t ? ORDER_STATUS_LABELS[t] : 'All'}{t && counts[t] ? ` (${counts[t]})` : ''}
          </button>
        ))}
      </div>
      <OrderList key={status} url={`/seller/orders?${toQuery({ status })}`} viewer="seller" base="/seller/orders" />
    </div>
  )
}

function nextSteps(order) {
  const flow = order.fulfillment === 'pickup' ? ['processing', 'ready', 'delivered'] : ['processing', 'ready', 'dispatched', 'delivered']
  const order_ = ['confirmed', ...flow]
  const at = order_.indexOf(order.status)
  return at === -1 ? [] : flow.filter((s) => order_.indexOf(s) > at)
}

function ConfirmForm({ order, onDone }) {
  const needsFee = order.fulfillment === 'delivery' && order.delivery_fee_santim === null
  const [fee, setFee] = useState('')
  const [note, setNote] = useState('')
  const [errors, setErrors] = useState({})
  const [busy, setBusy] = useState(false)

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    try {
      const res = await api.post(`/seller/orders/${order.id}/confirm`, { delivery_fee: needsFee ? fee : null, note: note || null })
      onDone(res.data)
    } catch (err) {
      const p = parseError(err)
      // Errors about the fee belong under the fee box; anything else (store over its limit, order changed) goes on top.
      const { delivery_fee: fee, ...rest } = p.fields
      setErrors({ delivery_fee: fee, _: Object.values(rest)[0] || (fee ? null : p.message) })
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} className="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-3" noValidate>
      <p className="text-sm font-medium text-amber-900">Check stock and timing, then confirm. The customer is told right away.</p>
      {errors._ && <Alert tone="error">{errors._}</Alert>}
      {needsFee && (
        <Field label="Delivery fee (ETB)" name="confirm-fee" error={errors.delivery_fee} hint="No fee was set for this address. Enter 0 for free delivery.">
          <input id="confirm-fee" className="input" inputMode="decimal" value={fee} onChange={(e) => setFee(e.target.value)} />
        </Field>
      )}
      <Field label="Note to the customer (optional)" name="confirm-note" hint="For example the delivery day.">
        <input id="confirm-note" className="input" maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} />
      </Field>
      <button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Confirming...' : 'Confirm order'}</button>
    </form>
  )
}

export function SellerOrderDetail() {
  const { id } = useParams()
  const order = useFetch(`/seller/orders/${id}`)
  const [error, setError] = useState(null)

  if (order.loading && !order.data) return <Spinner />
  if (order.error) return <div className="container-page py-10"><ErrorState error={order.error} /></div>
  const o = order.data.data

  async function call(request) {
    setError(null)
    try {
      const res = await request
      order.setData(res.data)
    } catch (err) {
      const p = parseError(err)
      setError(Object.values(p.fields)[0] || p.message)
      order.reload()
    }
  }

  const steps = nextSteps(o)
  const canCancel = ['pending_confirmation', 'confirmed', 'processing', 'ready'].includes(o.status)

  const actions = (
    <div className="space-y-3">
      {error && <Alert tone="error">{error}</Alert>}
      {o.status === 'pending_confirmation' && <ConfirmForm order={o} onDone={order.setData} />}
      {steps.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {steps.map((s, i) => (
            <button key={s} type="button" className={i === 0 ? 'btn-primary' : 'btn-secondary'}
              onClick={() => call(api.post(`/seller/orders/${o.id}/status`, { status: s }))}>
              {orderActionLabel(s, o.fulfillment, o.payment_method)}
            </button>
          ))}
        </div>
      )}
      {steps.includes('delivered') && (
        <p className="text-xs text-stone-500">
          {o.payment_method === 'online'
            ? `Paid online: do not collect cash. Use "${orderActionLabel('delivered', o.fulfillment, 'online')}" once the customer has the furniture; the marketplace then owes you this sale minus commission.`
            : `Use "${orderActionLabel('delivered', o.fulfillment)}" only after the customer has the furniture and you have the cash.`} Later steps can be skipped.
        </p>
      )}
      {canCancel && <CancelForm onCancel={(reason) => call(api.post(`/seller/orders/${o.id}/cancel`, { reason }))} hint={o.payment_method === 'online' ? 'Reserved stock goes back to your listing. The customer paid online and gets a full refund. The customer sees your reason.' : 'Reserved stock goes back to your listing. The customer sees your reason.'} />}
    </div>
  )

  return (
    <div className="container-page max-w-5xl py-8">
      <Link to="/seller/orders" className="text-sm text-brand-700 hover:underline">Back to orders</Link>
      <div className="mt-3"><OrderView order={o} viewer="seller" actions={actions} /></div>
    </div>
  )
}
