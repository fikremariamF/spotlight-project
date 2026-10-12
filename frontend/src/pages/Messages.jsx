import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ProductImage } from '../components/ProductCard'
import { Alert, EmptyState, ErrorState, Field, PageHeader, Pagination, Spinner } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { useCart } from '../context/CartContext'
import { usePaymentWording } from '../context/ConfigContext'
import { useInbox } from '../context/InboxContext'
import { api, parseError } from '../lib/api'
import { INQUIRY_TYPES, QUOTE_STATUS_LABELS, formatETB, timeAgo, toQuery } from '../lib/format'
import { useFetch } from '../lib/useFetch'

const FILTERS = [
  ['', 'All'],
  ['unread', 'Unread'],
  ['open', 'Open'],
  ['closed', 'Closed'],
]

const REFRESH_MS = 20000

function counterpart(c) {
  return c.viewer_side === 'seller' ? c.customer?.name : c.seller?.store_name
}

export function Inbox() {
  const { user } = useAuth()
  const [params, setParams] = useSearchParams()
  const filter = params.get('filter') || ''
  const query = toQuery({
    unread: filter === 'unread',
    status: filter === 'open' || filter === 'closed' ? filter : '',
    page: params.get('page') || '',
  })
  const list = useFetch(`/conversations?${query}`)
  const isSeller = user?.role === 'seller'

  return (
    <div className="container-page py-8">
      <PageHeader title="Messages"
        subtitle={isSeller ? 'Questions, price offers and custom requests from customers.' : 'Your conversations with sellers.'} />
      <div className="mb-4 flex gap-1 relative overflow-x-auto" role="tablist" aria-label="Filter conversations">
        {FILTERS.map(([key, label]) => (
          <button key={key} type="button" role="tab" aria-selected={filter === key}
            className={`shrink-0 rounded-full px-3 py-1.5 text-sm font-medium ${filter === key ? 'bg-brand-700 text-white' : 'bg-stone-100 text-stone-700 hover:bg-stone-200'}`}
            onClick={() => setParams(key ? { filter: key } : {})}>
            {label}
          </button>
        ))}
      </div>

      {list.loading && <Spinner />}
      {list.error && <ErrorState error={list.error} onRetry={list.reload} />}
      {list.data && (list.data.data.length ? (
        <>
          <ul className="card divide-y divide-stone-100">
            {list.data.data.map((c) => (
              <li key={c.id}>
                <Link to={`/messages/${c.id}`} className="flex gap-3 p-4 hover:bg-stone-50">
                  <ProductImage src={c.product?.image} alt="" className="size-14 shrink-0 rounded-lg" />
                  <div className="min-w-0 flex-1">
                    <div className="flex items-baseline justify-between gap-2">
                      <p className={`truncate ${c.unread ? 'font-bold text-stone-900' : 'font-medium text-stone-800'}`}>
                        {counterpart(c)}
                        {c.unread && <span className="ml-2 inline-block size-2 rounded-full bg-brand-600 align-middle" aria-label="unread" />}
                      </p>
                      <span className="shrink-0 text-xs text-stone-500">{timeAgo(c.last_message_at)}</span>
                    </div>
                    <p className="truncate text-sm text-stone-700">{c.subject}</p>
                    <p className="truncate text-sm text-stone-500">
                      {c.last_message?.is_mine && 'You: '}{c.last_message?.body}
                    </p>
                    <div className="mt-1 flex gap-2 text-xs">
                      <span className="rounded bg-stone-100 px-1.5 py-0.5 text-stone-600">{INQUIRY_TYPES[c.type]}</span>
                      {c.status === 'closed' && <span className="rounded bg-stone-200 px-1.5 py-0.5 text-stone-600">Closed</span>}
                    </div>
                  </div>
                </Link>
              </li>
            ))}
          </ul>
          <Pagination meta={list.data.meta} onPage={(p) => setParams({ ...(filter ? { filter } : {}), page: String(p) })} />
        </>
      ) : (
        <EmptyState title={filter ? 'Nothing here' : 'No messages yet'}
          action={!isSeller && !filter && <Link to="/search" className="btn-primary">Browse furniture</Link>}>
          {isSeller ? 'When customers message your store, their questions appear here.' : 'Use "Message seller" on any listing to ask a question or request a custom piece.'}
        </EmptyState>
      ))}
    </div>
  )
}

