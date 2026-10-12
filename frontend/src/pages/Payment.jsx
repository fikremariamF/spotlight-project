import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useLocation, useSearchParams } from 'react-router-dom'
import { CheckCircle2, Clock, ShieldCheck } from 'lucide-react'
import { OrderStatusBadge } from '../components/OrderView'
import { Alert, ErrorState, PageHeader, Spinner } from '../components/ui'
import { useCart } from '../context/CartContext'
import { useConfig } from '../context/ConfigContext'
import { api, parseError } from '../lib/api'
import { formatETB } from '../lib/format'

/**
 * Time left until `iso`, ticking every second. Null once it has passed.
 */
// eslint-disable-next-line react-refresh/only-export-components
export function useCountdown(iso) {
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    if (!iso) return undefined
    const t = setInterval(() => setNow(Date.now()), 1000)
    return () => clearInterval(t)
  }, [iso])
  if (!iso) return null
  const left = new Date(iso).getTime() - now
  if (!(left > 0)) return null
  const m = Math.floor(left / 60000)
  const s = Math.floor((left % 60000) / 1000)
  return `${m}:${String(s).padStart(2, '0')}`
}

/**
 * Send the customer to the payment page for a checkout. Returns an error message, or nothing when
 * the browser is leaving for the provider.
 */
// eslint-disable-next-line react-refresh/only-export-components
export async function startPayment(checkoutId) {
  try {
    const res = await api.post(`/checkouts/${checkoutId}/pay`)
    const url = res.data.data.payment?.checkout_url
    if (url) {
      window.location.assign(url)
      return null
    }
    return 'The payment page is not available. Try again in a minute.'
  } catch (err) {
    const e = parseError(err)
    return Object.values(e.fields)[0] || e.message
  }
}

/**
 * Pay now and cancel buttons for a checkout that is waiting for payment.
 */
export function PayActions({ checkoutId, dueAt, onChanged }) {
  const { refreshCart } = useCart()
  const [busy, setBusy] = useState(null)
  const [error, setError] = useState(null)
  const [confirmCancel, setConfirmCancel] = useState(false)
  const left = useCountdown(dueAt)

  async function pay() {
    setBusy('pay')
    setError(null)
    const message = await startPayment(checkoutId)
    if (message) {
      setError(message)
      setBusy(null)
      onChanged?.()
    }
  }

  async function cancel() {
    setBusy('cancel')
    setError(null)
    try {
      await api.post(`/checkouts/${checkoutId}/cancel`)
      refreshCart()
    } catch (err) {
      const e = parseError(err)
      setError(Object.values(e.fields)[0] || e.message)
    }
    setBusy(null)
    setConfirmCancel(false)
    onChanged?.()
  }

  return (
    <div className="space-y-3">
      {error && <Alert tone="error">{error}</Alert>}
      {left && (
        <p className="flex items-center gap-2 text-sm text-stone-700">
          <Clock className="size-4 text-orange-600" aria-hidden="true" />
          <span>Items held for you: <span className="font-semibold tabular-nums">{left}</span> left to pay</span>
        </p>
      )}
      <div className="flex flex-wrap gap-2">
        <button type="button" className="btn-primary" disabled={!!busy} onClick={pay}>{busy === 'pay' ? 'Opening payment...' : 'Pay now'}</button>
        {confirmCancel ? (
          <>
            <button type="button" className="btn-danger" disabled={!!busy} onClick={cancel}>{busy === 'cancel' ? 'Cancelling...' : 'Yes, cancel'}</button>
            <button type="button" className="btn-ghost" disabled={!!busy} onClick={() => setConfirmCancel(false)}>Keep order</button>
          </>
        ) : (
          <button type="button" className="btn-ghost text-red-700" disabled={!!busy} onClick={() => setConfirmCancel(true)}>Cancel order</button>
        )}
      </div>
      {confirmCancel && <p className="text-xs text-stone-500">The items go back to your cart so you can pay another way.</p>}
    </div>
  )
}

const POLL_MS = 3000
const POLL_TRIES = 20

/**
 * Where the payment provider sends the customer back. The page asks our API, which asks the
 * provider; what the provider's redirect itself says is never trusted.
 */
