import { ArrowLeft, Printer } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ErrorState, PageHeader, Spinner, Stats } from '../../../components/ui'
import { api } from '../../../lib/api'
import { useFetch } from '../../../lib/useFetch'
import { ReturnForm, ReturnList, SalePaymentForm, SalePayments } from './SaleParts'
import { Badge, Err, ReasonForm, SALE_STATUS, money, placeName, useReadOnly, useSubmit } from './shared'

/** A sale the store made itself: what was sold, what the customer paid or still owes, and returns. */
export default function ErpSale() {
  const { id } = useParams()
  const readOnly = useReadOnly()
  const res = useFetch(`/seller/erp/sales/offline/${id}`)
  const sale = res.data?.data
  const [panel, setPanel] = useState(null)
  const { busy, errors, submit } = useSubmit((body) => { res.setData(body); setPanel(null) })

  if (res.loading && !sale) return <Spinner />
  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />

  const voided = sale.status === 'voided'
  const done = (body) => { res.setData(body); setPanel(null) }
  const recordedReturns = sale.returns.filter((r) => r.status === 'recorded').length
  const units = sale.items.reduce((n, i) => n + i.quantity, 0)
  const returnable = sale.items.some((i) => i.returnable > 0)

  return (
    <>
      <Link to="/seller/erp/sales" className="mb-3 inline-flex items-center gap-1 text-sm text-stone-600 hover:underline"><ArrowLeft className="size-4" aria-hidden="true" />Sales & invoices</Link>
      <PageHeader title={`Shop sale ${sale.number}`}
        subtitle={<span className="inline-flex flex-wrap items-center gap-2">
          <Badge map={SALE_STATUS} value={sale.status} />
          {sale.overdue && <span className="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-800">Payment late</span>}
          <span>{sale.date}{sale.customer.name ? `, ${sale.customer.name}` : ''}</span>
        </span>}
        actions={<>
          {!readOnly && !voided && !panel && sale.balance_santim > 0 && <button type="button" className="btn-primary" onClick={() => setPanel('payment')}>Record payment</button>}
          {!readOnly && !voided && !panel && returnable && <button type="button" className="btn-secondary" onClick={() => setPanel('return')}>Record a return</button>}
          {!readOnly && !voided && !panel && <button type="button" className="btn-ghost" onClick={() => setPanel('void')}>Void sale</button>}
          <Link to={`/seller/erp/sales/offline/${sale.id}/print`} target="_blank" rel="noopener" className="btn-ghost"><Printer className="size-4" aria-hidden="true" />{voided ? 'Print' : 'Print invoice'}</Link>
        </>} />

      {panel === 'void' && (
        <div className="mb-4">
          {recordedReturns > 0
            ? <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">A return is recorded for this sale. Undo the return first, then void the sale. <button type="button" className="font-semibold underline" onClick={() => setPanel(null)}>Close</button></p>
            : <ReasonForm label="Why is this sale wrong?" button="Void sale" busy={busy} errors={errors} onBack={() => setPanel(null)}
                hint="Items taken from stock go back to the location they left from, and its payments are voided too. The sale stays in your history, marked voided."
                onConfirm={(reason) => submit(() => api.post(`/seller/erp/sales/offline/${sale.id}/void`, { reason }), 'Sale voided')} />}
        </div>
      )}
      {panel === 'payment' && <div className="mb-5"><SalePaymentForm sale={sale} onDone={done} onCancel={() => setPanel(null)} /></div>}
      {panel === 'return' && <ReturnForm sale={sale} onDone={done} onCancel={() => setPanel(null)} />}

      {voided && <p className="mb-4 rounded-lg bg-stone-100 px-4 py-3 text-sm text-stone-700">Voided: {sale.void_reason}</p>}

      <Stats items={[
        { label: 'Sale total', value: money(sale.total_santim), hint: `${units} unit${units === 1 ? '' : 's'}` },
        sale.credited_santim > 0 && { label: 'Returned', value: money(sale.credited_santim) },
        { label: 'Paid', value: money(sale.paid_santim), hint: sale.refunded_santim > 0 ? `after ${money(sale.refunded_santim)} given back` : undefined },
        voided ? { label: 'Owed', value: money(0), hint: 'voided' }
          : sale.balance_santim < 0 ? { label: 'You owe the customer', value: money(-sale.balance_santim), tone: 'warn', hint: 'returns credited more than you gave back' }
            : { label: 'Customer still owes', value: money(sale.balance_santim), tone: sale.overdue ? 'bad' : sale.balance_santim > 0 ? 'warn' : undefined, hint: sale.due_date ? `pay by ${sale.due_date}` : undefined },
      ]} />

      <Details sale={sale} readOnly={readOnly || voided} onDone={(body) => res.setData(body)} />

      <section className="card mt-5 p-4">
        <h2 className="mb-3 font-semibold text-stone-900">Items</h2>
        <div className="relative overflow-x-auto">
          <table className="rtable rtable-flush w-full text-left text-sm">
            <thead className="text-xs text-stone-500 uppercase">
              <tr><th className="py-2 pr-3">Item</th><th className="py-2 pr-3 text-right">Quantity</th><th className="py-2 pr-3 text-right">Unit price</th><th className="py-2 pr-3 text-right">Discount</th><th className="py-2 text-right">Line total</th></tr>
            </thead>
            <tbody className="divide-y divide-stone-100">
              {sale.items.map((i) => (
                <tr key={i.id}>
                  <td className="rtable-title py-2 pr-3">
                    {i.product_id ? <Link to={`/seller/erp/inventory/${i.product_id}`} className="hover:underline">{i.description}</Link> : i.description}
                    {i.returned > 0 && <span className="block text-xs font-normal text-stone-500">{i.returned} returned</span>}
                    {!i.stock_item && i.product_id && <span className="block text-xs font-normal text-stone-500">No stock taken (made to order)</span>}
                  </td>
                  <td data-label="Quantity" className="py-2 pr-3 text-right tabular-nums">{i.quantity}</td>
                  <td data-label="Unit price" className="py-2 pr-3 text-right tabular-nums">{money(i.unit_price_santim)}</td>
                  <td data-label="Discount" className="py-2 pr-3 text-right tabular-nums">{i.discount_santim ? money(i.discount_santim) : ''}</td>
                  <td data-label="Line total" className="py-2 text-right font-semibold tabular-nums">{money(i.line_total_santim)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <dl className="mt-3 ml-auto max-w-xs space-y-1 text-sm">
          <div className="flex justify-between"><dt>Items</dt><dd className="tabular-nums">{money(sale.subtotal_santim)}</dd></div>
          {sale.discount_santim > 0 && <div className="flex justify-between"><dt>Discount</dt><dd className="tabular-nums">-{money(sale.discount_santim)}</dd></div>}
          {sale.delivery_santim > 0 && <div className="flex justify-between"><dt>Delivery</dt><dd className="tabular-nums">{money(sale.delivery_santim)}</dd></div>}
          <div className="flex justify-between border-t border-stone-200 pt-1 font-bold"><dt>Total</dt><dd className="tabular-nums">{money(sale.total_santim)}</dd></div>
        </dl>
      </section>

      <section className="card mt-5 p-4">
        <div className="mb-3 flex items-center justify-between gap-2">
          <h2 className="font-semibold text-stone-900">Payments from the customer</h2>
          {!readOnly && !voided && panel !== 'payment' && sale.balance_santim > 0 && <button type="button" className="btn-secondary" onClick={() => setPanel('payment')}>Record payment</button>}
        </div>
        <SalePayments payments={sale.payments} readOnly={readOnly || voided} onDone={(body) => res.setData(body)} />
      </section>

      <ReturnList returns={sale.returns} readOnly={readOnly || voided} onDone={(body) => res.setData(body)} />
      {!['void', 'payment', 'return'].includes(panel) && <Err errors={errors} />}
    </>
  )
}

/** Who bought, where it left from, the pay-by date and the note. The customer and dates can be corrected. */
function Details({ sale, readOnly, onDone }) {
  const [editing, setEditing] = useState(false)
  const initial = { customer_name: sale.customer.name || '', customer_phone: sale.customer_phone || '', customer_tin: sale.customer_tin || '', due_date: sale.due_date || '', note: sale.note || '' }
  const [v, setV] = useState(initial)
  const { busy, errors, submit } = useSubmit((body) => { onDone(body); setEditing(false) })
  const set = (k) => (e) => setV({ ...v, [k]: e.target.value })
  const fieldError = (k) => errors[k] && <span className="mt-1 block text-xs font-medium text-red-700">{errors[k]}</span>

  if (editing) {
    return (
      <form noValidate className="card mt-5 space-y-3 p-4" onSubmit={(e) => {
        e.preventDefault()
        submit(() => api.put(`/seller/erp/sales/offline/${sale.id}`, {
          customer_name: v.customer_name.trim() || null, customer_phone: v.customer_phone.trim() || null, customer_tin: v.customer_tin.trim() || null,
          due_date: v.due_date || null, note: v.note.trim() || null,
        }), 'Saved')
      }}>
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="text-sm text-stone-700" htmlFor="sd-name">Customer name<input id="sd-name" className="input mt-1" maxLength={100} value={v.customer_name} onChange={set('customer_name')} /></label>
          <label className="text-sm text-stone-700" htmlFor="sd-phone">Customer phone<input id="sd-phone" className="input mt-1" inputMode="tel" maxLength={20} value={v.customer_phone} onChange={set('customer_phone')} />{fieldError('customer_phone')}</label>
          <label className="text-sm text-stone-700" htmlFor="sd-tin">Customer TIN<input id="sd-tin" className="input mt-1" inputMode="numeric" maxLength={20} value={v.customer_tin} onChange={set('customer_tin')} />{fieldError('customer_tin')}</label>
          <label className="text-sm text-stone-700" htmlFor="sd-due">Pay by<input id="sd-due" type="date" className="input mt-1" min={sale.date} value={v.due_date} onChange={set('due_date')} />{fieldError('due_date')}</label>
        </div>
        <label className="block text-sm text-stone-700" htmlFor="sd-note">Note<textarea id="sd-note" className="input mt-1" rows={2} maxLength={500} value={v.note} onChange={set('note')} /></label>
        <p className="text-xs text-stone-500">Items, prices and payments cannot be changed here. If the sale itself is wrong, void it and record it again.</p>
        {errors.form || errors.status ? <p role="alert" className="text-sm font-medium text-red-700">{errors.form || errors.status}</p> : null}
        <div className="flex justify-end gap-2">
          <button type="button" className="btn-ghost" onClick={() => { setV(initial); setEditing(false) }} disabled={busy}>Cancel</button>
          <button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Saving...' : 'Save'}</button>
        </div>
      </form>
    )
  }
  return (
    <section className="card mt-5 p-4">
      <div className="mb-2 flex items-center justify-between gap-2"><h2 className="font-semibold text-stone-900">Details</h2>{!readOnly && <button type="button" className="btn-ghost px-2 py-1 text-sm" onClick={() => { setV(initial); setEditing(true) }}>Change customer or dates</button>}</div>
      <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
        <div><dt className="text-xs text-stone-500">Customer</dt><dd>{sale.customer.name || 'Walk-in customer'}</dd></div>
        <div><dt className="text-xs text-stone-500">Phone</dt><dd>{sale.customer_phone || 'None'}</dd></div>
        <div><dt className="text-xs text-stone-500">TIN</dt><dd>{sale.customer_tin || 'None'}</dd></div>
        <div><dt className="text-xs text-stone-500">Sold from</dt><dd>{sale.warehouse ? placeName(sale.warehouse) : 'Not set'}</dd></div>
        <div><dt className="text-xs text-stone-500">Sale date</dt><dd>{sale.date}</dd></div>
        <div><dt className="text-xs text-stone-500">Pay by</dt><dd className={sale.overdue ? 'font-semibold text-red-700' : ''}>{sale.due_date || 'Not set'}{sale.overdue && ' (late)'}</dd></div>
        {sale.by && <div><dt className="text-xs text-stone-500">Recorded by</dt><dd>{sale.by}</dd></div>}
        {sale.note && <div className="sm:col-span-2 lg:col-span-4"><dt className="text-xs text-stone-500">Note</dt><dd className="whitespace-pre-line">{sale.note}</dd></div>}
      </dl>
    </section>
  )
}
