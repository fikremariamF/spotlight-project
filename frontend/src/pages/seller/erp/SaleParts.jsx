import { useState } from 'react'
import { useConfig } from '../../../context/ConfigContext'
import { api } from '../../../lib/api'
import { newIdempotencyKey } from '../../../lib/format'
import { useFetch } from '../../../lib/useFetch'
import { Badge, Err, PAY_METHODS, ReasonForm, SALE_STATUS, birr, cleanBirr, money, placeName, toSantim, useSubmit } from './shared'

export const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Addis_Ababa' })

/** round(total * part / of), half up, in whole santim, the way the server works out a return's credit. */
export function portion(total, part, of) {
  return Math.floor(total / of) * part + Math.floor((2 * (total % of) * part + of) / (2 * of))
}

/** Money the customer paid later for an offline sale. A repeated submit is saved once. */
export function SalePaymentForm({ sale, onDone, onCancel }) {
  const [v, setV] = useState({ amount: sale.balance_santim > 0 ? birr(sale.balance_santim) : '', paid_on: today(), method: 'cash', reference: '', note: '' })
  const [requestId, setRequestId] = useState(newIdempotencyKey)
  const { busy, errors, submit } = useSubmit()
  const set = (k) => (e) => setV({ ...v, [k]: e.target.value })
  const amount = toSantim(v.amount)

  async function save(e) {
    e.preventDefault()
    const body = await submit(() => api.post(`/seller/erp/sales/offline/${sale.id}/payments`, {
      amount: cleanBirr(v.amount), paid_on: v.paid_on || null, method: v.method,
      reference: v.reference.trim() || null, note: v.note.trim() || null, request_id: requestId,
    }), 'Payment recorded')
    if (body) {
      setRequestId(newIdempotencyKey())
      onDone(body)
    }
  }

  return (
    <form noValidate onSubmit={save} className="space-y-3 rounded-xl border border-stone-200 bg-stone-50 p-4" aria-label="Record a payment from the customer">
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="text-sm text-stone-700" htmlFor="sp-amount">Amount (ETB)
          <input id="sp-amount" className="input mt-1" inputMode="decimal" value={v.amount} onChange={set('amount')} aria-invalid={errors.amount ? true : undefined} />
          <span className="mt-1 block text-xs text-stone-500">Still owed: {money(sale.balance_santim)}</span>
        </label>
        <label className="text-sm text-stone-700" htmlFor="sp-date">Paid on
          <input id="sp-date" type="date" className="input mt-1" value={v.paid_on} min={sale.date} max={today()} onChange={set('paid_on')} />
        </label>
        <label className="text-sm text-stone-700" htmlFor="sp-method">How
          <select id="sp-method" className="input mt-1" value={v.method} onChange={set('method')}>
            {Object.entries(PAY_METHODS).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
          </select>
        </label>
        <label className="text-sm text-stone-700" htmlFor="sp-ref">Reference
          <input id="sp-ref" className="input mt-1" maxLength={100} value={v.reference} onChange={set('reference')} placeholder="Receipt or transaction number" />
        </label>
        <label className="text-sm text-stone-700 sm:col-span-2" htmlFor="sp-note">Note
          <input id="sp-note" className="input mt-1" maxLength={255} value={v.note} onChange={set('note')} placeholder="Optional" />
        </label>
      </div>
      <Err errors={errors} />
      <div className="flex justify-end gap-2">
        {onCancel && <button type="button" className="btn-ghost" onClick={onCancel} disabled={busy}>Close</button>}
        <button type="submit" className="btn-primary" disabled={busy || !amount}>{busy ? 'Saving...' : 'Record payment'}</button>
      </div>
    </form>
  )
}

/** Customer payments of an offline sale, each with a void action. */
export function SalePayments({ payments, readOnly, onDone }) {
  if (!payments.length) return <p className="text-sm text-stone-500">No payments recorded.</p>
  return (
    <div className="relative overflow-x-auto">
      <table className="rtable rtable-flush w-full text-left text-sm">
        <thead className="text-xs text-stone-500 uppercase">
          <tr><th className="py-2 pr-3">Paid on</th><th className="py-2 pr-3">How</th><th className="py-2 pr-3">Reference</th><th className="py-2 pr-3 text-right">Amount</th><th className="py-2"><span className="sr-only">Actions</span></th></tr>
        </thead>
        <tbody className="divide-y divide-stone-100">
          {payments.map((p) => <PaymentRow key={p.id} row={p} readOnly={readOnly} onDone={onDone} />)}
        </tbody>
      </table>
    </div>
  )
}

function PaymentRow({ row, readOnly, onDone }) {
  const [voiding, setVoiding] = useState(false)
  const { busy, errors, submit } = useSubmit()
  const voided = row.status === 'voided'
  async function confirm(reason) {
    const body = await submit(() => api.post(`/seller/erp/sale-payments/${row.id}/void`, { reason }), 'Payment voided')
    if (body) {
      setVoiding(false)
      onDone(body)
    }
  }
  return (
    <>
      <tr className={voided ? 'text-stone-400' : ''}>
        <td className="rtable-title py-2 pr-3 whitespace-nowrap">
          {row.paid_on} {voided && <Badge map={SALE_STATUS} value="voided" />}
          {(row.note || row.void_reason) && <span className="block text-xs font-normal whitespace-normal text-stone-500">{voided ? `Voided: ${row.void_reason}` : row.note}</span>}
        </td>
        <td data-label="How" className="py-2 pr-3">{PAY_METHODS[row.method] || row.method}</td>
        <td data-label="Reference" className="py-2 pr-3">{row.reference || ''}</td>
        <td data-label="Amount" className="py-2 pr-3 text-right font-semibold tabular-nums"><span className={voided ? 'line-through' : ''}>{money(row.amount_santim)}</span></td>
        <td className="py-2 text-right">{!voided && !readOnly && !voiding && <button type="button" className="btn-ghost px-2 py-1 text-xs" onClick={() => setVoiding(true)}>Void</button>}</td>
      </tr>
      {voiding && (
        <tr><td colSpan={5} className="py-2">
          <ReasonForm label="Why is this payment wrong?" button="Void payment" busy={busy} errors={errors} onConfirm={confirm} onBack={() => setVoiding(false)}
            hint="A voided payment stays in the history, crossed out, and the customer owes that amount again." />
        </td></tr>
      )}
    </>
  )
}

/**
 * Record goods a customer brought back. `sale` is an offline sale or a marketplace order as the
 * sales pages load it. The credit is worked out the way the server does, so the preview matches.
 */
export function ReturnForm({ sale, onDone, onCancel }) {
  const marketplace = sale.kind === 'marketplace'
  const brand = useConfig().name
  const lines = sale.items.filter((i) => i.returnable > 0)
  const [qty, setQty] = useState(() => Object.fromEntries(lines.map((i) => [i.id, ''])))
  const [restock, setRestock] = useState(() => Object.fromEntries(lines.map((i) => [i.id, i.stock_item])))
  const [v, setV] = useState({ returned_on: today(), warehouse_id: '', reason: '', delivery_credit: '', refund: '', refund_method: 'cash' })
  const [refundTouched, setRefundTouched] = useState(false)
  const [requestId] = useState(newIdempotencyKey)
  const { busy, errors, lines: lineErrors, submit } = useSubmit()
  const places = useFetch('/seller/erp/warehouses')
  const open = (places.data?.data || []).filter((w) => w.is_active)
  const set = (k) => (e) => setV({ ...v, [k]: e.target.value })

  const count = (i) => Math.min(i.returnable, Math.max(0, parseInt(qty[i.id], 10) || 0))
  const net = (i) => (marketplace ? i.line_total_santim : i.net_santim)
  const credit = lines.reduce((n, i) => n + portion(net(i), i.returned + count(i), i.quantity) - portion(net(i), i.returned, i.quantity), 0)
  const deliveryCredit = toSantim(v.delivery_credit) ?? 0
  const totalCredit = credit + deliveryCredit
  const online = marketplace && sale.payment_method === 'online'
  // What the customer paid beyond what they owe once this return is credited (offline), or the credit (cash order).
  const room = online ? 0 : marketplace ? totalCredit : Math.max(0, Math.min(totalCredit, sale.paid_santim - (sale.total_santim - sale.credited_santim - totalCredit)))
  const refund = refundTouched ? v.refund : (room > 0 ? birr(room) : '')
  const units = lines.reduce((n, i) => n + count(i), 0)
  const restocking = lines.some((i) => count(i) > 0 && restock[i.id])

  async function save(e) {
    e.preventDefault()
    const body = await submit(() => api.post(`/seller/erp/sales/${marketplace ? 'orders' : 'offline'}/${sale.id}/returns`, {
      items: lines.filter((i) => count(i) > 0).map((i) => ({ item_id: i.id, quantity: count(i), restock: !!restock[i.id] })),
      returned_on: v.returned_on || null, warehouse_id: restocking && v.warehouse_id ? Number(v.warehouse_id) : null, reason: v.reason.trim(),
      delivery_credit: cleanBirr(v.delivery_credit) || null, refund: online ? null : cleanBirr(refund) || null,
      refund_method: (toSantim(refund) || 0) > 0 ? v.refund_method : null, request_id: requestId,
    }), 'Return recorded')
    if (body) onDone(body)
  }

  return (
    <form noValidate onSubmit={save} className="card mb-5 space-y-4 p-4" aria-label="Record a return">
      <div>
        <h2 className="font-semibold text-stone-900">Record a return</h2>
        <p className="text-sm text-stone-600">{marketplace
          ? `For your own records: the order, its payment and your ${brand} commission do not change. Ask ${brand} support about a commission change or an online refund.`
          : 'The value of the items is taken off what the customer owes. Money you hand back is recorded as given back.'}</p>
      </div>
      <ul className="divide-y divide-stone-100 rounded-lg border border-stone-200">
        {lines.map((i, n) => (
          <li key={i.id} className="flex flex-wrap items-center gap-3 p-3">
            <span className="w-full min-w-0 text-sm sm:w-auto sm:flex-1">
              <span className="block font-medium text-stone-900">{i.description}</span>
              <span className="block text-xs text-stone-500">{i.quantity} sold{i.returned ? `, ${i.returned} already back` : ''}, {money(portion(net(i), 1, i.quantity))} each</span>
              {lineErrors[n] && <span role="alert" className="block text-xs font-medium text-red-700">{lineErrors[n]}</span>}
            </span>
            <label className="text-sm text-stone-700" htmlFor={`rt-q-${i.id}`}>How many
              <input id={`rt-q-${i.id}`} type="number" min={0} max={i.returnable} className="input mt-1 w-24" value={qty[i.id]} placeholder="0"
                onChange={(e) => setQty({ ...qty, [i.id]: e.target.value })} />
            </label>
            <label className={`flex items-center gap-2 text-sm ${i.stock_item ? 'text-stone-700' : 'text-stone-400'}`}>
              <input type="checkbox" checked={!!restock[i.id]} disabled={!i.stock_item} onChange={(e) => setRestock({ ...restock, [i.id]: e.target.checked })} />
              Back in stock
            </label>
          </li>
        ))}
      </ul>
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="text-sm text-stone-700" htmlFor="rt-date">Came back on
          <input id="rt-date" type="date" className="input mt-1" value={v.returned_on} min={sale.date} max={today()} onChange={set('returned_on')} />
        </label>
        {restocking && (
          <label className="text-sm text-stone-700" htmlFor="rt-place">Put back at
            <select id="rt-place" className="input mt-1" value={v.warehouse_id} onChange={set('warehouse_id')}>
              <option value="">{open.find((w) => w.is_default) ? placeName(open.find((w) => w.is_default)) : 'Your main location'}</option>
              {open.filter((w) => !w.is_default).map((w) => <option key={w.id} value={w.id}>{placeName(w)}</option>)}
            </select>
          </label>
        )}
        <label className="text-sm text-stone-700 sm:col-span-2" htmlFor="rt-reason">Why did it come back?
          <input id="rt-reason" className="input mt-1" maxLength={500} value={v.reason} onChange={set('reason')} placeholder="For example: wrong colour, scratched on delivery" aria-invalid={errors.reason ? true : undefined} />
        </label>
        {sale.delivery_credit_left_santim > 0 && (
          <label className="text-sm text-stone-700" htmlFor="rt-delivery">Also credit delivery (ETB)
            <input id="rt-delivery" className="input mt-1" inputMode="decimal" value={v.delivery_credit} onChange={set('delivery_credit')} placeholder="0" />
            <span className="mt-1 block text-xs text-stone-500">Up to {money(sale.delivery_credit_left_santim)}</span>
          </label>
        )}
        {online ? (
          <p className="rounded-lg bg-sky-50 p-3 text-sm text-sky-900 sm:col-span-2">This order was paid online, so {brand} support refunds the customer. Record the goods here; no money is given back by the store.</p>
        ) : (
          <>
            <label className="text-sm text-stone-700" htmlFor="rt-refund">Money given back (ETB)
              <input id="rt-refund" className="input mt-1" inputMode="decimal" value={refund} onChange={(e) => { setRefundTouched(true); setV({ ...v, refund: e.target.value }) }} placeholder="0" aria-invalid={errors.refund ? true : undefined} />
              <span className="mt-1 block text-xs text-stone-500">{room > 0 ? `Up to ${money(room)}` : marketplace ? 'Nothing to give back yet' : 'The customer has not paid more than they owe after this return.'}</span>
            </label>
            {(toSantim(refund) || 0) > 0 && (
              <label className="text-sm text-stone-700" htmlFor="rt-method">Given back by
                <select id="rt-method" className="input mt-1" value={v.refund_method} onChange={set('refund_method')}>
                  {Object.entries(PAY_METHODS).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
                </select>
              </label>
            )}
          </>
        )}
      </div>
      <p className="text-sm text-stone-700" aria-live="polite">{units > 0 ? `${units} unit${units === 1 ? '' : 's'} back, credit ${money(totalCredit)}.` : 'Enter how many came back.'}</p>
      <Err errors={errors} />
      <div className="flex justify-end gap-2">
        <button type="button" className="btn-ghost" onClick={onCancel} disabled={busy}>Close</button>
        <button type="submit" className="btn-primary" disabled={busy || units === 0 || v.reason.trim().length < 3}>{busy ? 'Saving...' : 'Record return'}</button>
      </div>
    </form>
  )
}

/** Returns of a sale or order, each with an undo (void) action. */
export function ReturnList({ returns, readOnly, onDone }) {
  if (!returns.length) return null
  return (
    <section className="card mt-5 p-4">
      <h2 className="mb-3 font-semibold text-stone-900">Returns</h2>
      <ul className="divide-y divide-stone-100 text-sm">
        {returns.map((r) => <ReturnRow key={r.id} row={r} readOnly={readOnly} onDone={onDone} />)}
      </ul>
    </section>
  )
}

function ReturnRow({ row, readOnly, onDone }) {
  const [voiding, setVoiding] = useState(false)
  const { busy, errors, submit } = useSubmit()
  const voided = row.status === 'voided'
  async function confirm(reason) {
    const body = await submit(() => api.post(`/seller/erp/sale-returns/${row.id}/void`, { reason }), 'Return undone')
    if (body) {
      setVoiding(false)
      onDone(body)
    }
  }
  return (
    <li className={`py-2 ${voided ? 'text-stone-400' : ''}`}>
      <p className="flex flex-wrap items-center justify-between gap-2">
        <span className="inline-flex items-center gap-2"><span className={`font-semibold ${voided ? 'line-through' : 'text-stone-900'}`}>{row.number}</span>{voided && <Badge map={SALE_STATUS} value="voided" />}</span>
        <span className="text-stone-600">{row.returned_on}{row.by && `, by ${row.by}`}</span>
      </p>
      <p className={voided ? '' : 'text-stone-700'}>{row.items.map((x) => `${x.quantity} x ${x.description}${x.restocked ? '' : ' (not restocked)'}`).join(', ')}</p>
      <p className={voided ? '' : 'text-stone-600'}>
        Credit {money(row.credit_santim)}{row.delivery_credit_santim > 0 && ` (with ${money(row.delivery_credit_santim)} delivery)`}
        {row.refund_santim > 0 && `, ${money(row.refund_santim)} given back by ${PAY_METHODS[row.refund_method] || row.refund_method}`}
        {row.items.some((x) => x.restocked) && row.warehouse && `, back in stock at ${row.warehouse}`}
      </p>
      <p className="text-xs text-stone-500">{voided ? `Undone: ${row.void_reason}` : row.reason}</p>
      {!voided && !readOnly && !voiding && <button type="button" className="btn-ghost mt-1 px-2 py-1 text-xs" onClick={() => setVoiding(true)}>Undo return</button>}
      {voiding && (
        <div className="mt-2">
          <ReasonForm label="Why is this return wrong?" button="Undo return" busy={busy} errors={errors} onConfirm={confirm} onBack={() => setVoiding(false)}
            hint="Units put back in stock come out again, and the credit and any money given back stop counting. The return stays in the history, crossed out." />
        </div>
      )}
    </li>
  )
}
