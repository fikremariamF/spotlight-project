import { CheckCircle2, LifeBuoy } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import AskSupport from '../components/AskSupport'
import OfferCard from '../components/OfferCard'
import { ProductImage } from '../components/ProductCard'
import { Alert, EmptyState, ErrorState, PageHeader, Pagination, Spinner, SupportStatusBadge } from '../components/ui'
import { useInbox } from '../context/InboxContext'
import { api, parseError } from '../lib/api'
import { formatDateTime, formatETB, timeAgo, toQuery } from '../lib/format'
import { useFetch } from '../lib/useFetch'

const REFRESH_MS = 20000
const TABS = [['active', 'Active'], ['done', 'Resolved and closed'], ['', 'All']]

/** What an inquiry is about, as one short line. */
function aboutLine(t) {
  if (t.product) return t.product.name
  if (t.order) return `Order ${t.order.number}`
  if (t.store) return t.store.store_name
  return t.topic_label
}

export function SupportInbox() {
  const [params, setParams] = useSearchParams()
  const tab = params.get('status') ?? 'active'
  const list = useFetch(`/support/tickets?${toQuery({ status: tab, page: params.get('page') || '' })}`)
  // Chats with stores from before support messaging, kept to read.
  const old = useFetch('/conversations')

  return (
    <div className="container-page py-8">
      <PageHeader title="Support"
        subtitle="Your questions to Betoch. Our team checks with the store and answers here."
        actions={<Link to="/support/new" className="btn-primary">Ask a question</Link>} />
      <div className="relative mb-4 flex gap-1 overflow-x-auto" role="tablist" aria-label="Filter inquiries">
        {TABS.map(([key, label]) => (
          <button key={key || 'all'} type="button" role="tab" aria-selected={tab === key}
            className={`shrink-0 rounded-full px-3 py-1.5 text-sm font-medium ${tab === key ? 'bg-brand-700 text-white' : 'bg-stone-100 text-stone-700 hover:bg-stone-200'}`}
            onClick={() => setParams({ status: key })}>
            {label}
          </button>
        ))}
      </div>

      {list.loading && !list.data && <Spinner />}
      {list.error && <ErrorState error={list.error} onRetry={list.reload} />}
      {list.data && (list.data.data.length ? (
        <>
          <ul className="card divide-y divide-stone-100">
            {list.data.data.map((t) => (
              <li key={t.id}>
                <Link to={`/support/${t.id}`} className="flex gap-3 p-4 hover:bg-stone-50">
                  {t.product?.image
                    ? <ProductImage src={t.product.image} alt="" className="size-14 shrink-0 rounded-lg" />
                    : <span className="flex size-14 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700"><LifeBuoy className="size-6" aria-hidden="true" /></span>}
                  <div className="min-w-0 flex-1">
                    <div className="flex items-baseline justify-between gap-2">
                      <p className={`truncate ${t.unread ? 'font-bold text-stone-900' : 'font-medium text-stone-800'}`}>
                        {t.subject}
                        {t.unread && <span className="ml-2 inline-block size-2 rounded-full bg-brand-600 align-middle" aria-label="new answer" />}
                      </p>
                      <span className="shrink-0 text-xs text-stone-500">{timeAgo(t.last_public_message_at)}</span>
                    </div>
                    <p className="truncate text-sm text-stone-600">{t.reference} · {t.topic_label}{aboutLine(t) !== t.subject && aboutLine(t) !== t.topic_label ? ` · ${aboutLine(t)}` : ''}</p>
                    <p className="truncate text-sm text-stone-500">
                      {t.last_message?.from === 'customer' ? 'You: ' : t.last_message ? 'Betoch: ' : ''}{t.last_message?.body}
                    </p>
                    <div className="mt-1"><SupportStatusBadge status={t.status} /></div>
                  </div>
                </Link>
              </li>
            ))}
          </ul>
          <Pagination meta={list.data.meta} onPage={(p) => setParams({ status: tab, page: String(p) })} />
        </>
      ) : (
        <EmptyState title={tab === 'active' ? 'No open questions' : 'Nothing here'} action={<Link to="/support/new" className="btn-primary">Ask a question</Link>}>
          Ask about a product, a price, delivery, an order, a return or a refund. You can also use "Ask about this product" on any listing.
        </EmptyState>
      ))}

      {old.data?.data.length > 0 && (
        <section className="mt-8">
          <h2 className="mb-1 text-sm font-semibold text-stone-700">Earlier chats with stores</h2>
          <p className="mb-2 text-sm text-stone-500">Kept for your records. Stores no longer message customers directly; ask Betoch Support instead.</p>
          <Link to="/messages" className="btn-secondary">See {old.data.meta?.total ?? old.data.data.length} earlier {(old.data.meta?.total ?? old.data.data.length) === 1 ? 'chat' : 'chats'}</Link>
        </section>
      )}
    </div>
  )
}

