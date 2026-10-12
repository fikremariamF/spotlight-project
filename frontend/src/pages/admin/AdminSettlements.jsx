import { useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { Alert, EmptyState, ErrorState, Field, PageHeader, Pagination, Spinner, StatusBadge } from '../../components/ui'
import { api, parseError } from '../../lib/api'
import { METHOD_LABELS, formatETB, toQuery } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'
import { BalanceCards, LedgerTable } from '../seller/SellerBalance'

export function AdminSettlements() {
  const [params, setParams] = useSearchParams()
  const owing = params.get('owing') === '1'
  const owed = params.get('owed') === '1'
  const q = params.get('q') || ''
  const [term, setTerm] = useState(q)
  const list = useFetch(`/admin/settlements?${toQuery({ owing: owing ? 1 : '', owed: owed ? 1 : '', q, page: params.get('page') || '' })}`)
  const query = (over) => toQuery({ owing: owing ? '1' : '', owed: owed ? '1' : '', q, ...over })

  return (
    <>
      <PageHeader title="Settlements" subtitle="Cash orders: stores owe the marketplace its commission; record their payments here. Online orders: the marketplace holds the money and owes stores the sale minus commission; record payouts here." />
      {list.data && (
        <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
          <div className="card p-4"><p className="text-sm text-stone-600">Outstanding</p><p className="text-2xl font-bold">{formatETB(list.data.meta.outstanding_santim)}</p></div>
          <div className="card p-4"><p className="text-sm text-stone-600">Collected so far</p><p className="text-2xl font-bold">{formatETB(list.data.meta.collected_santim)}</p></div>
          <div className="card p-4"><p className="text-sm text-stone-600">Owed to stores</p><p className="text-2xl font-bold">{formatETB(list.data.meta.payable_santim || 0) || 'ETB 0'}</p><p className="text-xs text-stone-500">Paid out {formatETB(list.data.meta.paid_out_santim || 0) || 'ETB 0'}</p></div>
          <div className="card p-4"><p className="text-sm text-stone-600">Credit limit</p><p className="text-2xl font-bold">{list.data.meta.credit_limit_santim ? formatETB(list.data.meta.credit_limit_santim) : 'Off'}</p><Link to="/admin/settings" className="text-xs text-brand-700 hover:underline">Change</Link></div>
        </div>
      )}
      <form className="mb-4 flex flex-wrap items-center gap-3" onSubmit={(e) => { e.preventDefault(); setParams(query({ q: term.trim() })) }}>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={owing} onChange={(e) => setParams(query({ owing: e.target.checked ? '1' : '', owed: '' }))} /> Only stores that owe</label>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={owed} onChange={(e) => setParams(query({ owed: e.target.checked ? '1' : '', owing: '' }))} /> Only stores we owe</label>
        <label htmlFor="settle-q" className="sr-only">Store name</label>
        <input id="settle-q" className="input w-56" placeholder="Store name" value={term} onChange={(e) => setTerm(e.target.value)} />
        <button type="submit" className="btn-secondary">Search</button>
      </form>
      {list.loading && !list.data ? <Spinner /> : list.error ? <ErrorState error={list.error} /> : list.data.data.length === 0 ? (
        <EmptyState title="No stores here">No store matches.</EmptyState>
      ) : (
        <>
          <ul className="card divide-y divide-stone-100">
            {list.data.data.map((s) => (
              <li key={s.id}>
                <Link to={`/admin/settlements/${s.id}`} className="flex items-center justify-between gap-3 p-4 hover:bg-stone-50">
                  <span className="min-w-0">
                    <span className="block truncate font-medium text-stone-900">{s.store_name}</span>
                    <span className="text-xs text-stone-500">{s.phone}</span>
                  </span>
                  <span className="flex shrink-0 items-center gap-2">
                    {s.over_limit && <span className="rounded bg-red-100 px-1.5 text-xs font-semibold text-red-800">Over limit</span>}
                    {s.status !== 'approved' && <StatusBadge status={s.status} />}
                    <span className={`font-semibold ${s.balance_santim < 0 ? 'text-emerald-700' : ''}`}>{s.balance_santim < 0 ? `We owe ${formatETB(-s.balance_santim)}` : formatETB(s.balance_santim)}</span>
                  </span>
                </Link>
              </li>
            ))}
          </ul>
          <Pagination meta={list.data.meta} onPage={(p) => setParams(query({ page: String(p) }))} />
        </>
      )}
    </>
  )
}

// Ways money moves between the marketplace and a store (Chapa is only for customer payments).
const LEDGER_METHODS = ['cash', 'bank_transfer', 'telebirr', 'other']

const FORMS = {
  payment: { title: 'Record a payment', button: 'Record payment', path: 'payments', done: 'Payment recorded.', hint: 'Money the store paid you.' },
  payout: { title: 'Record a payout', button: 'Record payout', path: 'payouts', done: 'Payout recorded.', hint: 'Money you sent the store for its online sales.' },
  adjustment: { title: 'Adjust the balance', button: 'Save adjustment', path: 'adjustments', done: 'Adjustment saved.', hint: 'Positive adds to what the store owes. Negative forgives part of it, e.g. -200.' },
}

function MoneyForm({ kind, sellerId, onDone }) {
  const f = FORMS[kind]
  const payment = kind !== 'adjustment'
  const [form, setForm] = useState({ amount: '', method: 'cash', reference: '', note: '' })
  const [errors, setErrors] = useState({})
  const [busy, setBusy] = useState(false)
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  async function submit(e) {
    e.preventDefault()
    const amount = form.amount.trim()
    const label = payment ? `${f.title} of ETB ${amount}?` : `Adjust the balance by ETB ${amount}? This cannot be edited later, only corrected with another adjustment.`
    if (!window.confirm(label)) return
    setBusy(true)
    setErrors({})
    try {
      const body = payment ? { ...form, amount } : { amount, note: form.note }
      const res = await api.post(`/admin/settlements/${sellerId}/${f.path}`, body)
      setForm({ amount: '', method: 'cash', reference: '', note: '' })
      onDone(res.data, f.done)
    } catch (err) {
      const p = parseError(err)
      setErrors({ ...p.fields, _: Object.keys(p.fields).length ? null : p.message })
    } finally {
      setBusy(false)
    }
  }

  const id = (k) => `${kind}-${k}`
  return (
    <form onSubmit={submit} className="card space-y-3 p-4" noValidate>
      <h2 className="font-semibold text-stone-900">{f.title}</h2>
      {errors._ && <Alert tone="error">{errors._}</Alert>}
      <Field label="Amount (ETB)" name={id('amount')} error={errors.amount} hint={f.hint}>
        <input id={id('amount')} className="input" inputMode="decimal" value={form.amount} onChange={set('amount')} />
      </Field>
      {payment && (
        <>
          <Field label="Method" name={id('method')} error={errors.method}>
            <select id={id('method')} className="input" value={form.method} onChange={set('method')}>
              {LEDGER_METHODS.map((k) => <option key={k} value={k}>{METHOD_LABELS[k]}</option>)}
            </select>
          </Field>
          <Field label={`Reference${['bank_transfer', 'telebirr'].includes(form.method) ? '' : ' (optional)'}`} name={id('reference')} error={errors.reference} hint="Transfer or transaction number. The same reference cannot be recorded twice.">
            <input id={id('reference')} className="input" maxLength={100} value={form.reference} onChange={set('reference')} />
          </Field>
        </>
      )}
      <Field label={payment ? 'Note (optional)' : 'Reason'} name={id('note')} error={errors.note}>
        <input id={id('note')} className="input" maxLength={500} value={form.note} onChange={set('note')} />
      </Field>
      <button type="submit" className={payment ? 'btn-primary' : 'btn-secondary'} disabled={busy}>{f.button}</button>
    </form>
  )
}

export function AdminSettlementDetail() {
  const { id } = useParams()
  const [page, setPage] = useState(1)
  const res = useFetch(`/admin/settlements/${id}?page=${page}`)
  const [notice, setNotice] = useState(null)

  if (res.loading && !res.data) return <Spinner />
  if (res.error) return <ErrorState error={res.error} />
  const d = res.data.data
  const entries = res.data.entries

  function done(data, text) {
    setPage(1)
    res.setData(data)
    setNotice(text)
  }

  return (
    <>
      <Link to="/admin/settlements" className="text-sm text-brand-700 hover:underline">Back to settlements</Link>
      <PageHeader title={d.seller.store_name} subtitle={<>Phone {d.seller.phone} · <Link to={`/sellers/${d.seller.slug}`} className="text-brand-700 hover:underline">storefront</Link></>} />
      {notice && <div className="mb-4"><Alert tone="success">{notice}</Alert></div>}
      <BalanceCards b={d} admin />
      <div className="mt-6 grid gap-6 lg:grid-cols-[1fr_320px]">
        <section className="card min-w-0 p-4">
          <h2 className="mb-2 font-semibold text-stone-900">Statement</h2>
          <LedgerTable entries={entries.data} admin />
          <Pagination meta={entries.meta} onPage={setPage} />
        </section>
        <div className="space-y-4">
          {d.owed_to_seller_santim > 0 && <MoneyForm kind="payout" sellerId={d.seller.id} onDone={done} />}
          {d.balance_santim > 0 && <MoneyForm kind="payment" sellerId={d.seller.id} onDone={done} />}
          <MoneyForm kind="adjustment" sellerId={d.seller.id} onDone={done} />
        </div>
      </div>
    </>
  )
}