function QuoteCard({ quote, side, onChanged }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const pending = quote.status === 'pending'

  async function act(action) {
    if (action === 'accept' && !window.confirm(`Accept "${quote.title}" for ${formatETB(quote.total_santim)}?`)) return
    setBusy(true)
    setError(null)
    try {
      const url = side === 'seller' ? `/seller/quotes/${quote.id}/${action}` : `/quotes/${quote.id}/${action}`
      await api.post(url)
      onChanged()
    } catch (err) {
      const e = parseError(err)
      setError(Object.values(e.fields)[0] || e.message)
      onChanged()
    } finally {
      setBusy(false)
    }
  }

  const tone = { accepted: 'border-emerald-300 bg-emerald-50', pending: 'border-brand-300 bg-white' }[quote.status] || 'border-stone-200 bg-stone-50'
  const statusText = side === 'seller' && pending ? 'Waiting for the customer' : QUOTE_STATUS_LABELS[quote.status]

  return (
    <div className={`rounded-xl border-2 p-4 text-sm ${tone}`}>
      <div className="flex items-start justify-between gap-3">
        <p className="font-semibold text-stone-900">Quote: {quote.title}</p>
        <span className="shrink-0 text-xs font-semibold text-stone-600">{statusText}</span>
      </div>
      <p className="mt-1 whitespace-pre-line text-stone-700">{quote.description}</p>
      <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-4">
        <div><dt className="text-xs text-stone-500">Price</dt><dd className="font-medium">{formatETB(quote.price_santim)}{quote.quantity > 1 && ` x ${quote.quantity}`}</dd></div>
        <div><dt className="text-xs text-stone-500">Delivery</dt><dd className="font-medium">{quote.delivery_fee_santim === null ? 'Not included' : quote.delivery_fee_santim === 0 ? 'Free' : formatETB(quote.delivery_fee_santim)}</dd></div>
        <div><dt className="text-xs text-stone-500">Total</dt><dd className="font-bold text-stone-900">{formatETB(quote.total_santim)}</dd></div>
        <div><dt className="text-xs text-stone-500">Valid until</dt><dd className="font-medium">{new Date(`${quote.valid_until}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}</dd></div>
        {quote.production_days !== null && <div className="col-span-2"><dt className="text-xs text-stone-500">Ready in</dt><dd className="font-medium">{quote.production_days} days after you confirm</dd></div>}
      </dl>
      {error && <p className="mt-2 font-medium text-red-700">{error}</p>}
      {pending && side === 'customer' && (
        <div className="mt-3 flex flex-wrap gap-2">
          <button type="button" className="btn-primary" disabled={busy} onClick={() => act('accept')}>Accept quote</button>
          <button type="button" className="btn-secondary" disabled={busy} onClick={() => act('decline')}>Decline</button>
        </div>
      )}
      {pending && side === 'seller' && (
        <button type="button" className="btn-ghost mt-2 text-red-700" disabled={busy} onClick={() => act('withdraw')}>Withdraw quote</button>
      )}
      {quote.status === 'accepted' && side === 'customer' && <OrderQuote quote={quote} />}
      {quote.status === 'accepted' && side === 'seller' && (
        <p className="mt-2 text-emerald-800">
          {quote.ordered ? 'Ordered. You will find it under Orders.' : 'Accepted. The customer can now order it at this price. You will see it under Orders when they do.'}
        </p>
      )}
    </div>
  )
}

function OrderQuote({ quote }) {
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
  if (expired) return <p className="mt-2 text-stone-600">This quote can no longer be ordered. Ask the seller for a new one.</p>

  return (
    <div className="mt-3">
      {error && <p className="mb-2 font-medium text-red-700">{error}</p>}
      <div className="flex flex-wrap items-center gap-3">
        <button type="button" className="btn-primary" disabled={busy} onClick={add}>{busy ? 'Adding...' : 'Add to cart'}</button>
        <span className="text-xs text-stone-600">Order by {new Date(quote.order_by).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}. {pay.short}</span>
      </div>
    </div>
  )
}

function today(offsetDays = 0) {
  const d = new Date()
  d.setDate(d.getDate() + offsetDays)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function QuoteForm({ conversation, onSent, onCancel }) {
  const [form, setForm] = useState({
    title: conversation.product?.name || conversation.subject,
    description: '',
    price: '',
    quantity: 1,
    delivery_fee: '',
    production_days: '',
    valid_until: today(7),
  })
  const [errors, setErrors] = useState({})
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const set = (key) => (e) => setForm({ ...form, [key]: e.target.value })

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    try {
      await api.post(`/seller/conversations/${conversation.id}/quotes`, {
        ...form,
        quantity: Number(form.quantity) || 1,
        delivery_fee: form.delivery_fee.trim() || null,
        production_days: form.production_days === '' ? null : Number(form.production_days),
      })
      onSent()
    } catch (err) {
      const parsed = parseError(err)
      setErrors(parsed.fields)
      setError(Object.values(parsed.fields)[0] || parsed.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} className="card space-y-3 p-4" noValidate>
      <p className="font-semibold text-stone-900">Send a quote</p>
      <p className="text-xs text-stone-500">A new quote replaces any earlier quote in this conversation that has not been answered.</p>
      {error && <Alert tone="error">{error}</Alert>}
      <Field label="Title" name="q-title" error={errors.title}>
        <input id="q-title" className="input" maxLength={160} value={form.title} onChange={set('title')} />
      </Field>
      <Field label="What is included" name="q-desc" error={errors.description} hint="Size, material, finish, and anything the price depends on.">
        <textarea id="q-desc" className="input min-h-24" maxLength={3000} value={form.description} onChange={set('description')} />
      </Field>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Unit price (ETB)" name="q-price" error={errors.price}>
          <input id="q-price" className="input" inputMode="decimal" placeholder="e.g. 45000" value={form.price} onChange={set('price')} />
        </Field>
        <Field label="Quantity" name="q-qty" error={errors.quantity}>
          <input id="q-qty" type="number" min="1" max="1000" className="input" value={form.quantity} onChange={set('quantity')} />
        </Field>
        <Field label="Delivery fee (ETB, optional)" name="q-fee" error={errors.delivery_fee} hint="Leave empty if delivery is arranged separately. Enter 0 for free delivery.">
          <input id="q-fee" className="input" inputMode="decimal" value={form.delivery_fee} onChange={set('delivery_fee')} />
        </Field>
        <Field label="Production days (optional)" name="q-days" error={errors.production_days}>
          <input id="q-days" type="number" min="0" max="365" className="input" value={form.production_days} onChange={set('production_days')} />
        </Field>
        <Field label="Valid until" name="q-valid" error={errors.valid_until}>
          <input id="q-valid" type="date" className="input" min={today()} max={today(60)} value={form.valid_until} onChange={set('valid_until')} />
        </Field>
      </div>
      <div className="flex gap-2">
        <button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Sending...' : 'Send quote'}</button>
        <button type="button" className="btn-ghost" onClick={onCancel}>Cancel</button>
      </div>
    </form>
  )
}

export function ConversationView() {
  const { id } = useParams()
  const { refreshUnread } = useInbox()
  const { data, error, loading, reload, setData } = useFetch(`/conversations/${id}`)
  const [body, setBody] = useState('')
  const [sendError, setSendError] = useState(null)
  const [sending, setSending] = useState(false)
  const [quoteOpen, setQuoteOpen] = useState(false)
  const bottom = useRef(null)

  const c = data?.data
  const messageCount = c?.messages.length

  // Opening the thread marks it read on the server; update the header badge.
  useEffect(() => {
    if (c) refreshUnread()
  }, [c?.id, messageCount, refreshUnread]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    bottom.current?.scrollIntoView?.({ block: 'end' })
  }, [messageCount])

  // Light polling so replies show up without a manual refresh.
  useEffect(() => {
    const timer = setInterval(() => {
      if (document.visibilityState === 'visible') {
        api.get(`/conversations/${id}`).then((res) => setData(res.data)).catch(() => {})
      }
    }, REFRESH_MS)
    return () => clearInterval(timer)
  }, [id]) // eslint-disable-line react-hooks/exhaustive-deps

  if (loading && !c) return <Spinner />
  if (error) return <div className="container-page py-12"><ErrorState error={error} onRetry={reload} /></div>
  if (!c) return null

  const side = c.viewer_side
  const quotesById = Object.fromEntries(c.quotes.map((q) => [q.id, q]))
  const shownQuotes = new Set()
  const open = c.status === 'open'

  async function send(e) {
    e.preventDefault()
    if (!body.trim()) return
    setSending(true)
    setSendError(null)
    try {
      await api.post(`/conversations/${c.id}/messages`, { body })
      setBody('')
      await reload()
    } catch (err) {
      const parsed = parseError(err)
      setSendError(Object.values(parsed.fields)[0] || parsed.message)
    } finally {
      setSending(false)
    }
  }

  async function close() {
    if (!window.confirm('Close this conversation? Neither side can send new messages afterwards, and any unanswered quote is cancelled.')) return
    try {
      await api.post(`/conversations/${c.id}/close`)
      reload()
    } catch (err) {
      setSendError(parseError(err).message)
    }
  }

  return (
    <div className="container-page max-w-3xl py-8">
      <Link to="/messages" className="text-sm text-brand-700 hover:underline">Back to messages</Link>
      <header className="card mt-3 flex gap-3 p-4">
        {c.product && <ProductImage src={c.product.image} alt="" className="size-16 shrink-0 rounded-lg" />}
        <div className="min-w-0 flex-1">
          <h1 className="truncate text-lg font-bold text-stone-900">{c.subject}</h1>
          <p className="text-sm text-stone-600">
            {side === 'seller' ? `Customer: ${c.customer.name}` : <>Store: <Link to={`/sellers/${c.seller.slug}`} className="font-medium text-brand-700 hover:underline">{c.seller.store_name}</Link></>}
            {' '}&middot; {INQUIRY_TYPES[c.type]}
          </p>
          {c.product && (
            <p className="text-sm text-stone-600">
              <Link to={`/products/${c.product.slug}`} className="hover:underline">{c.product.name}</Link> &middot; listed at {formatETB(c.product.price_santim)}
            </p>
          )}
        </div>
        {open && <button type="button" className="btn-ghost h-fit shrink-0 text-sm" onClick={close}>Close</button>}
      </header>

      <ol className="mt-4 space-y-3" aria-label="Messages">
        {c.messages.map((m) => {
          const quote = m.quote_id && quotesById[m.quote_id]
          const showQuote = quote && !shownQuotes.has(quote.id)
          if (showQuote) shownQuotes.add(quote.id)
          return (
            <li key={m.id} className={`flex flex-col ${m.is_mine ? 'items-end' : 'items-start'}`}>
              {showQuote ? (
                <div className="w-full max-w-lg"><QuoteCard quote={quote} side={side} onChanged={reload} /></div>
              ) : (
                <div className={`max-w-[85%] rounded-2xl px-4 py-2 text-sm whitespace-pre-line break-words ${m.is_mine ? 'bg-brand-700 text-white' : 'bg-white text-stone-800 ring-1 ring-stone-200'}`}>
                  {m.body}
                </div>
              )}
              <span className="mt-1 text-xs text-stone-400">{new Date(m.created_at).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</span>
            </li>
          )
        })}
      </ol>
      <div ref={bottom} />

      {sendError && <div className="mt-4"><Alert tone="error">{sendError}</Alert></div>}

      {open ? (
        <>
          {side === 'seller' && quoteOpen && (
            <div className="mt-4"><QuoteForm conversation={c} onCancel={() => setQuoteOpen(false)} onSent={() => { setQuoteOpen(false); reload() }} /></div>
          )}
          <form onSubmit={send} className="mt-4 flex flex-col gap-2 sm:flex-row sm:items-end">
            <label htmlFor="reply" className="sr-only">Your reply</label>
            <textarea id="reply" className="input min-h-12 flex-1" rows={2} maxLength={2000} value={body} placeholder="Write a reply"
              onChange={(e) => setBody(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) send(e)
              }} />
            <div className="flex gap-2">
              <button type="submit" className="btn-primary" disabled={sending || !body.trim()}>{sending ? 'Sending...' : 'Send'}</button>
              {side === 'seller' && !quoteOpen && <button type="button" className="btn-secondary" onClick={() => setQuoteOpen(true)}>Send quote</button>}
            </div>
          </form>
        </>
      ) : (
        <div className="mt-4"><Alert>This conversation is closed. {side === 'customer' && 'Message the seller again from the listing to start a new one.'}</Alert></div>
      )}
    </div>
  )
}
