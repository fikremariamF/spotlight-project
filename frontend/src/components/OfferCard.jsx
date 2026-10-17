import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useCart } from '../context/CartContext'
import { usePaymentWording } from '../context/ConfigContext'
import { api, parseError } from '../lib/api'
import { QUOTE_STATUS_LABELS, formatETB } from '../lib/format'

const shortDate = (iso) => new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })

/**
 * A priced offer as the customer sees it: from Betoch Support, or a quote a store sent in an old chat.
 * Accepting it lets the customer order at that price through the normal cart and checkout.
 * `onChanged` reloads the page around it after any answer, so its status is always the server's.
 */
export default function OfferCard({ quote, onChanged, noun = 'offer' }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const pending = quote.status === 'pending'

  async function act(action) {
    if (action === 'accept' && !window.confirm(`Accept "${quote.title}" for ${formatETB(quote.total_santim)}?`)) return
    setBusy(true)
    setError(null)
    try {
      await api.post(`/quotes/${quote.id}/${action}`)
    } catch (err) {
      const e = parseError(err)
      setError(Object.values(e.fields)[0] || e.message)
    } finally {
      setBusy(false)
      onChanged()
    }
  }

  const tone = { accepted: 'border-emerald-300 bg-emerald-50', pending: 'border-brand-300 bg-white' }[quote.status] || 'border-stone-200 bg-stone-50'
  const label = noun === 'offer' ? 'Offer' : 'Quote'

  return (
    <div className={`rounded-xl border-2 p-4 text-sm ${tone}`}>
      <div className="flex items-start justify-between gap-3">
        <p className="font-semibold text-stone-900">{label}: {quote.title}</p>
        <span className="shrink-0 text-xs font-semibold text-stone-600">{QUOTE_STATUS_LABELS[quote.status]}</span>
      </div>
      {quote.store && <p className="text-xs text-stone-500">Made and delivered by {quote.store.store_name}</p>}
      <p className="mt-1 whitespace-pre-line text-stone-700">{quote.description}</p>
      <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-4">
        <div><dt className="text-xs text-stone-500">Price</dt><dd className="font-medium">{formatETB(quote.price_santim)}{quote.quantity > 1 && ` x ${quote.quantity}`}</dd></div>
        <div><dt className="text-xs text-stone-500">Delivery</dt><dd className="font-medium">{quote.delivery_fee_santim === null ? 'Priced at checkout' : quote.delivery_fee_santim === 0 ? 'Free' : formatETB(quote.delivery_fee_santim)}</dd></div>
        <div><dt className="text-xs text-stone-500">Total</dt><dd className="font-bold text-stone-900">{formatETB(quote.total_santim)}</dd></div>
        <div><dt className="text-xs text-stone-500">Valid until</dt><dd className="font-medium">{shortDate(`${quote.valid_until}T00:00:00`)}</dd></div>
        {quote.production_days !== null && <div className="col-span-2"><dt className="text-xs text-stone-500">Ready in</dt><dd className="font-medium">{quote.production_days} days after the store confirms your order</dd></div>}
      </dl>
      {error && <p role="alert" className="mt-2 font-medium text-red-700">{error}</p>}
      {pending && (
        <div className="mt-3 flex flex-wrap gap-2">
          <button type="button" className="btn-primary" disabled={busy} onClick={() => act('accept')}>Accept {noun}</button>
          <button type="button" className="btn-secondary" disabled={busy} onClick={() => act('decline')}>Decline</button>
        </div>
      )}
      {quote.status === 'accepted' && <OrderOffer quote={quote} />}
    </div>
  )
}

function OrderOffer({ quote }) {
  const pay = usePaymentWording()
  const { refreshCart } = useCart()
  const navigate = useNavigate()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const expired = quote.order_by && new Date(quote.order_by) < new Date()

  async function add() {
    setBusy(true)
    setError(null)
    try {
      await api.post(`/quotes/${quote.id}/add-to-cart`)
      refreshCart()
      navigate('/cart')
    } catch (err) {
      const e = parseError(err)
      setError(Object.values(e.fields)[0] || e.message)
      setBusy(false)
    }
  }

  if (quote.ordered) return <p className="mt-2 text-emerald-800">Ordered. Track it under <Link to="/orders" className="font-medium underline">My orders</Link>.</p>
  if (expired) return <p className="mt-2 text-stone-600">This can no longer be ordered. Ask Betoch Support for a new offer.</p>

  return (
    <div className="mt-3">
      {error && <p role="alert" className="mb-2 font-medium text-red-700">{error}</p>}
      <div className="flex flex-wrap items-center gap-3">
        <button type="button" className="btn-primary" disabled={busy} onClick={add}>{busy ? 'Adding...' : 'Add to cart'}</button>
        <span className="text-xs text-stone-600">Order by {new Date(quote.order_by).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}. {pay.short}</span>
      </div>
    </div>
  )
}
