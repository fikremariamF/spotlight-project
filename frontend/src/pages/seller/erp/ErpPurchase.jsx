import { ArrowLeft, Printer } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ErrorState, PageHeader, Spinner, Stats } from '../../../components/ui'
import { api } from '../../../lib/api'
import { newIdempotencyKey } from '../../../lib/format'
import { useFetch } from '../../../lib/useFetch'
import { Badge, Err, PO_STATUS, ReasonForm, money, placeName, useReadOnly, useSubmit } from './shared'
import { InvoiceForm, InvoiceTable, PaymentForm, PaymentTable } from './SupplierMoney'

const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Addis_Ababa' })

export default function ErpPurchase() {
  const { id } = useParams()
  const readOnly = useReadOnly()
  const res = useFetch(`/seller/erp/purchases/${id}`)
  const po = res.data?.data
  const [panel, setPanel] = useState(null)
  const { busy, errors, submit } = useSubmit((body) => { res.setData(body); setPanel(null) })

  if (res.loading && !po) return <Spinner />
  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />

  const open = ['ordered', 'partially_received'].includes(po.status)
  const billable = ['ordered', 'partially_received', 'received'].includes(po.status)
  const canCancel = ['draft', 'ordered'].includes(po.status)
  const done = () => { setPanel(null); res.reload() }
  const orderedUnits = po.items.reduce((n, i) => n + i.quantity, 0)
  const receivedUnits = po.items.reduce((n, i) => n + i.received_quantity, 0)

  return (
    <>
      <Link to="/seller/erp/purchases" className="mb-3 inline-flex items-center gap-1 text-sm text-stone-600 hover:underline"><ArrowLeft className="size-4" aria-hidden="true" />Purchases</Link>
      <PageHeader title={`Purchase order ${po.number}`}
        subtitle={<span className="inline-flex flex-wrap items-center gap-2">
          <Badge map={PO_STATUS} value={po.status} late={po.late} />
          {po.closed_short && <span className="rounded-full bg-stone-200 px-2 py-0.5 text-xs font-semibold text-stone-700">Closed short</span>}
          <Link to={`/seller/erp/suppliers/${po.supplier.id}`} className="font-medium text-stone-800 hover:underline">{po.supplier.name}</Link>
        </span>}
        actions={<>
          {!readOnly && !panel && po.status === 'draft' && <>
            <button type="button" className="btn-primary" onClick={() => setPanel('order')}>Mark as ordered</button>
            <Link to={`/seller/erp/purchases/${po.id}/edit`} className="btn-secondary">Edit</Link>
          </>}
          {!readOnly && !panel && open && <button type="button" className="btn-primary" onClick={() => setPanel('receive')}>Receive goods</button>}
          {!readOnly && !panel && po.status === 'partially_received' && <button type="button" className="btn-ghost" onClick={() => setPanel('close')}>Stop waiting for the rest</button>}
          {!readOnly && !panel && canCancel && <button type="button" className="btn-ghost" onClick={() => setPanel('cancel')}>Cancel order</button>}
          <Link to={`/seller/erp/purchases/${po.id}/print`} target="_blank" rel="noopener" className="btn-ghost"><Printer className="size-4" aria-hidden="true" />Print</Link>
        </>} />

      {panel === 'order' && (
        <div className="mb-4 space-y-2 rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900" role="alertdialog" aria-label="Mark as ordered">
          <p>Mark {po.number} as sent to {po.supplier.name}? After this its items and prices are fixed; you can still change the expected date, reference and notes. Nothing changes in your stock until you receive the goods.</p>
          <Err errors={errors} />
          <div className="flex justify-end gap-2">
            <button type="button" className="btn-ghost" onClick={() => setPanel(null)} disabled={busy}>Back</button>
            <button type="button" className="btn-primary" disabled={busy} onClick={() => submit(() => api.post(`/seller/erp/purchases/${po.id}/order`), 'Marked as ordered')}>{busy ? 'Saving...' : 'Mark as ordered'}</button>
          </div>
        </div>
      )}
      {panel === 'cancel' && (
        <div className="mb-4">
          <ReasonForm label="Why is this order cancelled?" button="Cancel order" busy={busy} errors={errors} onBack={() => setPanel(null)}
            hint={po.status === 'ordered' ? 'Tell the supplier too. A cancelled order stays in your history.' : 'A cancelled draft stays in your history.'}
            onConfirm={(reason) => submit(() => api.post(`/seller/erp/purchases/${po.id}/cancel`, { reason }), 'Order cancelled')} />
        </div>
      )}
      {panel === 'close' && (
        <div className="mb-4">
          <ReasonForm label="Why is the rest not coming?" button="Stop waiting" tone="primary" busy={busy} errors={errors} onBack={() => setPanel(null)}
            hint={`${receivedUnits} of ${orderedUnits} units arrived. The order counts as received; what you pay is the invoice, or the value of what arrived.`}
            onConfirm={(reason) => submit(() => api.post(`/seller/erp/purchases/${po.id}/close`, { reason }), 'Order closed')} />
        </div>
      )}
      {!['cancel', 'close', 'order', 'receive'].includes(panel) && <Err errors={errors} />}

      {panel === 'receive' && <ReceiveForm po={po} onDone={(body) => { res.setData(body); setPanel(null) }} onBack={() => setPanel(null)} />}

      {(po.cancel_reason && po.status === 'cancelled') && <p className="mb-4 rounded-lg bg-stone-100 px-4 py-3 text-sm text-stone-700">Cancelled: {po.cancel_reason}</p>}
      {(po.cancel_reason && po.closed_short) && <p className="mb-4 rounded-lg bg-stone-100 px-4 py-3 text-sm text-stone-700">Stopped waiting for the rest: {po.cancel_reason}</p>}

      <Stats items={[
        { label: 'Order total', value: money(po.total_santim), hint: `${orderedUnits} units` },
        { label: 'Received', value: money(po.received_value_santim), hint: `${receivedUnits} of ${orderedUnits} units` },
        { label: 'Invoiced', value: money(po.invoiced_santim) },
        { label: 'Paid', value: money(po.paid_santim) },
        { label: po.balance_santim < 0 ? 'Paid ahead' : 'Still to pay', value: money(Math.abs(po.balance_santim)), tone: po.balance_santim > 0 ? 'warn' : undefined, hint: po.invoiced_santim ? 'invoices minus payments' : 'value received minus payments' },
      ]} />

      <Details po={po} readOnly={readOnly} onDone={(body) => res.setData(body)} />

      <section className="card mt-5 p-4">
        <h2 className="mb-3 font-semibold text-stone-900">Items</h2>
        <div className="relative overflow-x-auto">
          <table className="rtable rtable-flush w-full text-left text-sm">
            <thead className="text-xs text-stone-500 uppercase">
              <tr><th className="py-2 pr-3">Item</th><th className="py-2 pr-3 text-right">Ordered</th><th className="py-2 pr-3 text-right">Received</th><th className="py-2 pr-3 text-right">Unit cost</th><th className="py-2 pr-3 text-right">Discount</th><th className="py-2 pr-3 text-right">Line total</th><th className="py-2 text-right">Cost per unit with charges</th></tr>
            </thead>
            <tbody className="divide-y divide-stone-100">
              {po.items.map((i) => (
                <tr key={i.id}>
                  <td className="rtable-title py-2 pr-3"><Link to={`/seller/erp/inventory/${i.product_id}`} className="hover:underline">{i.description}</Link>
                    {i.outstanding > 0 && <span className="block text-xs font-normal text-amber-800">{i.outstanding} still expected</span>}</td>
                  <td data-label="Ordered" className="py-2 pr-3 text-right tabular-nums">{i.quantity}</td>
                  <td data-label="Received" className="py-2 pr-3 text-right tabular-nums">{i.received_quantity}</td>
                  <td data-label="Unit cost" className="py-2 pr-3 text-right tabular-nums">{money(i.unit_cost_santim)}</td>
                  <td data-label="Discount" className="py-2 pr-3 text-right tabular-nums">{i.discount_santim ? money(i.discount_santim) : ''}</td>
                  <td data-label="Line total" className="py-2 pr-3 text-right font-semibold tabular-nums">{money(i.line_total_santim)}</td>
                  <td data-label="Cost per unit with charges" className="py-2 text-right tabular-nums">{money(i.landed_unit_cost_santim)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <dl className="mt-3 ml-auto max-w-xs space-y-1 text-sm">
          <div className="flex justify-between"><dt>Items</dt><dd className="tabular-nums">{money(po.subtotal_santim)}</dd></div>
          {po.discount_santim > 0 && <div className="flex justify-between"><dt>Discount</dt><dd className="tabular-nums">-{money(po.discount_santim)}</dd></div>}
          {po.charges_santim > 0 && <div className="flex justify-between"><dt>Charges{po.charges_note && <span className="text-stone-500"> ({po.charges_note})</span>}</dt><dd className="tabular-nums">{money(po.charges_santim)}</dd></div>}
          <div className="flex justify-between border-t border-stone-200 pt-1 font-bold"><dt>Total</dt><dd className="tabular-nums">{money(po.total_santim)}</dd></div>
        </dl>
      </section>

      {po.receipts.length > 0 && (
        <section className="card mt-5 p-4">
          <h2 className="mb-3 font-semibold text-stone-900">Deliveries received</h2>
          <ul className="divide-y divide-stone-100 text-sm">
            {po.receipts.map((r) => (
              <li key={r.id} className="py-2">
                <p className="flex flex-wrap justify-between gap-2"><span className="font-semibold text-stone-900">{r.number}</span><span className="text-stone-600">{r.received_on} at {r.warehouse}{r.by && `, by ${r.by}`}</span></p>
                <p className="text-stone-600">{r.items.map((x) => `${x.quantity} x ${x.description}`).join(', ')}</p>
                {r.note && <p className="text-xs text-stone-500">{r.note}</p>}
              </li>
            ))}
          </ul>
        </section>
      )}

      {billable && (
        <div className="mt-5 grid grid-cols-[minmax(0,1fr)] gap-5 xl:grid-cols-2">
          <section className="card p-4">
            <div className="mb-3 flex items-center justify-between gap-2">
              <h2 className="font-semibold text-stone-900">Supplier invoices</h2>
              {!readOnly && panel !== 'invoice' && <button type="button" className="btn-secondary" onClick={() => setPanel('invoice')}>Record invoice</button>}
            </div>
            {panel === 'invoice' && <div className="mb-3"><InvoiceForm supplierId={po.supplier.id} orderId={po.id} onDone={done} onCancel={() => setPanel(null)} /></div>}
            <InvoiceTable invoices={po.invoices} showOrder={false} readOnly={readOnly} onChange={res.reload} />
          </section>
          <section className="card p-4">
            <div className="mb-3 flex items-center justify-between gap-2">
              <h2 className="font-semibold text-stone-900">Payments</h2>
              {!readOnly && panel !== 'payment' && <button type="button" className="btn-secondary" onClick={() => setPanel('payment')}>Record payment</button>}
            </div>
            {panel === 'payment' && <div className="mb-3"><PaymentForm supplierId={po.supplier.id} orderId={po.id} suggested={po.balance_santim} onDone={done} onCancel={() => setPanel(null)} /></div>}
            <PaymentTable payments={po.payments} showOrder={false} readOnly={readOnly} onChange={res.reload} />
          </section>
        </div>
      )}
    </>
  )
}

/** Where it goes, dates and notes. A sent order can still change its expected date, reference and notes. */
function Details({ po, readOnly, onDone }) {
  const [editing, setEditing] = useState(false)
  const [v, setV] = useState({ expected_date: po.expected_date || '', supplier_reference: po.supplier_reference || '', notes: po.notes || '' })
  const { busy, errors, submit } = useSubmit((body) => { onDone(body); setEditing(false) })
  const editable = !readOnly && ['ordered', 'partially_received'].includes(po.status)

  if (editing) {
    return (
      <form noValidate className="card mt-5 space-y-3 p-4" onSubmit={(e) => {
        e.preventDefault()
        submit(() => api.put(`/seller/erp/purchases/${po.id}`, { expected_date: v.expected_date || null, supplier_reference: v.supplier_reference.trim() || null, notes: v.notes.trim() || null }), 'Saved')
      }}>
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="text-sm text-stone-700" htmlFor="pod-exp">Expected delivery<input id="pod-exp" type="date" className="input mt-1" min={po.order_date} value={v.expected_date} onChange={(e) => setV({ ...v, expected_date: e.target.value })} /></label>
          <label className="text-sm text-stone-700" htmlFor="pod-ref">Supplier's reference<input id="pod-ref" className="input mt-1" maxLength={60} value={v.supplier_reference} onChange={(e) => setV({ ...v, supplier_reference: e.target.value })} /></label>
        </div>
        <label className="block text-sm text-stone-700" htmlFor="pod-notes">Notes<textarea id="pod-notes" className="input mt-1" rows={3} maxLength={1000} value={v.notes} onChange={(e) => setV({ ...v, notes: e.target.value })} /></label>
        <Err errors={errors} />
        <div className="flex justify-end gap-2">
          <button type="button" className="btn-ghost" onClick={() => setEditing(false)} disabled={busy}>Cancel</button>
          <button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Saving...' : 'Save'}</button>
        </div>
      </form>
    )
  }
  return (
    <section className="card mt-5 p-4">
      <div className="mb-2 flex items-center justify-between"><h2 className="font-semibold text-stone-900">Details</h2>{editable && <button type="button" className="btn-ghost px-2 py-1 text-sm" onClick={() => setEditing(true)}>Change dates or notes</button>}</div>
      <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
        <div><dt className="text-xs text-stone-500">Deliver to</dt><dd>{placeName(po.warehouse)}</dd></div>
        <div><dt className="text-xs text-stone-500">Order date</dt><dd>{po.order_date}</dd></div>
        <div><dt className="text-xs text-stone-500">Expected</dt><dd className={po.late ? 'font-semibold text-red-700' : ''}>{po.expected_date || 'Not set'}{po.late && ' (late)'}</dd></div>
        <div><dt className="text-xs text-stone-500">Supplier's reference</dt><dd>{po.supplier_reference || 'None'}</dd></div>
        {po.by && <div><dt className="text-xs text-stone-500">Created by</dt><dd>{po.by}</dd></div>}
        {po.notes && <div className="sm:col-span-2 lg:col-span-3"><dt className="text-xs text-stone-500">Notes</dt><dd className="whitespace-pre-line">{po.notes}</dd></div>}
      </dl>
    </section>
  )
}

/** Record a delivery. Each submit carries its own id, so a double tap or a retry is saved once. */
function ReceiveForm({ po, onDone, onBack }) {
  const places = useFetch('/seller/erp/warehouses')
  const lines = po.items.filter((i) => i.outstanding > 0)
  const [qty, setQty] = useState(() => Object.fromEntries(lines.map((i) => [i.id, String(i.outstanding)])))
  const [v, setV] = useState({ warehouse_id: String(po.warehouse.is_active ? po.warehouse.id : ''), received_on: today(), note: '' })
  const [requestId] = useState(newIdempotencyKey)
  const [confirm, setConfirm] = useState(false)
  const { busy, errors, lines: lineErrors, submit } = useSubmit(onDone)
  const open = (places.data?.data || []).filter((w) => w.is_active)
  const warehouseId = v.warehouse_id || String(open.find((w) => w.is_default)?.id ?? open[0]?.id ?? '')

  const ok = (i) => /^\d+$/.test(qty[i.id] ?? '') && Number(qty[i.id]) <= i.outstanding
  const valid = lines.every(ok) && lines.some((i) => Number(qty[i.id]) > 0)
  const units = lines.reduce((n, i) => n + (ok(i) ? Number(qty[i.id]) : 0), 0)
  const value = lines.reduce((n, i) => n + (ok(i) ? Number(qty[i.id]) * i.landed_unit_cost_santim : 0), 0)
  const sent = lines.filter((i) => Number(qty[i.id]) > 0)

  const send = () => submit(() => api.post(`/seller/erp/purchases/${po.id}/receive`, {
    warehouse_id: warehouseId ? Number(warehouseId) : null, received_on: v.received_on || null, note: v.note.trim() || null, request_id: requestId,
    items: sent.map((i) => ({ item_id: i.id, quantity: Number(qty[i.id]) })),
  }), 'Goods received, stock updated').then((body) => { if (!body) setConfirm(false) })

  return (
    <form noValidate className="card mb-5 space-y-3 border-brand-200 p-4" onSubmit={(e) => { e.preventDefault(); if (valid) setConfirm(true) }} aria-label="Receive goods">
      <h2 className="font-semibold text-stone-900">Receive goods</h2>
      <p className="text-sm text-stone-600">Count what arrived. Only what you enter here is added to stock.</p>
      <ul className="divide-y divide-stone-100 rounded-lg border border-stone-200">
        {lines.map((i) => (
          <li key={i.id} className="flex flex-wrap items-center gap-3 px-3 py-2">
            <span className="min-w-0 flex-1 text-sm">
              <span className="block font-medium text-stone-900">{i.description}</span>
              <span className="text-xs text-stone-500">{i.outstanding} still expected{i.received_quantity > 0 && `, ${i.received_quantity} already received`}</span>
              {lineErrors[sent.indexOf(i)] && <span role="alert" className="block text-xs font-medium text-red-700">{lineErrors[sent.indexOf(i)]}</span>}
            </span>
            <label className="text-xs text-stone-600" htmlFor={`rcv-${i.id}`}>Arrived
              <input id={`rcv-${i.id}`} className="input mt-0.5 w-24" inputMode="numeric" value={qty[i.id] ?? ''} aria-invalid={ok(i) ? undefined : true}
                onChange={(e) => { setQty({ ...qty, [i.id]: e.target.value.trim() }); setConfirm(false) }} />
            </label>
          </li>
        ))}
      </ul>
      <div className="flex flex-wrap gap-2 text-sm">
        <button type="button" className="btn-ghost px-2 py-1" onClick={() => { setQty(Object.fromEntries(lines.map((i) => [i.id, String(i.outstanding)]))); setConfirm(false) }}>Everything expected</button>
        <button type="button" className="btn-ghost px-2 py-1" onClick={() => { setQty(Object.fromEntries(lines.map((i) => [i.id, '0']))); setConfirm(false) }}>Clear all</button>
      </div>
      <div className="grid gap-3 sm:grid-cols-3">
        <label className="text-sm text-stone-700" htmlFor="rcv-wh">Received at
          <select id="rcv-wh" className="input mt-1" value={warehouseId} onChange={(e) => { setV({ ...v, warehouse_id: e.target.value }); setConfirm(false) }}>
            {open.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
          </select>
        </label>
        <label className="text-sm text-stone-700" htmlFor="rcv-date">Date
          <input id="rcv-date" type="date" className="input mt-1" min={po.order_date} max={today()} value={v.received_on} onChange={(e) => setV({ ...v, received_on: e.target.value })} />
        </label>
        <label className="text-sm text-stone-700" htmlFor="rcv-note">Note
          <input id="rcv-note" className="input mt-1" maxLength={255} value={v.note} onChange={(e) => setV({ ...v, note: e.target.value })} placeholder="Delivery note number, driver" />
        </label>
      </div>
      {lines.some((i) => !ok(i)) && <p className="text-xs font-medium text-red-700">Each number must be between 0 and what is still expected.</p>}
      <Err errors={errors} />
      {confirm ? (
        <div className="flex flex-wrap items-center justify-end gap-2 rounded-lg bg-amber-50 p-2 text-sm" role="alertdialog" aria-label="Confirm received goods">
          <span className="text-amber-900">Add {units} {units === 1 ? 'unit' : 'units'} ({money(value)} at cost) to {open.find((w) => String(w.id) === warehouseId)?.name}?</span>
          <button type="button" className="btn-ghost" onClick={() => setConfirm(false)} disabled={busy}>Back</button>
          <button type="button" className="btn-primary" onClick={send} disabled={busy} autoFocus>{busy ? 'Saving...' : 'Confirm'}</button>
        </div>
      ) : (
        <div className="flex justify-end gap-2">
          <button type="button" className="btn-ghost" onClick={onBack}>Cancel</button>
          <button type="submit" className="btn-primary" disabled={!valid || !warehouseId}>Receive</button>
        </div>
      )}
    </form>
  )
}
