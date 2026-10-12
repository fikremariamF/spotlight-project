import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Alert, EmptyState, ErrorState, Field, PageHeader, Pagination, Spinner } from '../../components/ui'
import { api, parseError } from '../../lib/api'
import { METHOD_LABELS, formatDateTime, formatETB, toQuery } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'

const TABS = [['due', 'Refunds to send'], ['refunded', 'Refunded'], ['attempts', 'Payments']]
const REFUND_METHODS = ['chapa', 'telebirr', 'bank_transfer', 'cash', 'other']
const PAYMENT_TONES = { paid: 'bg-emerald-100 text-emerald-800', pending: 'bg-amber-100 text-amber-800', failed: 'bg-red-100 text-red-800', cancelled: 'bg-stone-200 text-stone-700', expired: 'bg-stone-200 text-stone-700' }

function RefundForm({ refund, onDone }) {
  const [form, setForm] = useState({ method: 'chapa', reference: '', note: '' })
  const [errors, setErrors] = useState({})
  const [busy, setBusy] = useState(false)
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const id = (k) => `refund-${refund.id}-${k}`

  async function submit(e) {
    e.preventDefault()
    if (!window.confirm(`Mark ${formatETB(refund.amount_santim)} as sent back to ${refund.customer.name}? Only do this after the money has actually been sent.`)) return
    setBusy(true)
    setErrors({})
    try {
      await api.post(`/admin/refunds/${refund.id}/complete`, form)
      onDone()
    } catch (err) {
      const p = parseError(err)
      setErrors({ ...p.fields, _: Object.keys(p.fields).length ? null : p.message })
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} className="mt-3 grid gap-3 rounded-lg bg-stone-50 p-3 sm:grid-cols-3" noValidate>
      {errors._ && <div className="sm:col-span-3"><Alert tone="error">{errors._}</Alert></div>}
      <Field label="Sent by" name={id('method')} error={errors.method}>
        <select id={id('method')} className="input" value={form.method} onChange={set('method')}>
          {REFUND_METHODS.map((m) => <option key={m} value={m}>{METHOD_LABELS[m]}</option>)}
        </select>
      </Field>
      <Field label={`Reference${form.method === 'cash' ? ' (optional)' : ''}`} name={id('reference')} error={errors.reference}>
        <input id={id('reference')} className="input" maxLength={100} value={form.reference} onChange={set('reference')} />
      </Field>
      <Field label="Note (optional)" name={id('note')} error={errors.note}>
        <input id={id('note')} className="input" maxLength={500} value={form.note} onChange={set('note')} />
      </Field>
      <div className="sm:col-span-3"><button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Saving...' : 'Mark as refunded'}</button></div>
    </form>
  )
}

function Refunds({ status }) {
  const [page, setPage] = useState(1)
  const list = useFetch(`/admin/refunds?${toQuery({ status, page })}`)
  const [open, setOpen] = useState(null)
  const [notice, setNotice] = useState(null)

  if (list.loading && !list.data) return <Spinner />
  if (list.error) return <ErrorState error={list.error} onRetry={list.reload} />
  const done = notice && <div className="mb-3"><Alert tone="success">{notice}</Alert></div>
  if (!list.data.data.length) {
    return <>{done}<EmptyState title={status === 'due' ? 'No refunds to send' : 'No refunds yet'}>{status === 'due' ? 'Refunds appear here when a paid order is cancelled or a payment arrives that no order needs.' : null}</EmptyState></>
  }

  return (
    <>
      {done}
      {status === 'due' && <p className="mb-3 text-sm text-stone-600">Send the money back in the Chapa dashboard (Transactions, Refund) or by transfer, then mark it here with the reference. The customer is told when you do.</p>}
      <ul className="card divide-y divide-stone-100">
        {list.data.data.map((r) => (
          <li key={r.id} className="p-4 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div className="min-w-0">
                <p className="font-semibold text-stone-900">{formatETB(r.amount_santim)} to {r.customer.name}</p>
                <p className="text-stone-600">{r.reason}{r.order && <> · <Link to={`/admin/orders/${r.order.id}`} className="text-brand-700 hover:underline">{r.order.number}</Link></>}</p>
                <p className="text-xs break-all text-stone-500">
                  {r.customer.phone && `${r.customer.phone} · `}{r.customer.email}
                  {r.payment && ` · paid with ${r.payment.method || 'Chapa'}, Chapa ref ${r.payment.provider_reference || '-'}, our ref ${r.payment.tx_ref}`}
                </p>
                {r.status === 'refunded' && <p className="mt-1 text-xs text-emerald-800">Refunded {formatDateTime(r.refunded_at)} by {r.refunded_by} · {METHOD_LABELS[r.method]}{r.reference && ` ${r.reference}`}{r.note && ` · ${r.note}`}</p>}
              </div>
              {r.status === 'due' && open !== r.id && <button type="button" className="btn-secondary" onClick={() => setOpen(r.id)}>Mark refunded</button>}
            </div>
            {open === r.id && <RefundForm refund={r} onDone={() => { setOpen(null); setNotice('Refund recorded. The customer has been told.'); list.reload() }} />}
          </li>
        ))}
      </ul>
      <Pagination meta={list.data.meta} onPage={setPage} />
    </>
  )
}