/** /support/new, optionally ?order=ID from an order page. */
export function NewSupportRequest() {
  const [params] = useSearchParams()
  const orderId = params.get('order')
  const order = useFetch(orderId && /^\d+$/.test(orderId) ? `/orders/${orderId}` : null)

  return (
    <div className="container-page max-w-2xl py-8">
      <Link to="/support" className="text-sm text-brand-700 hover:underline">Back to support</Link>
      <div className="mt-3">
        {order.loading && <Spinner />}
        {order.error && <div className="mb-3"><Alert tone="warning">We could not find that order, so this question is not linked to it.</Alert></div>}
        {!order.loading && (
          <AskSupport order={order.data?.data} title={order.data ? 'Get help with this order' : 'Ask Betoch Support'} />
        )}
      </div>
    </div>
  )
}

function Bubble({ m }) {
  if (m.kind === 'offer') {
    return (
      <li className="flex justify-center">
        <p className="rounded-full bg-stone-100 px-3 py-1 text-center text-xs text-stone-600">
          {m.from_role === 'customer' ? 'You' : 'Betoch Support'}: {m.body} <span className="text-stone-400">{timeAgo(m.created_at)}</span>
        </p>
      </li>
    )
  }
  const mine = m.from_role === 'customer'
  return (
    <li className={`flex flex-col ${mine ? 'items-end' : 'items-start'}`}>
      {!mine && <span className="mb-0.5 text-xs font-semibold text-brand-800">Betoch Support</span>}
      <div className={`max-w-[85%] rounded-2xl px-4 py-2 text-sm break-words whitespace-pre-line ${mine ? 'bg-brand-700 text-white' : 'bg-white text-stone-800 ring-1 ring-stone-200'}`}>
        {m.body}
      </div>
      <span className="mt-1 text-xs text-stone-400">{formatDateTime(m.created_at)}</span>
    </li>
  )
}

