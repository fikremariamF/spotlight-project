import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useConfig } from '../../../context/ConfigContext'
import { api } from '../../../lib/api'
import { newIdempotencyKey } from '../../../lib/format'
import { Badge, Err, PAY_METHODS, ReasonForm, birr, cleanBirr, money, toSantim, useSubmit } from './shared'

const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Addis_Ababa' })
const VOID = { voided: ['Voided', 'bg-stone-200 text-stone-700'] }

/**
 * Record what a supplier billed. `orders` are the purchase orders it can belong to; with `orderId`
 * the invoice is for that order only.
 */
export function InvoiceForm({ supplierId, orders = [], orderId = null, onDone, onCancel }) {
  const [v, setV] = useState({ purchase_order_id: orderId ?? '', invoice_number: '', invoice_date: today(), due_date: '', amount: '', note: '' })
  const { busy, errors, submit } = useSubmit()
  const set = (k) => (e) => setV({ ...v, [k]: e.target.value })
  const amount = toSantim(v.amount)

  async function save(e) {
    e.preventDefault()
    const body = await submit(() => api.post(`/seller/erp/suppliers/${supplierId}/invoices`, {
      purchase_order_id: v.purchase_order_id ? Number(v.purchase_order_id) : null, invoice_number: v.invoice_number.trim(),
      invoice_date: v.invoice_date || null, due_date: v.due_date || null, amount: cleanBirr(v.amount), note: v.note.trim() || null,
    }), 'Invoice recorded')
    if (body) onDone(body.data)
  }

  return (
    <form noValidate onSubmit={save} className="space-y-3 rounded-xl border border-stone-200 bg-stone-50 p-4" aria-label="Record a supplier invoice">
      <div className="grid gap-3 sm:grid-cols-2">
        {orderId === null && (
          <label className="text-sm text-stone-700" htmlFor="inv-po">For purchase order
            <select id="inv-po" className="input mt-1" value={v.purchase_order_id} onChange={set('purchase_order_id')}>
              <option value="">No order (a general bill)</option>
              {orders.map((o) => <option key={o.id} value={o.id}>{o.number}, {money(o.total_santim)}</option>)}
            </select>
          </label>
        )}
        <label className="text-sm text-stone-700" htmlFor="inv-number">Supplier's invoice number
          <input id="inv-number" className="input mt-1" maxLength={60} value={v.invoice_number} onChange={set('invoice_number')} aria-invalid={errors.invoice_number ? true : undefined} />
        </label>
        <label className="text-sm text-stone-700" htmlFor="inv-amount">Amount (ETB)
          <input id="inv-amount" className="input mt-1" inputMode="decimal" value={v.amount} onChange={set('amount')} aria-invalid={errors.amount ? true : undefined} />
        </label>
        <label className="text-sm text-stone-700" htmlFor="inv-date">Invoice date
          <input id="inv-date" type="date" className="input mt-1" value={v.invoice_date} max={today()} onChange={set('invoice_date')} />
        </label>
        <label className="text-sm text-stone-700" htmlFor="inv-due">Due date
          <input id="inv-due" type="date" className="input mt-1" value={v.due_date} min={v.invoice_date || undefined} onChange={set('due_date')} />
          <span className="mt-1 block text-xs text-stone-500">Left empty, it follows the supplier's payment days.</span>
        </label>
        <label className="text-sm text-stone-700 sm:col-span-2" htmlFor="inv-note">Note
          <input id="inv-note" className="input mt-1" maxLength={255} value={v.note} onChange={set('note')} placeholder="Optional" />
        </label>
      </div>
      <Err errors={errors} />
      <div className="flex justify-end gap-2">
        {onCancel && <button type="button" className="btn-ghost" onClick={onCancel} disabled={busy}>Close</button>}
        <button type="submit" className="btn-primary" disabled={busy || !v.invoice_number.trim() || !amount}>{busy ? 'Saving...' : 'Record invoice'}</button>
      </div>
    </form>
  )
}