function Attempts() {
  const [page, setPage] = useState(1)
  const [q, setQ] = useState('')
  const [term, setTerm] = useState('')
  const list = useFetch(`/admin/payments?${toQuery({ q, page })}`)

  return (
    <>
      <form className="mb-3 flex flex-wrap gap-2" onSubmit={(e) => { e.preventDefault(); setPage(1); setQ(term.trim()) }}>
        <label htmlFor="pay-q" className="sr-only">Reference</label>
        <input id="pay-q" className="input w-64" placeholder="Our ref (BF-...) or Chapa ref" value={term} onChange={(e) => setTerm(e.target.value)} />
        <button type="submit" className="btn-secondary">Find</button>
      </form>
      {list.loading && !list.data ? <Spinner /> : list.error ? <ErrorState error={list.error} onRetry={list.reload} /> : !list.data.data.length ? <EmptyState title="No payments" /> : (
        <>
          <div className="card relative overflow-x-auto">
            <table className="w-full min-w-[720px] text-left text-sm">
              <thead className="text-xs text-stone-500 uppercase">
                <tr><th className="p-3 font-semibold">Started</th><th className="p-3 font-semibold">Customer</th><th className="p-3 font-semibold">Orders</th><th className="p-3 font-semibold">Status</th><th className="p-3 font-semibold">References</th><th className="p-3 text-right font-semibold">Amount</th></tr>
              </thead>
              <tbody className="divide-y divide-stone-100">
                {list.data.data.map((p) => (
                  <tr key={p.id} className="align-top">
                    <td className="p-3 whitespace-nowrap text-stone-600">{formatDateTime(p.created_at)}</td>
                    <td className="p-3">{p.customer.name}</td>
                    <td className="p-3">{p.orders.map((o) => <Link key={o.id} to={`/admin/orders/${o.id}`} className="mr-2 text-brand-700 hover:underline">{o.number}</Link>)}</td>
                    <td className="p-3">
                      <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${PAYMENT_TONES[p.status] || ''}`}>{p.status}</span>
                      {p.method && <span className="block text-xs text-stone-500">{p.method}</span>}
                      {p.problem && <span className="block max-w-56 text-xs text-red-700">{p.problem}</span>}
                    </td>
                    <td className="p-3 text-xs break-all text-stone-600">{p.tx_ref}{p.provider_reference && <span className="block">Chapa {p.provider_reference}</span>}</td>
                    <td className="p-3 text-right font-medium whitespace-nowrap">{formatETB(p.amount_santim)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination meta={list.data.meta} onPage={setPage} />
        </>
      )}
    </>
  )
}

export default function AdminPayments() {
  const [params, setParams] = useSearchParams()
  const tab = TABS.some(([k]) => k === params.get('tab')) ? params.get('tab') : 'due'
  const meta = useFetch('/admin/payments?page=1')
  const m = meta.data?.meta

  return (
    <>
      <PageHeader title="Online payments" subtitle="Customer payments through Chapa, and money to send back." />
      {m && !m.online_available && (
        <div className="mb-4"><Alert tone="warning">Online payment is off. {m.gateway_ready ? 'Turn it on in Settings.' : 'Add the Chapa keys on the server (see the README), then turn it on in Settings.'}</Alert></div>
      )}
      {m && m.gateway === 'fake' && <div className="mb-4"><Alert tone="info">Test payment page is on: no real money moves. Production always refuses it.</Alert></div>}
      <div className="mb-4 flex flex-wrap gap-2" role="tablist">
        {TABS.map(([k, label]) => (
          <button key={k} type="button" role="tab" aria-selected={tab === k} className={tab === k ? 'btn-primary' : 'btn-secondary'} onClick={() => setParams({ tab: k })}>
            {label}{k === 'due' && m?.refunds_due ? ` (${m.refunds_due})` : ''}
          </button>
        ))}
      </div>
      {tab === 'attempts' ? <Attempts /> : <Refunds key={tab} status={tab} />}
    </>
  )
}