export function PaymentReturn() {
  const [params] = useSearchParams()
  const location = useLocation()
  const id = params.get('checkout')
  const [state, setState] = useState({ data: null, error: null })
  const [tries, setTries] = useState(0)
  const timer = useRef(null)

  const load = useCallback(() => {
    if (!/^\d+$/.test(id || '')) {
      setState({ data: null, error: { message: 'This payment link is not complete. Open the order from your orders page.' } })
      return
    }
    api.get(`/checkouts/${id}/payment`)
      .then((res) => setState({ data: res.data.data, error: null }))
      .catch((err) => setState((s) => ({ data: s.data, error: parseError(err) })))
      .finally(() => setTries((t) => t + 1))
  }, [id])

  useEffect(() => {
    load()
  }, [load])

  const d = state.data
  // Still waiting on an attempt the customer may have just finished: ask again for a while.
  const pending = d?.status === 'awaiting' && d.payment?.status === 'pending'
  useEffect(() => {
    if (!pending || tries >= POLL_TRIES) return undefined
    timer.current = setTimeout(load, POLL_MS)
    return () => clearTimeout(timer.current)
  }, [pending, tries, load])

  if (!d && !state.error) return <Spinner label="Checking your payment" />
  if (!d) return <div className="container-page py-10"><ErrorState error={state.error} onRetry={/^\d+$/.test(id || '') ? load : undefined} /></div>

  const orders = d.orders || []
  return (
    <div className="container-page max-w-2xl py-8">
      <PageHeader title={d.status === 'paid' ? 'Payment received' : 'Your payment'} />
      {location.state?.error && d.status === 'awaiting' && <div className="mb-4"><Alert tone="error">{location.state.error}</Alert></div>}

      <section className="card space-y-4 p-5">
        {d.status === 'paid' && (
          <div className="flex gap-3">
            <CheckCircle2 className="size-6 shrink-0 text-emerald-600" aria-hidden="true" />
            <div>
              <p className="font-semibold text-stone-900">Paid {formatETB(d.grand_total_santim)}. Thank you.</p>
              <p className="text-sm text-stone-600">{orders.length > 1 ? 'Each seller' : 'The seller'} will confirm the order before preparing it. If a seller cancels, you get that money back.</p>
            </div>
          </div>
        )}
        {d.status === 'awaiting' && (
          <>
            <div>
              <p className="font-semibold text-stone-900">{formatETB(d.grand_total_santim)} to pay</p>
              <p className="text-sm text-stone-600">
                {pending && tries < POLL_TRIES
                  ? 'Checking with the payment provider. If you just paid, this updates on its own.'
                  : d.payment?.status === 'failed'
                    ? 'The last payment did not go through. You can try again.'
                    : 'Not paid yet. If you paid and this does not change, wait a minute and check again.'}
              </p>
              {d.check_failed && <p className="mt-1 text-sm text-amber-800">We could not reach the payment provider just now. If you paid, it will show here once we can check.</p>}
            </div>
            <PayActions checkoutId={d.checkout_id} dueAt={d.payment_due_at} onChanged={load} />
            {pending && tries >= POLL_TRIES && <button type="button" className="btn-secondary" onClick={() => { setTries(0); load() }}>Check again</button>}
          </>
        )}
        {d.status === 'expired' && (
          <Alert tone="warning">The time to pay ran out. The order is being cancelled and the items go back to your cart. If you did pay, the money is refunded.</Alert>
        )}
        {d.status === 'cancelled' && (
          <Alert tone="info">This order was cancelled before it was paid. <Link to="/cart" className="font-semibold underline">Go to your cart</Link></Alert>
        )}

        <ul className="divide-y divide-stone-100 border-t border-stone-100">
          {orders.map((o) => (
            <li key={o.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 py-3 text-sm">
              <Link to={`/orders/${o.id}`} className="font-semibold text-brand-700 hover:underline">{o.number}</Link>
              <OrderStatusBadge order={o} />
              <span className="text-stone-600">{o.seller?.store_name}</span>
              <span className="ml-auto font-medium">{formatETB(o.total_santim)}</span>
            </li>
          ))}
        </ul>
      </section>
      <p className="mt-4 text-sm"><Link to="/orders" className="text-brand-700 hover:underline">All your orders</Link></p>
    </div>
  )
}

/**
 * Stand-in for the provider's page while trying the app locally. The API only serves it when the
 * test gateway is on, and the test gateway is never on in production.
 */
export function TestPay() {
  const [params] = useSearchParams()
  const config = useConfig()
  const txRef = params.get('tx_ref') || ''
  const [state, setState] = useState({ data: null, error: null })
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState(null)

  useEffect(() => {
    let current = true
    api.get(`/payments/test/${encodeURIComponent(txRef)}`)
      .then((res) => current && setState({ data: res.data.data, error: null }))
      .catch((err) => current && setState({ data: null, error: parseError(err) }))
    return () => {
      current = false
    }
  }, [txRef])

  async function choose(outcome) {
    setBusy(true)
    try {
      const res = await api.post(`/payments/test/${encodeURIComponent(txRef)}`, { outcome })
      setDone(res.data.data)
      window.location.assign(`/payments/return?checkout=${res.data.data.checkout_id}`)
    } catch (err) {
      setState((s) => ({ ...s, error: parseError(err) }))
      setBusy(false)
    }
  }

  if (!state.data && !state.error) return <Spinner />
  if (!state.data) return <div className="container-page py-10"><ErrorState error={state.error} /></div>
  const p = state.data

  return (
    <div className="container-page max-w-md py-10">
      <section className="card space-y-4 p-6">
        <div className="flex items-center gap-2 text-sm font-semibold text-amber-800"><ShieldCheck className="size-5" aria-hidden="true" /> Test payment page</div>
        <p className="text-sm text-stone-600">This page stands in for Chapa while {config.name} runs in test mode. No money moves.</p>
        <p className="text-3xl font-bold text-stone-900">{formatETB(p.amount_santim)}</p>
        <p className="text-xs break-all text-stone-500">Reference {p.tx_ref}</p>
        {state.error && <Alert tone="error">{state.error.message}</Alert>}
        {p.status !== 'pending' ? (
          <Alert tone="info">This payment is already {p.status}. <Link to={`/payments/return?checkout=${p.checkout_id}`} className="font-semibold underline">Back to the shop</Link></Alert>
        ) : (
          <div className="flex flex-wrap gap-2">
            <button type="button" className="btn-primary" disabled={busy} onClick={() => choose('paid')}>Pay {formatETB(p.amount_santim)}</button>
            <button type="button" className="btn-secondary" disabled={busy} onClick={() => choose('failed')}>Make it fail</button>
            <Link to={`/payments/return?checkout=${p.checkout_id}`} className="btn-ghost">Go back without paying</Link>
          </div>
        )}
        {done && <p className="text-sm text-stone-600">Going back to the shop...</p>}
      </section>
    </div>
  )
}