export function SupportTicketView() {
  const { id } = useParams()
  const { refreshUnread } = useInbox()
  const { data, error, loading, reload, setData } = useFetch(`/support/tickets/${id}`)
  const [body, setBody] = useState('')
  const [sendError, setSendError] = useState(null)
  const [sending, setSending] = useState(false)
  const bottom = useRef(null)

  const t = data?.data
  const count = t?.messages.length

  // Opening it marks it read on the server; update the header badge.
  useEffect(() => {
    if (t) refreshUnread()
  }, [t?.id, count, refreshUnread]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    bottom.current?.scrollIntoView?.({ block: 'end' })
  }, [count])

  // Light polling so answers show up without a manual refresh.
  useEffect(() => {
    const timer = setInterval(() => {
      if (document.visibilityState === 'visible') {
        api.get(`/support/tickets/${id}`).then((res) => setData(res.data)).catch(() => {})
      }
    }, REFRESH_MS)
    return () => clearInterval(timer)
  }, [id]) // eslint-disable-line react-hooks/exhaustive-deps

  if (loading && !t) return <Spinner />
  if (error) return <div className="container-page py-12"><ErrorState error={error} onRetry={reload} /></div>
  if (!t) return null

  const closed = t.status === 'closed'
  const offers = [...t.offers].sort((a, b) => b.id - a.id)

  async function send(e) {
    e.preventDefault()
    if (!body.trim()) return
    setSending(true)
    setSendError(null)
    try {
      await api.post(`/support/tickets/${t.id}/messages`, { body })
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
    const pending = offers.some((q) => q.status === 'pending')
    if (!window.confirm(`Close this question? You can always ask a new one.${pending ? ' The offer waiting for your answer will be declined.' : ''}`)) return
    try {
      await api.post(`/support/tickets/${t.id}/close`)
      reload()
    } catch (err) {
      setSendError(parseError(err).message)
    }
  }

  return (
    <div className="container-page max-w-3xl py-8">
      <Link to="/support" className="text-sm text-brand-700 hover:underline">Back to support</Link>
      <header className="card mt-3 flex gap-3 p-4">
        {t.product && <ProductImage src={t.product.image} alt="" className="size-16 shrink-0 rounded-lg" />}
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="min-w-0 truncate text-lg font-bold text-stone-900">{t.subject}</h1>
            <SupportStatusBadge status={t.status} />
          </div>
          <p className="text-sm text-stone-600">{t.reference} · {t.topic_label} · asked {formatDateTime(t.created_at)}</p>
          {t.product && (
            <p className="text-sm text-stone-600">
              <Link to={`/products/${t.product.slug}`} className="hover:underline">{t.product.name}</Link> · listed at {formatETB(t.product.price_santim)}
            </p>
          )}
          {t.order && <p className="text-sm text-stone-600"><Link to={`/orders/${t.order.id}`} className="hover:underline">Order {t.order.number}</Link></p>}
          {t.store && !t.product && <p className="text-sm text-stone-600">Store: <Link to={`/sellers/${t.store.slug}`} className="hover:underline">{t.store.store_name}</Link></p>}
        </div>
        {!closed && <button type="button" className="btn-ghost h-fit shrink-0 text-sm" onClick={close}>Close</button>}
      </header>

      {t.status === 'waiting' && <div className="mt-4"><Alert tone="warning">Betoch Support needs more information from you. Reply below.</Alert></div>}
      {t.status === 'resolved' && (
        <p className="mt-4 flex items-center gap-2 text-sm text-emerald-800"><CheckCircle2 className="size-4" aria-hidden="true" />Marked as resolved. Reply if you still need help.</p>
      )}

      {offers.length > 0 && (
        <section aria-labelledby="offers" className="mt-4 space-y-3">
          <h2 id="offers" className="text-sm font-semibold text-stone-700">{offers.length === 1 ? 'Offer for you' : 'Offers for you'}</h2>
          {offers.map((q) => <OfferCard key={q.id} quote={q} onChanged={reload} />)}
        </section>
      )}

      <ol className="mt-6 space-y-3" aria-label="Messages">
        {t.messages.map((m) => <Bubble key={m.id} m={m} />)}
      </ol>
      <div ref={bottom} />

      {sendError && <div className="mt-4"><Alert tone="error">{sendError}</Alert></div>}

      {closed ? (
        <div className="mt-4"><Alert>This question is closed. <Link to="/support/new" className="font-semibold underline">Ask a new one</Link> if you still need help.</Alert></div>
      ) : (
        <form onSubmit={send} className="mt-4 flex flex-col gap-2 sm:flex-row sm:items-end">
          <label htmlFor="reply" className="sr-only">Your reply</label>
          <textarea id="reply" className="input min-h-12 flex-1" rows={2} maxLength={3000} value={body} placeholder="Write a reply to Betoch Support"
            onChange={(e) => setBody(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) send(e)
            }} />
          <button type="submit" className="btn-primary" disabled={sending || !body.trim()}>{sending ? 'Sending...' : 'Send'}</button>
        </form>
      )}
    </div>
  )
}
