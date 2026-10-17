import { AlertTriangle, Lock, Send } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useOutletContext, useParams, useSearchParams } from 'react-router-dom'
import { ProductImage } from '../../components/ProductCard'
import { Alert, EmptyState, ErrorState, Field, PageHeader, Pagination, Spinner, StatusBadge, SupportStatusBadge } from '../../components/ui'
import { useAuth } from '../../context/AuthContext'
import { api, parseError } from '../../lib/api'
import {
  ORDER_STATUS_LABELS, QUOTE_STATUS_LABELS, REQUEST_STATUS_LABELS, REQUEST_TOPICS, SUPPORT_STATUS_LABELS, SUPPORT_TOPICS,
  formatDateTime, formatETB, timeAgo, toQuery,
} from '../../lib/format'
import { useFetch } from '../../lib/useFetch'

const REFRESH_MS = 30000

const firstError = (err) => {
  const e = parseError(err)
  return Object.values(e.fields)[0] || e.message
}

function today(offsetDays = 0) {
  const d = new Date()
  d.setDate(d.getDate() + offsetDays)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function Tabs({ tab, counts, onTab }) {
  const tabs = [['inquiries', 'Inquiries', counts?.needs_reply], ['requests', 'Store requests', counts?.store_answers], ['old', 'Old store chats']]
  return (
    <div className="mb-4 flex gap-1 overflow-x-auto" role="tablist" aria-label="Support sections">
      {tabs.map(([key, label, n]) => (
        <button key={key} type="button" role="tab" aria-selected={tab === key} onClick={() => onTab(key)}
          className={`shrink-0 rounded-full px-3 py-1.5 text-sm font-medium ${tab === key ? 'bg-brand-700 text-white' : 'bg-stone-100 text-stone-700 hover:bg-stone-200'}`}>
          {label}{n > 0 && <span className="ml-1.5 rounded-full bg-amber-100 px-1.5 text-xs font-semibold text-amber-800">{n}</span>}
        </button>
      ))}
    </div>
  )
}

/** /admin/support: the inbox. Tabs keep inquiries, requests to stores and the old chats apart. */
export default function AdminSupport() {
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') || 'inquiries'
  // The layout already loads /admin/summary for its menu counts.
  const summary = useOutletContext()
  return (
    <>
      <PageHeader title="Support" subtitle="Customers ask Betoch here. Check with stores internally, then answer the customer." />
      <Tabs tab={tab} counts={summary?.data?.data.support} onTab={(key) => setParams(key === 'inquiries' ? {} : { tab: key })} />
      {tab === 'inquiries' && <InquiryList params={params} setParams={setParams} />}
      {tab === 'requests' && <RequestList params={params} setParams={setParams} />}
      {tab === 'old' && <OldChatList params={params} setParams={setParams} />}
    </>
  )
}

const QUICK = [
  ['needs_reply', 'Needs reply'],
  ['mine', 'Mine'],
  ['unassigned', 'Unassigned'],
  ['escalated', 'Escalated'],
]

function InquiryList({ params, setParams }) {
  const get = (k) => params.get(k) || ''
  const quick = get('quick')
  const query = {
    status: get('status'),
    topic: get('topic'),
    q: get('q'),
    needs_reply: quick === 'needs_reply',
    assigned: quick === 'mine' ? 'me' : quick === 'unassigned' ? 'none' : '',
    escalated: quick === 'escalated',
    page: get('page'),
  }
  const list = useFetch(`/admin/support/tickets?${toQuery(query)}`)
  const [search, setSearch] = useState(get('q'))
  const counts = list.data?.meta?.counts
  const update = (changes) => setParams(toQuery({ status: get('status'), topic: get('topic'), q: get('q'), quick, ...changes, page: changes.page ?? '' }))

  return (
    <>
      <div className="mb-3 flex flex-wrap gap-1.5" aria-label="Quick filters">
        {QUICK.map(([key, label]) => (
          <button key={key} type="button" aria-pressed={quick === key} onClick={() => update({ quick: quick === key ? '' : key })}
            className={`rounded-full border px-3 py-1 text-sm ${quick === key ? 'border-brand-700 bg-brand-50 font-semibold text-brand-800' : 'border-stone-300 text-stone-700 hover:bg-stone-50'}`}>
            {label}{counts?.[key] > 0 && <span className="ml-1 font-semibold">{counts[key]}</span>}
          </button>
        ))}
      </div>
      <div className="mb-4 flex flex-col gap-2 sm:flex-row">
        <select className="input sm:w-48" value={get('status')} onChange={(e) => update({ status: e.target.value })} aria-label="Status">
          <option value="">Active (not closed)</option>
          {Object.entries(SUPPORT_STATUS_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          <option value="all">All</option>
        </select>
        <select className="input sm:w-48" value={get('topic')} onChange={(e) => update({ topic: e.target.value })} aria-label="Topic">
          <option value="">All topics</option>
          {Object.entries(SUPPORT_TOPICS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
        <form className="flex flex-1 gap-2" onSubmit={(e) => { e.preventDefault(); update({ q: search.trim() }) }}>
          <input className="input min-w-0" placeholder="Search #number, customer, listing, order or store" value={search} onChange={(e) => setSearch(e.target.value)} aria-label="Search inquiries" />
          <button className="btn-secondary" type="submit">Search</button>
        </form>
      </div>

      {list.loading && !list.data && <Spinner />}
      {list.error && <ErrorState error={list.error} onRetry={list.reload} />}
      {list.data && (list.data.data.length ? (
        <>
          <ul className="card divide-y divide-stone-100">
            {list.data.data.map((t) => (
              <li key={t.id}>
                <Link to={`/admin/support/${t.id}`} className="flex gap-3 px-4 py-3 hover:bg-stone-50">
                  <div className="min-w-0 flex-1 text-sm">
                    <div className="flex flex-wrap items-center gap-2">
                      {t.escalated_at && <AlertTriangle className="size-4 text-red-600" aria-label="Escalated" />}
                      <p className={`min-w-0 truncate ${t.needs_reply ? 'font-bold text-stone-900' : 'font-medium text-stone-800'}`}>{t.reference} {t.subject}</p>
                      <SupportStatusBadge status={t.status} />
                      {t.needs_reply && <span className="rounded-full bg-amber-100 px-2 text-xs font-semibold text-amber-800">Needs reply</span>}
                    </div>
                    <p className="truncate text-stone-600">
                      {t.customer?.name} · {t.topic_label}{t.store ? ` · ${t.store.store_name}` : ''}{t.order ? ` · ${t.order.number}` : ''}
                    </p>
                    <p className="truncate text-stone-500">{t.last_message?.from === 'customer' ? 'Customer: ' : 'Betoch: '}{t.last_message?.body}</p>
                  </div>
                  <div className="shrink-0 text-right text-xs text-stone-500">
                    <p>{timeAgo(t.last_activity_at)}</p>
                    <p>{t.assignee ? t.assignee.name : 'Unassigned'}</p>
                  </div>
                </Link>
              </li>
            ))}
          </ul>
          <Pagination meta={list.data.meta} onPage={(p) => update({ page: p })} />
        </>
      ) : <EmptyState title="No inquiries match">New questions from customers appear here.</EmptyState>)}
    </>
  )
}

function RequestList({ params, setParams }) {
  const get = (k) => params.get(k) || ''
  const list = useFetch(`/admin/support/requests?${toQuery({ status: get('status'), q: get('q'), page: get('page') })}`)
  const [search, setSearch] = useState(get('q'))
  const [asking, setAsking] = useState(false)
  const navigate = useNavigate()
  const update = (changes) => setParams(toQuery({ tab: 'requests', status: get('status'), q: get('q'), ...changes, page: changes.page ?? '' }))

  return (
    <>
      <div className="mb-4 flex flex-col gap-2 sm:flex-row">
        <select className="input sm:w-56" value={get('status')} onChange={(e) => update({ status: e.target.value })} aria-label="Status">
          <option value="">Active</option>
          <option value="answered">Store answered</option>
          <option value="open">Waiting for the store</option>
          <option value="closed">Closed</option>
          <option value="all">All</option>
        </select>
        <form className="flex flex-1 gap-2" onSubmit={(e) => { e.preventDefault(); update({ q: search.trim() }) }}>
          <input className="input min-w-0" placeholder="Search store or subject" value={search} onChange={(e) => setSearch(e.target.value)} aria-label="Search store requests" />
          <button className="btn-secondary" type="submit">Search</button>
        </form>
        {!asking && <button type="button" className="btn-primary" onClick={() => setAsking(true)}>Ask a store</button>}
      </div>
      {asking && (
        <div className="mb-4">
          <AskStoreForm onCancel={() => setAsking(false)} onSent={(r) => navigate(`/admin/support/requests/${r.id}`)} />
        </div>
      )}
      {list.loading && !list.data && <Spinner />}
      {list.error && <ErrorState error={list.error} onRetry={list.reload} />}
      {list.data && (list.data.data.length ? (
        <>
          <ul className="card divide-y divide-stone-100">
            {list.data.data.map((r) => (
              <li key={r.id}>
                <Link to={`/admin/support/requests/${r.id}`} className="flex gap-3 px-4 py-3 text-sm hover:bg-stone-50">
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <p className={`min-w-0 truncate ${r.status === 'answered' ? 'font-bold text-stone-900' : 'font-medium text-stone-800'}`}>{r.store?.store_name}: {r.subject}</p>
                      <RequestBadge status={r.status} />
                    </div>
                    <p className="truncate text-stone-600">{r.topic_label}{r.ticket ? ` · inquiry ${r.ticket.reference}` : ' · no inquiry'}{r.created_by ? ` · asked by ${r.created_by}` : ''}</p>
                  </div>
                  <span className="shrink-0 text-xs text-stone-500">{timeAgo(r.last_message_at)}</span>
                </Link>
              </li>
            ))}
          </ul>
          <Pagination meta={list.data.meta} onPage={(p) => update({ page: p })} />
        </>
      ) : <EmptyState title="No store requests match">Ask a store from an inquiry, or with "Ask a store" above.</EmptyState>)}
    </>
  )
}

function OldChatList({ params, setParams }) {
  const get = (k) => params.get(k) || ''
  const list = useFetch(`/admin/support/old-chats?${toQuery({ q: get('q'), page: get('page') })}`)
  const [search, setSearch] = useState(get('q'))
  const update = (changes) => setParams(toQuery({ tab: 'old', q: get('q'), ...changes, page: changes.page ?? '' }))

  return (
    <>
      <p className="mb-3 text-sm text-stone-600">Chats customers had with stores before questions went through Betoch. Read-only; opening one is recorded in the audit log.</p>
      <form className="mb-4 flex gap-2" onSubmit={(e) => { e.preventDefault(); update({ q: search.trim() }) }}>
        <input className="input min-w-0" placeholder="Search customer, store or subject" value={search} onChange={(e) => setSearch(e.target.value)} aria-label="Search old chats" />
        <button className="btn-secondary" type="submit">Search</button>
      </form>
      {list.loading && !list.data && <Spinner />}
      {list.error && <ErrorState error={list.error} onRetry={list.reload} />}
      {list.data && (list.data.data.length ? (
        <>
          <ul className="card divide-y divide-stone-100">
            {list.data.data.map((c) => (
              <li key={c.id}>
                <Link to={`/admin/support/old-chats/${c.id}`} className="flex gap-3 px-4 py-3 text-sm hover:bg-stone-50">
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-medium text-stone-800">{c.customer?.name} and {c.seller?.store_name}</p>
                    <p className="truncate text-stone-600">{c.subject}{c.status === 'closed' ? ' · closed' : ''}</p>
                  </div>
                  <span className="shrink-0 text-xs text-stone-500">{timeAgo(c.last_message_at)}</span>
                </Link>
              </li>
            ))}
          </ul>
          <Pagination meta={list.data.meta} onPage={(p) => update({ page: p })} />
        </>
      ) : <EmptyState title="No old chats" />)}
    </>
  )
}

function RequestBadge({ status }) {
  const tone = { open: 'bg-sky-100 text-sky-800', answered: 'bg-amber-100 text-amber-800', closed: 'bg-stone-200 text-stone-700' }[status]
  return <span className={`inline-flex shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold ${tone}`}>{REQUEST_STATUS_LABELS[status] || status}</span>
}

/** Choose an approved (or suspended) store by name. */
function StorePicker({ value, onChange, error }) {
  const [term, setTerm] = useState('')
  const [results, setResults] = useState([])
  useEffect(() => {
    const q = term.trim()
    if (q.length < 2) return undefined
    let live = true
    const timer = setTimeout(() => {
      api.get(`/admin/sellers?${toQuery({ q, status: 'approved' })}`).then((res) => live && setResults(res.data.data.slice(0, 8))).catch(() => {})
    }, 250)
    return () => {
      live = false
      clearTimeout(timer)
    }
  }, [term])

  if (value) {
    return (
      <div>
        <p className="label">Store</p>
        <div className="flex items-center justify-between gap-2 rounded-lg border border-stone-300 px-3 py-2 text-sm">
          <span className="font-medium">{value.store_name}</span>
          <button type="button" className="text-brand-700 hover:underline" onClick={() => onChange(null)}>Change</button>
        </div>
      </div>
    )
  }
  return (
    <Field label="Store" name="ask-store" error={error}>
      <input id="ask-store" className="input" placeholder="Type a store name" value={term} onChange={(e) => setTerm(e.target.value)} autoComplete="off" />
      {term.trim().length >= 2 && results.length > 0 && (
        <ul className="mt-1 divide-y divide-stone-100 rounded-lg border border-stone-200 bg-white text-sm">
          {results.map((s) => (
            <li key={s.id}><button type="button" className="w-full px-3 py-2 text-left hover:bg-stone-50" onClick={() => onChange(s)}>{s.store_name}</button></li>
          ))}
        </ul>
      )}
    </Field>
  )
}

/**
 * Ask a store something, from an inquiry or on its own. Nothing about the customer goes with it:
 * the store sees only the admin's words and its own listing or order.
 */
function AskStoreForm({ ticket, onSent, onCancel }) {
  const [store, setStore] = useState(ticket?.store ? { id: ticket.store.id, store_name: ticket.store.store_name } : null)
  const sameStore = ticket?.store && store?.id === ticket.store.id
  const [form, setForm] = useState({
    topic: { availability: 'stock', price: 'price', custom: 'price', delivery: 'delivery', order: 'fulfilment', product: 'specs' }[ticket?.topic] || 'other',
    subject: ticket?.subject || '',
    body: '',
    attach: true,
  })
  const [errors, setErrors] = useState({})
  const [busy, setBusy] = useState(false)
  const set = (key) => (e) => setForm({ ...form, [key]: e.target.value })

  async function submit(e) {
    e.preventDefault()
    if (!store) {
      setErrors({ seller_profile_id: 'Choose a store.' })
      return
    }
    setBusy(true)
    setErrors({})
    try {
      const res = await api.post('/admin/support/requests', {
        support_ticket_id: ticket?.id ?? null,
        seller_profile_id: store.id,
        topic: form.topic,
        subject: form.subject,
        body: form.body,
        product_id: sameStore && form.attach && ticket.product ? ticket.product.id : null,
        order_id: sameStore && form.attach && ticket.order ? ticket.order.id : null,
      })
      onSent(res.data.data)
    } catch (err) {
      const p = parseError(err)
      setErrors(Object.keys(p.fields).length ? p.fields : { body: p.message })
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} className="card space-y-3 p-4" noValidate>
      <div>
        <p className="font-semibold text-stone-900">Ask a store</p>
        <p className="text-xs text-stone-500">The store sees only what you write here, plus its own listing or order. It never sees the customer or their messages.</p>
      </div>
      <StorePicker value={store} onChange={setStore} error={errors.seller_profile_id} />
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="About" name="ask-topic" error={errors.topic}>
          <select id="ask-topic" className="input" value={form.topic} onChange={set('topic')}>
            {Object.entries(REQUEST_TOPICS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </Field>
        <Field label="Subject" name="ask-subject" error={errors.subject}>
          <input id="ask-subject" className="input" maxLength={160} value={form.subject} onChange={set('subject')} />
        </Field>
      </div>
      <Field label="Question for the store" name="ask-body" error={errors.body} hint="Do not paste the customer's name, phone or address.">
        <textarea id="ask-body" className="input min-h-24" maxLength={3000} value={form.body} onChange={set('body')} />
      </Field>
      {sameStore && (ticket.product || ticket.order) && (
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" className="size-4 accent-brand-700" checked={form.attach} onChange={(e) => setForm({ ...form, attach: e.target.checked })} />
          Attach {ticket.product ? `the listing "${ticket.product.name}"` : `order ${ticket.order.number}`}
        </label>
      )}
      {(errors.product_id || errors.order_id) && <p className="text-xs font-medium text-red-700">{errors.product_id || errors.order_id}</p>}
      <div className="flex gap-2">
        <button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Sending...' : 'Send to store'}</button>
        {onCancel && <button type="button" className="btn-ghost" onClick={onCancel}>Cancel</button>}
      </div>
    </form>
  )
}

/** Turn a store's proposal into an offer the customer can accept. Price and terms are the store's. */
function SendOfferForm({ request, message, ticket, onDone, onCancel }) {
  const p = message.proposal
  const [form, setForm] = useState({
    title: ticket?.product?.name || request.subject,
    description: '',
    valid_until: p.valid_until < today(3) ? p.valid_until : today(3),
  })
  const [errors, setErrors] = useState({})
  const [busy, setBusy] = useState(false)
  const set = (key) => (e) => setForm({ ...form, [key]: e.target.value })

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    try {
      await api.post(`/admin/support/requests/${request.id}/proposals/${message.id}/send`, form)
      onDone()
    } catch (err) {
      const parsed = parseError(err)
      setErrors(Object.keys(parsed.fields).length ? parsed.fields : { proposal: parsed.message })
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} className="mt-2 space-y-3 rounded-xl border border-brand-200 bg-brand-50/40 p-3" noValidate>
      <p className="text-sm font-semibold text-stone-900">Send as an offer for {formatETB(p.total_santim)}</p>
      <p className="text-xs text-stone-600">The customer sees your title and description with the store's price and terms, and can accept and order it. Any earlier offer from this store that is still waiting is withdrawn.</p>
      {errors.proposal && <Alert tone="error">{errors.proposal}</Alert>}
      <Field label="Title" name={`offer-title-${message.id}`} error={errors.title}>
        <input id={`offer-title-${message.id}`} className="input" maxLength={160} value={form.title} onChange={set('title')} />
      </Field>
      <Field label="What is included (the customer reads this)" name={`offer-desc-${message.id}`} error={errors.description}>
        <textarea id={`offer-desc-${message.id}`} className="input min-h-20" maxLength={3000} value={form.description} onChange={set('description')} />
      </Field>
      <Field label="Customer can accept until" name={`offer-valid-${message.id}`} error={errors.valid_until} hint={`No later than the store's ${p.valid_until}.`}>
        <input id={`offer-valid-${message.id}`} type="date" className="input sm:w-56" min={today()} max={p.valid_until} value={form.valid_until} onChange={set('valid_until')} />
      </Field>
      <div className="flex gap-2">
        <button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Sending...' : 'Send offer to customer'}</button>
        <button type="button" className="btn-ghost" onClick={onCancel}>Cancel</button>
      </div>
    </form>
  )
}

/** One request to a store, with its messages, proposals and the admin's reply box. */
function RequestThread({ request, ticket, onChanged, onUseInReply }) {
  const [body, setBody] = useState('')
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const [sending, setSending] = useState(null)
  const closed = request.status === 'closed'

  async function reply(e) {
    e.preventDefault()
    if (!body.trim()) return
    setBusy(true)
    setError(null)
    try {
      await api.post(`/admin/support/requests/${request.id}/messages`, { body })
      setBody('')
      onChanged()
    } catch (err) {
      setError(firstError(err))
    } finally {
      setBusy(false)
    }
  }

  async function close() {
    if (!window.confirm('Close this request? The store can no longer reply to it.')) return
    try {
      await api.post(`/admin/support/requests/${request.id}/close`)
      onChanged()
    } catch (err) {
      setError(firstError(err))
    }
  }

  return (
    <div className="card p-4 text-sm">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="font-semibold text-stone-900">{request.store?.store_name}: {request.subject}</p>
          <p className="text-xs text-stone-500">
            {request.topic_label} · asked by {request.created_by || 'an admin'} {timeAgo(request.created_at)}
            {request.product && ` · listing ${request.product.name}`}{request.order && ` · order ${request.order.number}`}
          </p>
        </div>
        <RequestBadge status={request.status} />
      </div>
      <p className="mt-2 flex items-center gap-1.5 text-xs font-medium text-amber-800"><Lock className="size-3.5" aria-hidden="true" />Internal. The customer does not see this conversation.</p>
      <ol className="mt-3 space-y-2">
        {request.messages.map((m) => (
          <li key={m.id} className={m.from_role === 'system' ? 'text-center text-xs text-stone-500' : `rounded-lg p-3 ${m.from_role === 'seller' ? 'bg-amber-50 ring-1 ring-amber-200' : 'bg-stone-50'}`}>
            {m.from_role === 'system' ? <>{m.body} · {timeAgo(m.created_at)}</> : (
              <>
                <p className="text-xs font-semibold text-stone-600">{m.from_role === 'seller' ? `${request.store?.store_name || 'Store'} (store)` : m.from} · {formatDateTime(m.created_at)}</p>
                <p className="mt-0.5 whitespace-pre-line break-words text-stone-800">{m.body}</p>
                {m.proposal && (
                  <div className="mt-2 rounded-lg border border-stone-200 bg-white p-3">
                    <p className="font-semibold">Proposal: {formatETB(m.proposal.price_santim)}{m.proposal.quantity > 1 && ` x ${m.proposal.quantity}`} = {formatETB(m.proposal.total_santim)}</p>
                    <p className="text-xs text-stone-600">
                      Delivery {m.proposal.delivery_fee_santim === null ? 'priced at checkout' : m.proposal.delivery_fee_santim === 0 ? 'free' : formatETB(m.proposal.delivery_fee_santim)}
                      {m.proposal.production_days !== null && ` · ready in ${m.proposal.production_days} days`} · store's price valid until {m.proposal.valid_until}
                    </p>
                    {m.proposal.sent
                      ? <p className="mt-1 text-xs font-medium text-emerald-800">Sent to the customer{m.proposal.offer_status ? ` · ${QUOTE_STATUS_LABELS[m.proposal.offer_status]?.replace('Waiting for your answer', 'waiting for the customer')}` : ''}</p>
                      : ticket && ticket.status !== 'closed' && sending !== m.id && (
                        <button type="button" className="btn-primary mt-2" onClick={() => setSending(m.id)}><Send className="size-4" aria-hidden="true" />Send as offer</button>
                      )}
                    {!m.proposal.sent && !ticket && <p className="mt-1 text-xs text-stone-500">Not linked to a customer inquiry, so it cannot be sent as an offer.</p>}
                    {sending === m.id && (
                      <SendOfferForm request={request} message={m} ticket={ticket} onCancel={() => setSending(null)} onDone={() => { setSending(null); onChanged() }} />
                    )}
                  </div>
                )}
                {m.from_role === 'seller' && onUseInReply && (
                  <button type="button" className="mt-1 text-xs font-medium text-brand-700 hover:underline" onClick={() => onUseInReply(m.body)}>Use in reply to customer</button>
                )}
              </>
            )}
          </li>
        ))}
      </ol>
      {error && <div className="mt-2"><Alert tone="error">{error}</Alert></div>}
      {!closed && (
        <form onSubmit={reply} className="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end">
          <label htmlFor={`req-reply-${request.id}`} className="sr-only">Message to the store</label>
          <textarea id={`req-reply-${request.id}`} className="input min-h-12 flex-1" rows={2} maxLength={3000} value={body} placeholder="Write to the store" onChange={(e) => setBody(e.target.value)} />
          <div className="flex gap-2">
            <button type="submit" className="btn-secondary" disabled={busy || !body.trim()}>{busy ? 'Sending...' : 'Send to store'}</button>
            <button type="button" className="btn-ghost" onClick={close}>Close request</button>
          </div>
        </form>
      )}
    </div>
  )
}

function TimelineItem({ m, customerName }) {
  if (m.kind === 'event') {
    return <li className="text-center text-xs text-stone-500">{m.body} · {m.from} · {formatDateTime(m.created_at)}</li>
  }
  if (m.kind === 'note') {
    return (
      <li className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm">
        <p className="flex items-center gap-1.5 text-xs font-semibold text-amber-900"><Lock className="size-3.5" aria-hidden="true" />Private note · {m.from} · {formatDateTime(m.created_at)}</p>
        <p className="mt-1 whitespace-pre-line break-words text-stone-800">{m.body}</p>
      </li>
    )
  }
  if (m.kind === 'offer') {
    return <li className="flex justify-center"><p className="rounded-full bg-stone-100 px-3 py-1 text-xs text-stone-600">{m.from_role === 'customer' ? customerName : m.from}: {m.body} · {timeAgo(m.created_at)}</p></li>
  }
  const fromCustomer = m.from_role === 'customer'
  return (
    <li className={`flex flex-col ${fromCustomer ? 'items-start' : 'items-end'}`}>
      <span className="mb-0.5 text-xs font-semibold text-stone-500">{fromCustomer ? m.from : `${m.from} to customer`}</span>
      <div className={`max-w-[85%] rounded-2xl px-4 py-2 text-sm break-words whitespace-pre-line ${fromCustomer ? 'bg-white text-stone-800 ring-1 ring-stone-200' : 'bg-brand-700 text-white'}`}>{m.body}</div>
      <span className="mt-1 text-xs text-stone-400">{formatDateTime(m.created_at)}</span>
    </li>
  )
}

/** `mode` and `body` live in the page, so "Use in reply" on a store's answer can fill the reply. */
function Composer({ ticket, mode, setMode, body, setBody, onSent }) {
  const closed = ticket.status === 'closed'
  const [picked, setThen] = useState('')
  // A closed inquiry can only be answered by reopening it.
  const then = closed ? 'open' : picked === 'open' ? '' : picked
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  async function submit(e) {
    e.preventDefault()
    if (!body.trim()) return
    setBusy(true)
    setError(null)
    try {
      if (mode === 'note') await api.post(`/admin/support/tickets/${ticket.id}/notes`, { body })
      else await api.post(`/admin/support/tickets/${ticket.id}/messages`, { body, status: then || null })
      setBody('')
      onSent()
    } catch (err) {
      setError(firstError(err))
    } finally {
      setBusy(false)
    }
  }

  const note = mode === 'note'
  return (
    <form onSubmit={submit} className={`card space-y-3 p-4 ${note ? 'border-amber-300 bg-amber-50/60' : ''}`} noValidate>
      <div className="flex gap-1" role="tablist" aria-label="Write">
        {[['reply', 'Reply to customer'], ['note', 'Private note']].map(([key, label]) => (
          <button key={key} type="button" role="tab" aria-selected={mode === key} onClick={() => setMode(key)}
            className={`rounded-full px-3 py-1.5 text-sm font-medium ${mode === key ? (key === 'note' ? 'bg-amber-200 text-amber-900' : 'bg-brand-700 text-white') : 'bg-stone-100 text-stone-700 hover:bg-stone-200'}`}>
            {label}
          </button>
        ))}
      </div>
      <p className="text-xs text-stone-600">
        {note ? 'Only admins see notes. Customers and stores never do.' : 'The customer sees this, signed "Betoch Support".'}
      </p>
      {error && <Alert tone="error">{error}</Alert>}
      <label htmlFor="composer" className="sr-only">{note ? 'Private note' : 'Reply to the customer'}</label>
      <textarea id="composer" className="input min-h-28" maxLength={5000} value={body} onChange={(e) => setBody(e.target.value)} />
      <div className="flex flex-wrap items-center gap-2">
        {!note && (
          <select className="input w-auto" value={then} onChange={(e) => setThen(e.target.value)} aria-label="After sending">
            {closed ? <option value="open">Reopen and send</option> : <option value="">Keep status after sending</option>}
            {!closed && <option value="waiting">Then: waiting for information</option>}
            {!closed && <option value="resolved">Then: resolved</option>}
            {!closed && <option value="closed">Then: closed</option>}
          </select>
        )}
        <button type="submit" className={note ? 'btn-secondary' : 'btn-primary'} disabled={busy || !body.trim()}>{busy ? 'Saving...' : note ? 'Add note' : 'Send to customer'}</button>
      </div>
    </form>
  )
}

function ManagePanel({ ticket, onChanged }) {
  const admins = useFetch('/admin/support/admins')
  const [picked, setStatus] = useState(null)
  const status = picked ?? ticket.status
  const [note, setNote] = useState('')
  const [reason, setReason] = useState('')
  const [escalating, setEscalating] = useState(false)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const me = useAuth().user?.id

  async function save(payload) {
    setBusy(true)
    setError(null)
    try {
      await api.put(`/admin/support/tickets/${ticket.id}`, payload)
      setNote('')
      setReason('')
      setEscalating(false)
      setStatus(null)
      onChanged()
    } catch (err) {
      setError(firstError(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="card space-y-4 p-4 text-sm" aria-label="Manage inquiry">
      {error && <Alert tone="error">{error}</Alert>}
      <div>
        <Field label="Status" name="ticket-status">
          <select id="ticket-status" className="input" value={status} onChange={(e) => setStatus(e.target.value)}>
            {Object.entries(SUPPORT_STATUS_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </Field>
        {status !== ticket.status && (
          <div className="mt-2 space-y-2">
            <input className="input" maxLength={500} placeholder="Note for the history (optional)" value={note} onChange={(e) => setNote(e.target.value)} aria-label="Status note" />
            {status === 'closed' && ticket.offers.some((o) => o.status === 'pending') && <p className="text-xs text-amber-800">Closing withdraws the offer still waiting for the customer.</p>}
            <button type="button" className="btn-primary" disabled={busy} onClick={() => save({ status, note: note.trim() || null })}>Save status</button>
          </div>
        )}
      </div>
      <div>
        <Field label="Assigned to" name="ticket-assignee">
          <select id="ticket-assignee" className="input" value={ticket.assignee?.id ?? ''} disabled={busy || !admins.data}
            onChange={(e) => save({ assigned_to: e.target.value ? Number(e.target.value) : null })}>
            <option value="">Nobody</option>
            {(admins.data?.data || []).map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
          </select>
        </Field>
        {me && ticket.assignee?.id !== me && <button type="button" className="mt-1 text-sm font-medium text-brand-700 hover:underline" onClick={() => save({ assigned_to: me })}>Assign to me</button>}
      </div>
      <div>
        <p className="label">Escalation</p>
        {ticket.escalated_at ? (
          <div className="rounded-lg bg-red-50 p-3 text-red-900">
            <p className="font-semibold">Escalated {timeAgo(ticket.escalated_at)}</p>
            {ticket.escalation_reason && <p className="mt-0.5">{ticket.escalation_reason}</p>}
            <button type="button" className="mt-2 text-sm font-medium underline" disabled={busy} onClick={() => save({ escalated: false })}>Remove escalation</button>
          </div>
        ) : escalating ? (
          <div className="space-y-2">
            <input className="input" maxLength={300} placeholder="Why? For whoever picks it up" value={reason} onChange={(e) => setReason(e.target.value)} aria-label="Escalation reason" />
            <div className="flex gap-2">
              <button type="button" className="btn-secondary text-red-700" disabled={busy || !reason.trim()} onClick={() => save({ escalated: true, escalation_reason: reason })}>Escalate</button>
              <button type="button" className="btn-ghost" onClick={() => setEscalating(false)}>Cancel</button>
            </div>
          </div>
        ) : (
          <button type="button" className="btn-secondary" onClick={() => setEscalating(true)}><AlertTriangle className="size-4" aria-hidden="true" />Escalate a complaint</button>
        )}
      </div>
    </section>
  )
}

function OfferList({ offers, onChanged }) {
  const [error, setError] = useState(null)
  if (!offers.length) return null
  async function withdraw(o) {
    if (!window.confirm(`Withdraw the offer "${o.title}"? The customer can no longer accept it.`)) return
    try {
      await api.post(`/admin/support/offers/${o.id}/withdraw`)
      onChanged()
    } catch (err) {
      setError(firstError(err))
    }
  }
  return (
    <section className="card p-4 text-sm" aria-label="Offers sent">
      <h2 className="mb-2 font-semibold text-stone-900">Offers sent</h2>
      {error && <Alert tone="error">{error}</Alert>}
      <ul className="space-y-2">
        {offers.map((o) => (
          <li key={o.id} className="rounded-lg bg-stone-50 p-2">
            <p className="font-medium">{o.title} · {formatETB(o.total_santim)}</p>
            <p className="text-xs text-stone-600">{o.store?.store_name} · {QUOTE_STATUS_LABELS[o.status]?.replace('Waiting for your answer', 'Waiting for the customer')}{o.ordered ? ' · ordered' : ''} · until {o.valid_until}</p>
            {o.status === 'pending' && <button type="button" className="mt-1 text-xs font-medium text-red-700 hover:underline" onClick={() => withdraw(o)}>Withdraw</button>}
          </li>
        ))}
      </ul>
    </section>
  )
}

function useLive(url) {
  const fetched = useFetch(url)
  const { setData } = fetched
  useEffect(() => {
    const timer = setInterval(() => {
      if (document.visibilityState === 'visible') api.get(url).then((res) => setData(res.data)).catch(() => {})
    }, REFRESH_MS)
    return () => clearInterval(timer)
  }, [url]) // eslint-disable-line react-hooks/exhaustive-deps
  return fetched
}

/** /admin/support/:id */
export function AdminSupportTicket() {
  const { id } = useParams()
  const { data, error, loading, reload } = useLive(`/admin/support/tickets/${id}`)
  const [asking, setAsking] = useState(false)
  const [mode, setMode] = useState('reply')
  const [body, setBody] = useState('')
  const t = data?.data

  function addToReply(text) {
    setMode('reply')
    setBody((b) => (b.trim() ? `${b}\n\n${text}` : text))
    document.getElementById('composer')?.focus()
  }

  if (loading && !t) return <Spinner />
  if (error) return <ErrorState error={error} onRetry={reload} />
  if (!t) return null

  return (
    <>
      <Link to="/admin/support" className="text-sm text-brand-700 hover:underline">Back to support</Link>
      <div className="mt-3 grid gap-6 lg:grid-cols-[minmax(0,1fr)_300px]">
        <div className="min-w-0 space-y-4">
          <header className="card p-4">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="min-w-0 text-lg font-bold text-stone-900">{t.reference} {t.subject}</h1>
              <SupportStatusBadge status={t.status} />
              {t.needs_reply && <span className="rounded-full bg-amber-100 px-2 text-xs font-semibold text-amber-800">Needs reply</span>}
            </div>
            <p className="text-sm text-stone-600">{t.topic_label} · asked {formatDateTime(t.created_at)}{t.first_response_at ? ` · first answer ${formatDateTime(t.first_response_at)}` : ''}</p>
            {t.escalated_at && (
              <p className="mt-2 flex items-start gap-2 rounded-lg bg-red-50 p-2 text-sm text-red-900">
                <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />Escalated: {t.escalation_reason || 'no reason given'}
              </p>
            )}
          </header>

          <ol className="space-y-3" aria-label="Inquiry history">
            {t.messages.map((m) => <TimelineItem key={m.id} m={m} customerName={t.customer?.name} />)}
          </ol>

          <Composer ticket={t} mode={mode} setMode={setMode} body={body} setBody={setBody} onSent={reload} />

          <section aria-labelledby="store-requests" className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h2 id="store-requests" className="text-sm font-semibold text-stone-700">Checking with stores</h2>
              {!asking && t.status !== 'closed' && <button type="button" className="btn-secondary" onClick={() => setAsking(true)}>Ask a store</button>}
            </div>
            {asking && <AskStoreForm ticket={t} onCancel={() => setAsking(false)} onSent={() => { setAsking(false); reload() }} />}
            {t.store_requests.length === 0 && !asking && <p className="text-sm text-stone-500">No store has been asked yet.</p>}
            {t.store_requests.map((r) => (
              <RequestThread key={r.id} request={r} ticket={t} onChanged={reload} onUseInReply={addToReply} />
            ))}
          </section>
        </div>

        <aside className="space-y-4">
          <ManagePanel ticket={t} onChanged={reload} />
          <section className="card space-y-1 p-4 text-sm" aria-label="Customer">
            <h2 className="font-semibold text-stone-900">Customer</h2>
            {t.customer && (
              <>
                <p><Link to={`/admin/users/${t.customer.id}`} className="text-brand-700 hover:underline">{t.customer.name}</Link></p>
                <p className="break-all text-stone-600">{t.customer.email}</p>
                {t.customer.phone && <p className="text-stone-600">{t.customer.phone}</p>}
                <p className="pt-1 text-xs text-stone-500">Never share these with a store unless a delivery needs it.</p>
              </>
            )}
          </section>
          {(t.product || t.order || t.store) && (
            <section className="card space-y-2 p-4 text-sm" aria-label="About">
              <h2 className="font-semibold text-stone-900">About</h2>
              {t.product && (
                <div className="flex gap-2">
                  <ProductImage src={t.product.image} alt="" className="size-12 shrink-0 rounded-lg" />
                  <div className="min-w-0">
                    <Link to={`/products/${t.product.slug}`} className="font-medium text-brand-700 hover:underline">{t.product.name}</Link>
                    <p className="text-xs text-stone-600">{formatETB(t.product.price_santim)} · {t.product.status} · {t.product.stock_type === 'made_to_order' ? 'made to order' : `${t.product.stock_quantity} in stock`}</p>
                  </div>
                </div>
              )}
              {t.order && (
                <p><Link to={`/admin/orders/${t.order.id}`} className="font-medium text-brand-700 hover:underline">Order {t.order.number}</Link>
                  <span className="block text-xs text-stone-600">{ORDER_STATUS_LABELS[t.order.status]} · {formatETB(t.order.total_santim)} · {t.order.payment_method === 'online' ? 'online' : 'cash on delivery'}, {t.order.payment_status}</span></p>
              )}
              {t.store && (
                <div>
                  <p className="flex flex-wrap items-center gap-2 font-medium">{t.store.store_name} <StatusBadge status={t.store.status} /></p>
                  {t.store.phone && <p className="text-xs text-stone-600">Store phone {t.store.phone}</p>}
                </div>
              )}
            </section>
          )}
          <OfferList offers={t.offers} onChanged={reload} />
        </aside>
      </div>
    </>
  )
}

/** /admin/support/requests/:id */
export function AdminSellerRequest() {
  const { id } = useParams()
  const { data, error, loading, reload } = useLive(`/admin/support/requests/${id}`)
  const r = data?.data
  // A proposal is sent from the inquiry it belongs to, so the offer form knows the inquiry's state.
  const ticket = useFetch(r?.support_ticket_id ? `/admin/support/tickets/${r.support_ticket_id}` : null)

  if (loading && !r) return <Spinner />
  if (error) return <ErrorState error={error} onRetry={reload} />
  if (!r) return null

  return (
    <div className="max-w-3xl">
      <Link to="/admin/support?tab=requests" className="text-sm text-brand-700 hover:underline">Back to store requests</Link>
      {r.ticket && (
        <p className="mt-3 text-sm text-stone-600">For inquiry <Link to={`/admin/support/${r.ticket.id}`} className="font-medium text-brand-700 hover:underline">{r.ticket.reference} {r.ticket.subject}</Link> <SupportStatusBadge status={r.ticket.status} /></p>
      )}
      <div className="mt-3">
        <RequestThread request={r} ticket={ticket.data?.data ?? null} onChanged={() => { reload(); ticket.reload() }} />
      </div>
    </div>
  )
}

/** /admin/support/old-chats/:id */
export function AdminOldChat() {
  const { id } = useParams()
  const { data, error, loading, reload } = useFetch(`/admin/support/old-chats/${id}`)
  const c = data?.data
  if (loading && !c) return <Spinner />
  if (error) return <ErrorState error={error} onRetry={reload} />
  if (!c) return null
  const quotes = Object.fromEntries(c.quotes.map((q) => [q.id, q]))

  return (
    <div className="max-w-3xl">
      <Link to="/admin/support?tab=old" className="text-sm text-brand-700 hover:underline">Back to old chats</Link>
      <header className="card mt-3 p-4 text-sm">
        <h1 className="text-lg font-bold text-stone-900">{c.subject}</h1>
        <p className="text-stone-600">
          <Link to={`/admin/users/${c.customer.id}`} className="text-brand-700 hover:underline">{c.customer.name}</Link> and {c.seller.store_name}
          {c.product && <> · <Link to={`/products/${c.product.slug}`} className="hover:underline">{c.product.name}</Link></>}
          {' '}· {c.status === 'closed' ? 'closed' : 'open'} · read-only
        </p>
      </header>
      <ol className="mt-4 space-y-3">
        {c.messages.map((m) => {
          const fromCustomer = m.sender_id === c.customer.id
          const q = m.quote_id && quotes[m.quote_id]
          return (
            <li key={m.id} className={`flex flex-col ${fromCustomer ? 'items-start' : 'items-end'}`}>
              <span className="mb-0.5 text-xs font-semibold text-stone-500">{fromCustomer ? c.customer.name : c.seller.store_name}</span>
              <div className={`max-w-[85%] rounded-2xl px-4 py-2 text-sm break-words whitespace-pre-line ${fromCustomer ? 'bg-white ring-1 ring-stone-200' : 'bg-stone-100'}`}>
                {m.body}
                {q && <span className="mt-1 block text-xs text-stone-600">Quote: {q.title} · {formatETB(q.total_santim)} · {QUOTE_STATUS_LABELS[q.status]?.replace('Waiting for your answer', 'waiting for the customer')}</span>}
              </div>
              <span className="mt-1 text-xs text-stone-400">{formatDateTime(m.created_at)}</span>
            </li>
          )
        })}
      </ol>
    </div>
  )
}