/** Record money paid to a supplier. A repeated submit of the same form is saved once. */
export function PaymentForm({ supplierId, orders = [], orderId = null, suggested = null, onDone, onCancel }) {
  const [v, setV] = useState({ purchase_order_id: orderId ?? '', amount: suggested > 0 ? birr(suggested) : '', paid_on: today(), method: 'bank_transfer', reference: '', note: '' })
  const [requestId, setRequestId] = useState(newIdempotencyKey)
  const brand = useConfig().name
  const { busy, errors, submit } = useSubmit()
  const set = (k) => (e) => setV({ ...v, [k]: e.target.value })
  const amount = toSantim(v.amount)

  async function save(e) {
    e.preventDefault()
    const body = await submit(() => api.post(`/seller/erp/suppliers/${supplierId}/payments`, {
      purchase_order_id: v.purchase_order_id ? Number(v.purchase_order_id) : null, amount: cleanBirr(v.amount), paid_on: v.paid_on || null,
      method: v.method, reference: v.reference.trim() || null, note: v.note.trim() || null, request_id: requestId,
    }), 'Payment recorded')
    if (body) {
      setRequestId(newIdempotencyKey())
      onDone(body.data)
    }
  }

  return (
    <form noValidate onSubmit={save} className="space-y-3 rounded-xl border border-stone-200 bg-stone-50 p-4" aria-label="Record a payment to the supplier">
      <div className="grid gap-3 sm:grid-cols-2">
        {orderId === null && (
          <label className="text-sm text-stone-700" htmlFor="pay-po">For purchase order
            <select id="pay-po" className="input mt-1" value={v.purchase_order_id} onChange={set('purchase_order_id')}>
              <option value="">No order (towards the balance)</option>
              {orders.map((o) => <option key={o.id} value={o.id}>{o.number}, {o.balance_santim > 0 ? `${money(o.balance_santim)} left` : 'nothing left'}</option>)}
            </select>
          </label>
        )}
        <label className="text-sm text-stone-700" htmlFor="pay-amount">Amount (ETB)
          <input id="pay-amount" className="input mt-1" inputMode="decimal" value={v.amount} onChange={set('amount')} aria-invalid={errors.amount ? true : undefined} />
        </label>
        <label className="text-sm text-stone-700" htmlFor="pay-date">Paid on
          <input id="pay-date" type="date" className="input mt-1" value={v.paid_on} max={today()} onChange={set('paid_on')} />
        </label>
        <label className="text-sm text-stone-700" htmlFor="pay-method">How
          <select id="pay-method" className="input mt-1" value={v.method} onChange={set('method')}>
            {Object.entries(PAY_METHODS).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
          </select>
        </label>
        <label className="text-sm text-stone-700" htmlFor="pay-ref">Reference
          <input id="pay-ref" className="input mt-1" maxLength={100} value={v.reference} onChange={set('reference')} placeholder="Receipt or transaction number" />
        </label>
        <label className="text-sm text-stone-700" htmlFor="pay-note">Note
          <input id="pay-note" className="input mt-1" maxLength={255} value={v.note} onChange={set('note')} placeholder="Optional" />
        </label>
      </div>
      <p className="text-xs text-stone-500">This records a payment you already made. {brand} does not send money to suppliers.</p>
      <Err errors={errors} />
      <div className="flex justify-end gap-2">
        {onCancel && <button type="button" className="btn-ghost" onClick={onCancel} disabled={busy}>Close</button>}
        <button type="submit" className="btn-primary" disabled={busy || !amount}>{busy ? 'Saving...' : 'Record payment'}</button>
      </div>
    </form>
  )
}

/** Invoices with a void action. `showOrder` adds the purchase order column. */
export function InvoiceTable({ invoices, showOrder = true, readOnly, onChange }) {
  if (!invoices.length) return <p className="text-sm text-stone-500">No invoices recorded.</p>
  return (
    <div className="relative overflow-x-auto">
      <table className="rtable rtable-flush w-full text-left text-sm">
        <thead className="text-xs text-stone-500 uppercase">
          <tr><th className="py-2 pr-3">Invoice</th><th className="py-2 pr-3">Date</th><th className="py-2 pr-3">Due</th>{showOrder && <th className="py-2 pr-3">Order</th>}<th className="py-2 pr-3 text-right">Amount</th><th className="py-2"><span className="sr-only">Actions</span></th></tr>
        </thead>
        <tbody className="divide-y divide-stone-100">
          {invoices.map((i) => <VoidableRow key={i.id} kind="invoice" row={i} showOrder={showOrder} readOnly={readOnly} onChange={onChange} />)}
        </tbody>
      </table>
    </div>
  )
}

export function PaymentTable({ payments, showOrder = true, readOnly, onChange }) {
  if (!payments.length) return <p className="text-sm text-stone-500">No payments recorded.</p>
  return (
    <div className="relative overflow-x-auto">
      <table className="rtable rtable-flush w-full text-left text-sm">
        <thead className="text-xs text-stone-500 uppercase">
          <tr><th className="py-2 pr-3">Paid on</th><th className="py-2 pr-3">How</th><th className="py-2 pr-3">Reference</th>{showOrder && <th className="py-2 pr-3">Order</th>}<th className="py-2 pr-3 text-right">Amount</th><th className="py-2"><span className="sr-only">Actions</span></th></tr>
        </thead>
        <tbody className="divide-y divide-stone-100">
          {payments.map((p) => <VoidableRow key={p.id} kind="payment" row={p} showOrder={showOrder} readOnly={readOnly} onChange={onChange} />)}
        </tbody>
      </table>
    </div>
  )
}

function VoidableRow({ kind, row, showOrder, readOnly, onChange }) {
  const [voiding, setVoiding] = useState(false)
  const { busy, errors, submit } = useSubmit()
  const voided = row.status === 'voided'
  const url = kind === 'invoice' ? `/seller/erp/supplier-invoices/${row.id}/void` : `/seller/erp/supplier-payments/${row.id}/void`
  async function confirm(reason) {
    const body = await submit(() => api.post(url, { reason }), kind === 'invoice' ? 'Invoice voided' : 'Payment voided')
    if (body) {
      setVoiding(false)
      onChange?.()
    }
  }
  const order = row.purchase_order ? <Link to={`/seller/erp/purchases/${row.purchase_order.id}`} className="text-brand-700 hover:underline">{row.purchase_order.number}</Link> : <span className="text-stone-500">None</span>
  return (
    <>
      <tr className={voided ? 'text-stone-400' : ''}>
        {kind === 'invoice' ? (
          <>
            <td className="rtable-title py-2 pr-3">
              <span className={voided ? 'line-through' : ''}>{row.invoice_number}</span>{' '}
              {voided ? <Badge map={VOID} value="voided" /> : row.overdue && <span className="inline-flex rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-800">Overdue</span>}
              {(row.note || row.void_reason) && <span className="block text-xs font-normal text-stone-500">{voided ? `Voided: ${row.void_reason}` : row.note}</span>}
            </td>
            <td data-label="Date" className="py-2 pr-3 whitespace-nowrap">{row.invoice_date}</td>
            <td data-label="Due" className="py-2 pr-3 whitespace-nowrap">{row.due_date || 'Not set'}</td>
          </>
        ) : (
          <>
            <td className="rtable-title py-2 pr-3 whitespace-nowrap">
              {row.paid_on} {voided && <Badge map={VOID} value="voided" />}
              {(row.note || row.void_reason) && <span className="block text-xs font-normal text-stone-500">{voided ? `Voided: ${row.void_reason}` : row.note}</span>}
            </td>
            <td data-label="How" className="py-2 pr-3">{PAY_METHODS[row.method] || row.method}</td>
            <td data-label="Reference" className="py-2 pr-3">{row.reference || ''}</td>
          </>
        )}
        {showOrder && <td data-label="Order" className="py-2 pr-3">{order}</td>}
        <td data-label="Amount" className="py-2 pr-3 text-right font-semibold tabular-nums"><span className={voided ? 'line-through' : ''}>{money(row.amount_santim)}</span></td>
        <td className="py-2 text-right">{!voided && !readOnly && !voiding && <button type="button" className="btn-ghost px-2 py-1 text-xs" onClick={() => setVoiding(true)}>Void</button>}</td>
      </tr>
      {voiding && (
        <tr><td colSpan={showOrder ? 6 : 5} className="py-2">
          <ReasonForm label={kind === 'invoice' ? 'Why is this invoice wrong?' : 'Why is this payment wrong?'} button={kind === 'invoice' ? 'Void invoice' : 'Void payment'}
            hint="Voided records stay in your history, crossed out, and stop counting." busy={busy} errors={errors} onConfirm={confirm} onBack={() => setVoiding(false)} />
        </td></tr>
      )}
    </>
  )
}
