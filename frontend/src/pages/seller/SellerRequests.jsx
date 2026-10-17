import { useEffect, useRef, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { Alert, EmptyState, ErrorState, Field, PageHeader, Pagination, Spinner } from '../../components/ui'
import { useConfig } from '../../context/ConfigContext'
import { useInbox } from '../../context/InboxContext'
import { api, parseError } from '../../lib/api'
import { QUOTE_STATUS_LABELS, formatDateTime, formatETB, timeAgo, toQuery } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'

const TABS = [['active', 'Active'], ['unread', 'Unread'], ['closed', 'Closed']]
// As the store reads it: an open request is waiting for the store.
const STATUS = {
  open: ['Waiting for your answer', 'bg-amber-100 text-amber-800'],
  answered: ['Answered', 'bg-emerald-100 text-emerald-800'],
  closed: ['Closed', 'bg-stone-200 text-stone-700'],
}

function RequestStatus({ status }) {
  const [label, tone] = STATUS[status] || [status, 'bg-stone-100 text-stone-700']
  return <span className={`inline-flex shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold ${tone}`}>{label}</span>
}

/** Questions Betoch's team asks the store. Customers never see these, and the store never sees the customer. */
export function SellerRequests() {
  const [params, setParams] = useSearchParams()
  const tab = params.get('status') || 'active'
  const list = useFetch(`/seller/requests?${toQuery({ status: tab, page: params.get('page') || '' })}`)

  return (
    <>
      <PageHeader title="Admin requests"
        subtitle="Questions from the Betoch team about your listings and orders. Your answers go to Betoch only; the team decides what to tell the customer." />
      <div className="mb-4 flex gap-1 overflow-x-auto" role="tablist" aria-label="Filter requests">
        {TABS.map(([key, label]) => (
          <button key={key} type="button" role="tab" aria-selected={tab === key}
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
            {list.data.data.map((r) => (
              <li key={r.id}>
                <Link to={`/seller/requests/${r.id}`} className="block p-4 hover:bg-stone-50">
                  <div className="flex items-baseline justify-between gap-2">
                    <p className={`truncate ${r.unread ? 'font-bold text-stone-900' : 'font-medium text-stone-800'}`}>
                      {r.subject}
                      {r.unread && <span className="ml-2 inline-block size-2 rounded-full bg-brand-600 align-middle" aria-label="unread" />}
                    </p>
                    <span className="shrink-0 text-xs text-stone-500">{timeAgo(r.last_message_at)}</span>
                  </div>
                  <p className="truncate text-sm text-stone-600">
                    {r.topic_label}{r.product ? ` · ${r.product.name}` : ''}{r.order ? ` · Order ${r.order.number}` : ''}
                  </p>
                  <div className="mt-1"><RequestStatus status={r.status} /></div>
                </Link>
              </li>
            ))}
          </ul>
          <Pagination meta={list.data.meta} onPage={(p) => setParams({ status: tab, page: String(p) })} />
        </>
      ) : (
        <EmptyState title={tab === 'active' ? 'No open requests' : 'Nothing here'}>
          When a customer asks about your furniture, the Betoch team may check stock, details, prices or delivery with you here.
        </EmptyState>
      ))}
    </>
  )
}

function today(offsetDays = 0) {
  const d = new Date()
  d.setDate(d.getDate() + offsetDays)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const EMPTY_PROPOSAL = { price: '', quantity: 1, delivery_fee: '', production_days: '', valid_until: today(7) }

function ProposalFields({ value, onChange, errors }) {
  // While the marketplace prices delivery, a store can only offer it free.
  const storesSetFee = Boolean(useConfig().delivery?.stores_set_fee)
  const set = (key) => (e) => onChange({ ...value, [key]: e.target.value })
  return (
    <div className="grid gap-3 rounded-xl bg-stone-50 p-3 sm:grid-cols-2">
      <p className="text-xs text-stone-600 sm:col-span-2">
        Betoch checks your price and may send it to the customer as an offer. If they accept, they order it through checkout like any listing.
      </p>
      <Field label="Unit price (ETB)" name="p-price" error={errors.price}>
        <input id="p-price" className="input" inputMode="decimal" placeholder="e.g. 45000" value={value.price} onChange={set('price')} />
      </Field>
      <Field label="Quantity" name="p-qty" error={errors.quantity}>
        <input id="p-qty" type="number" min="1" max="1000" className="input" value={value.quantity} onChange={set('quantity')} />
      </Field>
      {storesSetFee ? (
        <Field label="Delivery fee (ETB, optional)" name="p-fee" error={errors.delivery_fee} hint="Leave empty to price delivery at checkout. Enter 0 for free delivery.">
          <input id="p-fee" className="input" inputMode="decimal" value={value.delivery_fee} onChange={set('delivery_fee')} />
        </Field>
      ) : (
        <div className="text-sm">
          <label className="flex items-center gap-2 pt-6">
            <input type="checkbox" className="size-4 accent-brand-700" checked={value.delivery_fee === '0'}
              onChange={(e) => onChange({ ...value, delivery_fee: e.target.checked ? '0' : '' })} />
            Free delivery
          </label>
          <p className="mt-1 text-xs text-stone-500">Otherwise the marketplace prices delivery at checkout.</p>
          {errors.delivery_fee && <p className="mt-1 text-xs text-red-700">{errors.delivery_fee}</p>}
        </div>
      )}
      <Field label="Production days (optional)" name="p-days" error={errors.production_days}>
        <input id="p-days" type="number" min="0" max="365" className="input" value={value.production_days} onChange={set('production_days')} />
      </Field>
      <Field label="Price valid until" name="p-valid" error={errors.valid_until}>
        <input id="p-valid" type="date" className="input" min={today()} max={today(60)} value={value.valid_until} onChange={set('valid_until')} />
      </Field>
    </div>
  )
}

function ProposalSummary({ p }) {
  return (
    <div className="mt-2 rounded-lg border border-stone-200 bg-white p-3 text-sm text-stone-800">
      <p className="font-semibold">
        Proposal: {formatETB(p.price_santim)}{p.quantity > 1 && ` x ${p.quantity}`} = {formatETB(p.total_santim)}
      </p>
      <p className="text-xs text-stone-600">
        Delivery {p.delivery_fee_santim === null ? 'priced at checkout' : p.delivery_fee_santim === 0 ? 'free' : formatETB(p.delivery_fee_santim)}
        {p.production_days !== null && ` · ready in ${p.production_days} days`} · valid until {p.valid_until}
      </p>
      <p className="mt-1 text-xs font-medium text-stone-600">
        {p.offer_status ? `Sent to the customer as an offer · ${QUOTE_STATUS_LABELS[p.offer_status]?.replace('Waiting for your answer', 'Waiting for the customer')}` : p.sent ? 'Sent to the customer' : 'Not sent to the customer yet'}
      </p>
    </div>
  )
}

export function SellerRequestView() {
  const { id } = useParams()
  const { refreshUnread } = useInbox()
  const { data, error, loading, reload } = useFetch(`/seller/requests/${id}`)
  const [body, setBody] = useState('')
  const [withProposal, setWithProposal] = useState(false)
  const [proposal, setProposal] = useState(EMPTY_PROPOSAL)
  const [errors, setErrors] = useState({})
  const [sendError, setSendError] = useState(null)
  const [sending, setSending] = useState(false)
  const bottom = useRef(null)

  const r = data?.data
  const count = r?.messages.length

  useEffect(() => {
    if (r) refreshUnread()
  }, [r?.id, count, refreshUnread]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    bottom.current?.scrollIntoView?.({ block: 'end' })
  }, [count])

  if (loading && !r) return <Spinner />
  if (error) return <ErrorState error={error} onRetry={reload} />
  if (!r) return null

  const closed = r.status === 'closed'

  async function send(e) {
    e.preventDefault()
    if (!body.trim()) {
      setErrors({ body: 'Write a message first.' })
      return
    }
    setSending(true)
    setErrors({})
    setSendError(null)
    try {
      await api.post(`/seller/requests/${r.id}/messages`, {
        body,
        proposal: withProposal ? {
          ...proposal,
          quantity: Number(proposal.quantity) || 1,
          delivery_fee: String(proposal.delivery_fee).trim() || null,
          production_days: proposal.production_days === '' ? null : Number(proposal.production_days),
        } : null,
      })
      setBody('')
      setWithProposal(false)
      setProposal(EMPTY_PROPOSAL)
      await reload()
    } catch (err) {
      const parsed = parseError(err)
      // Proposal errors come back as "proposal.price" and so on; show each under its own field.
      const fields = {}
      for (const [key, messages] of Object.entries(err.response?.data?.errors || {})) {
        fields[key.replace(/^proposal\./, '')] ??= messages[0]
      }
      setErrors(fields)
      setSendError(Object.values(fields)[0] || parsed.message)
    } finally {
      setSending(false)
    }
  }

  return (
    <div className="max-w-3xl">
      <Link to="/seller/requests" className="text-sm text-brand-700 hover:underline">Back to admin requests</Link>
      <header className="card mt-3 p-4">
        <div className="flex flex-wrap items-center gap-2">
          <h1 className="min-w-0 text-lg font-bold text-stone-900">{r.subject}</h1>
          <RequestStatus status={r.status} />
        </div>
        <p className="text-sm text-stone-600">{r.topic_label} · from the Betoch team · {formatDateTime(r.created_at)}</p>
        {r.product && <p className="text-sm text-stone-600">Listing: <Link to={`/seller/products/${r.product.id}/edit`} className="hover:underline">{r.product.name}</Link></p>}
        {r.order && <p className="text-sm text-stone-600">Order: <Link to={`/seller/orders/${r.order.id}`} className="hover:underline">{r.order.number}</Link></p>}
      </header>

      <ol className="mt-4 space-y-3" aria-label="Messages">
        {r.messages.map((m) => {
          if (m.from_role === 'system') {
            return <li key={m.id} className="text-center text-xs text-stone-500">{m.body} · {timeAgo(m.created_at)}</li>
          }
          const mine = m.from_role === 'seller'
          return (
            <li key={m.id} className={`flex flex-col ${mine ? 'items-end' : 'items-start'}`}>
              <span className="mb-0.5 text-xs font-semibold text-stone-500">{mine ? 'You' : 'Betoch Support'}</span>
              <div className={`max-w-[85%] rounded-2xl px-4 py-2 text-sm break-words whitespace-pre-line ${mine ? 'bg-brand-700 text-white' : 'bg-white text-stone-800 ring-1 ring-stone-200'}`}>
                {m.body}
              </div>
              {m.proposal && <div className="w-full max-w-md"><ProposalSummary p={m.proposal} /></div>}
              <span className="mt-1 text-xs text-stone-400">{formatDateTime(m.created_at)}</span>
            </li>
          )
        })}
      </ol>
      <div ref={bottom} />

      {sendError && <div className="mt-4"><Alert tone="error">{sendError}</Alert></div>}

      {closed ? (
        <div className="mt-4"><Alert>Betoch closed this request. Nothing more is needed from you.</Alert></div>
      ) : (
        <form onSubmit={send} className="card mt-4 space-y-3 p-4" noValidate>
          <Field label="Your answer to Betoch" name="answer" error={errors.body} hint="Only the Betoch team reads this. Do not include your phone number for the customer; Betoch handles the conversation.">
            <textarea id="answer" className="input min-h-24" maxLength={3000} value={body} onChange={(e) => setBody(e.target.value)} />
          </Field>
          <label className="flex items-center gap-2 text-sm font-medium text-stone-800">
            <input type="checkbox" className="size-4 accent-brand-700" checked={withProposal} onChange={(e) => setWithProposal(e.target.checked)} />
            Include a price proposal
          </label>
          {withProposal && <ProposalFields value={proposal} onChange={setProposal} errors={errors} />}
          <button type="submit" className="btn-primary" disabled={sending}>{sending ? 'Sending...' : 'Send to Betoch'}</button>
        </form>
      )}
    </div>
  )
}
