import { ArrowLeft, X } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { ErrorState, PageHeader, Spinner } from '../../../components/ui'
import { api } from '../../../lib/api'
import { newIdempotencyKey } from '../../../lib/format'
import { useFetch } from '../../../lib/useFetch'
import { today } from './SaleParts'
import { ItemPicker, PAY_METHODS, birr, cleanBirr, itemKey, itemName, money, placeName, toSantim, useReadOnly, useSubmit } from './shared'

const MAX_LINES = 100

/**
 * Record a sale made outside the marketplace: at the shop, by phone, to a business. In-stock items
 * come out of the chosen location when it is saved.
 */
export default function ErpSaleNew() {
  const places = useFetch('/seller/erp/warehouses')
  if (places.error) return <ErrorState error={places.error} onRetry={places.reload} />
  if (!places.data) return <Spinner />
  return <SaleForm places={places.data.data.filter((w) => w.is_active)} />
}

function SaleForm({ places }) {
  const navigate = useNavigate()
  const readOnly = useReadOnly()
  const [v, setV] = useState({
    warehouse_id: String(places.find((w) => w.is_default)?.id ?? places[0]?.id ?? ''), sale_date: today(), customer_name: '', customer_phone: '', customer_tin: '',
    discount: '', delivery: '', paid_now: '', payment_method: 'cash', payment_reference: '', due_date: '', note: '', lines: [],
  })
  const [paidTouched, setPaidTouched] = useState(false)
  const [freeCount, setFreeCount] = useState(0)
  const [requestId] = useState(newIdempotencyKey)
  const { busy, errors, lines: lineErrors, submit } = useSubmit()
  // A line's server message stops showing once that line is changed, until the next save.
  const [edited, setEdited] = useState({})

  const header = <Link to="/seller/erp/sales" className="mb-3 inline-flex items-center gap-1 text-sm text-stone-600 hover:underline"><ArrowLeft className="size-4" aria-hidden="true" />Sales & invoices</Link>
  if (readOnly) return <>{header}<p className="card p-4 text-sm text-stone-600">Your store cannot record sales right now.</p></>

  const set = (k) => (e) => setV({ ...v, [k]: e.target.value })
  const setLine = (i, k, value) => { setEdited({ ...edited, [i]: true }); setV({ ...v, lines: v.lines.map((l, j) => (j === i ? { ...l, [k]: value } : l)) }) }
  const key = (l) => (l.free ? l.key : itemKey(l))

  // Worked out here only to show; the server works the totals out again and its numbers are kept.
  const lineTotals = v.lines.map((l) => {
    const q = /^\d+$/.test(l.quantity) ? Number(l.quantity) : null
    const unit = toSantim(l.unit_price)
    const off = toSantim(l.discount)
    return q === null || q < 1 || unit === null || off === null || cleanBirr(l.unit_price) === '' ? null : q * unit - off
  })
  const subtotal = lineTotals.every((t) => t !== null) ? lineTotals.reduce((a, b) => a + b, 0) : null
  const discount = toSantim(v.discount)
  const delivery = toSantim(v.delivery)
  const total = subtotal === null || discount === null || delivery === null ? null : subtotal - discount + delivery
  const paidText = paidTouched ? v.paid_now : (total > 0 ? birr(total) : '')
  const paid = toSantim(paidText)
  const owed = total !== null && paid !== null ? total - paid : null
  const short = (l) => !l.free && l.stock_type !== 'made_to_order' && l.at_location !== null && l.at_location !== undefined && Number(l.quantity) > l.at_location
  const problem = v.lines.length === 0 ? 'Add at least one item.'
    : lineTotals.some((t) => t === null) ? 'Every line needs a quantity of at least 1 and a price in birr.'
      : v.lines.some((l) => l.free && !l.description.trim()) ? 'Describe each line that is not one of your listings.'
        : lineTotals.some((t) => t < 0) ? 'A line discount is more than the line total.'
          : discount === null || delivery === null ? 'Check the sale discount and delivery charge.'
            : total < 0 ? 'The discount is more than the items total.'
              : paid === null || paid > total ? 'The amount paid now cannot be more than the total.' : null

  async function save(e) {
    e.preventDefault()
    setEdited({})
    const body = await submit(() => api.post('/seller/erp/sales', {
      warehouse_id: v.warehouse_id ? Number(v.warehouse_id) : null, sale_date: v.sale_date || null,
      customer_name: v.customer_name.trim() || null, customer_phone: v.customer_phone.trim() || null, customer_tin: v.customer_tin.trim() || null,
      discount: cleanBirr(v.discount) || null, delivery: cleanBirr(v.delivery) || null,
      paid_now: cleanBirr(paidText) || null, payment_method: paid > 0 ? v.payment_method : null, payment_reference: v.payment_reference.trim() || null,
      due_date: owed > 0 && v.due_date ? v.due_date : null, note: v.note.trim() || null, request_id: requestId,
      items: v.lines.map((l) => (l.free
        ? { description: l.description.trim(), quantity: Number(l.quantity), unit_price: cleanBirr(l.unit_price), discount: cleanBirr(l.discount) || null }
        : { product_id: l.product_id, variant_id: l.variant_id, quantity: Number(l.quantity), unit_price: cleanBirr(l.unit_price), discount: cleanBirr(l.discount) || null })),
    }), 'Sale recorded')
    if (body) navigate(`/seller/erp/sales/offline/${body.data.id}`, { replace: true })
  }

  const fieldError = (k) => errors[k] && <span className="mt-1 block text-xs font-medium text-red-700">{errors[k]}</span>

  return (
    <>
      {header}
      <PageHeader title="New shop sale" subtitle="A sale you made yourself: at your shop, by phone or to a business. In-stock items come out of the location you pick when you save." />
      <form noValidate className="space-y-5" onSubmit={save}>
        <section className="card grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
          <label className="text-sm text-stone-700" htmlFor="sale-place">Sold from
            <select id="sale-place" className="input mt-1" value={v.warehouse_id}
              onChange={(e) => setV({ ...v, warehouse_id: e.target.value, lines: v.lines.map((l) => ({ ...l, at_location: null })) })}>
              {places.map((w) => <option key={w.id} value={w.id}>{placeName(w)}</option>)}
            </select>
            {fieldError('warehouse_id')}
          </label>
          <label className="text-sm text-stone-700" htmlFor="sale-date">Sale date
            <input id="sale-date" type="date" className="input mt-1" max={today()} value={v.sale_date} onChange={set('sale_date')} aria-invalid={errors.sale_date ? true : undefined} />
            {fieldError('sale_date')}
          </label>
          <label className="text-sm text-stone-700" htmlFor="sale-customer">Customer name
            <input id="sale-customer" className="input mt-1" maxLength={100} value={v.customer_name} onChange={set('customer_name')} placeholder="Optional" />
          </label>
          <label className="text-sm text-stone-700" htmlFor="sale-phone">Customer phone
            <input id="sale-phone" className="input mt-1" inputMode="tel" maxLength={20} value={v.customer_phone} onChange={set('customer_phone')} placeholder="Optional" aria-invalid={errors.customer_phone ? true : undefined} />
            {fieldError('customer_phone')}
          </label>
          <label className="text-sm text-stone-700" htmlFor="sale-tin">Customer TIN
            <input id="sale-tin" className="input mt-1" inputMode="numeric" maxLength={20} value={v.customer_tin} onChange={set('customer_tin')} placeholder="For a business buyer" aria-invalid={errors.customer_tin ? true : undefined} />
            {fieldError('customer_tin')}
          </label>
        </section>

        <section className="card p-4">
          <h2 className="mb-3 font-semibold text-stone-900">Items</h2>
          {v.lines.length > 0 && (
            <ul className="mb-3 divide-y divide-stone-100 rounded-lg border border-stone-200">
              {v.lines.map((l, i) => (
                <li key={key(l)} className="grid grid-cols-2 gap-2 p-3 sm:grid-cols-[minmax(0,1fr)_6rem_8rem_7rem_8rem_auto] sm:items-end">
                  <span className="col-span-2 min-w-0 text-sm sm:col-span-1 sm:self-center">
                    {l.free ? (
                      <label className="text-xs text-stone-600" htmlFor={`sale-desc-${i}`}>What was sold
                        <input id={`sale-desc-${i}`} className="input mt-0.5" maxLength={255} value={l.description} placeholder="For example: assembly, cushions" onChange={(e) => setLine(i, 'description', e.target.value)} />
                      </label>
                    ) : (
                      <>
                        <span className="block font-medium text-stone-900">{itemName(l)}</span>
                        <span className="text-xs text-stone-500">{[l.sku, l.stock_type === 'made_to_order' ? 'made to order, no stock taken' : l.at_location !== null && l.at_location !== undefined ? `${l.at_location} at this location` : null].filter(Boolean).join(' · ')}</span>
                        {short(l) && <span className="block text-xs font-medium text-amber-800">More than there is at this location.</span>}
                      </>
                    )}
                    {lineErrors[i] && !edited[i] && <span role="alert" className="block text-xs font-medium text-red-700">{lineErrors[i]}</span>}
                  </span>
                  <label className="text-xs text-stone-600" htmlFor={`sale-q-${i}`}>Quantity
                    <input id={`sale-q-${i}`} className="input mt-0.5" inputMode="numeric" value={l.quantity} onChange={(e) => setLine(i, 'quantity', e.target.value.trim())} />
                  </label>
                  <label className="text-xs text-stone-600" htmlFor={`sale-p-${i}`}>Unit price (ETB)
                    <input id={`sale-p-${i}`} className="input mt-0.5" inputMode="decimal" value={l.unit_price} onChange={(e) => setLine(i, 'unit_price', e.target.value)} />
                  </label>
                  <label className="text-xs text-stone-600" htmlFor={`sale-d-${i}`}>Discount (ETB)
                    <input id={`sale-d-${i}`} className="input mt-0.5" inputMode="decimal" value={l.discount} placeholder="0" onChange={(e) => setLine(i, 'discount', e.target.value)} />
                  </label>
                  <p className="text-xs text-stone-600">Line total<span className="mt-1.5 block text-sm font-semibold text-stone-900 tabular-nums">{lineTotals[i] === null ? '-' : money(lineTotals[i])}</span></p>
                  <button type="button" className="icon-btn col-span-2 justify-self-end sm:col-span-1" aria-label={`Remove ${l.free ? l.description || 'line' : itemName(l)}`} onClick={() => { setEdited(Object.fromEntries(v.lines.map((_, j) => [j, true]))); setV({ ...v, lines: v.lines.filter((_, j) => j !== i) }) }}><X className="size-4" aria-hidden="true" /></button>
                </li>
              ))}
            </ul>
          )}
          {v.lines.length < MAX_LINES ? (
            <div className="flex flex-wrap items-start gap-2">
              <ItemPicker forSale warehouseId={v.warehouse_id ? Number(v.warehouse_id) : undefined} added={v.lines.filter((l) => !l.free).map(itemKey)}
                label={v.lines.length ? 'Add more items' : 'Add items'}
                onPick={(it) => setV((s) => ({ ...s, lines: [...s.lines, { ...it, quantity: '1', unit_price: birr(it.price_santim), discount: '' }] }))} />
              <button type="button" className="btn-ghost" onClick={() => { setFreeCount(freeCount + 1); setV((s) => ({ ...s, lines: [...s.lines, { free: true, key: `free-${freeCount}`, description: '', quantity: '1', unit_price: '', discount: '' }] })) }}>
                Add a line that is not a listing
              </button>
            </div>
          ) : <p className="text-sm text-stone-500">A sale can have up to {MAX_LINES} lines.</p>}
          {errors.items && !Object.keys(lineErrors).length && <p role="alert" className="mt-2 text-xs font-medium text-red-700">{errors.items}</p>}
        </section>

        <section className="card grid gap-5 p-4 lg:grid-cols-2">
          <div className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-2">
              <label className="text-sm text-stone-700" htmlFor="sale-discount">Discount on the whole sale (ETB)
                <input id="sale-discount" className="input mt-1" inputMode="decimal" value={v.discount} placeholder="0" onChange={set('discount')} aria-invalid={errors.discount ? true : undefined} />
                {fieldError('discount')}
              </label>
              <label className="text-sm text-stone-700" htmlFor="sale-delivery">Delivery charge (ETB)
                <input id="sale-delivery" className="input mt-1" inputMode="decimal" value={v.delivery} placeholder="0" onChange={set('delivery')} aria-invalid={errors.delivery ? true : undefined} />
                {fieldError('delivery')}
              </label>
              <label className="text-sm text-stone-700" htmlFor="sale-paid">Paid now (ETB)
                <input id="sale-paid" className="input mt-1" inputMode="decimal" value={paidText} placeholder="0" onChange={(e) => { setPaidTouched(true); setV({ ...v, paid_now: e.target.value }) }} aria-invalid={errors.paid_now ? true : undefined} />
                {fieldError('paid_now')}
              </label>
              {paid > 0 && (
                <label className="text-sm text-stone-700" htmlFor="sale-method">Paid by
                  <select id="sale-method" className="input mt-1" value={v.payment_method} onChange={set('payment_method')}>
                    {Object.entries(PAY_METHODS).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
                  </select>
                </label>
              )}
              {paid > 0 && v.payment_method !== 'cash' && (
                <label className="text-sm text-stone-700" htmlFor="sale-payref">Payment reference
                  <input id="sale-payref" className="input mt-1" maxLength={100} value={v.payment_reference} onChange={set('payment_reference')} placeholder="Transaction number" />
                </label>
              )}
              {owed > 0 && (
                <label className="text-sm text-stone-700" htmlFor="sale-due">Customer pays the rest by
                  <input id="sale-due" type="date" className="input mt-1" min={v.sale_date || undefined} value={v.due_date} onChange={set('due_date')} aria-invalid={errors.due_date ? true : undefined} />
                  {fieldError('due_date')}
                </label>
              )}
            </div>
            <label className="block text-sm text-stone-700" htmlFor="sale-note">Note
              <textarea id="sale-note" className="input mt-1" rows={2} maxLength={500} value={v.note} onChange={set('note')} placeholder="Shown on the invoice" />
            </label>
          </div>
          <dl className="space-y-1.5 self-start rounded-xl bg-stone-50 p-4 text-sm">
            <div className="flex justify-between"><dt>Items</dt><dd className="tabular-nums">{subtotal === null ? '-' : money(subtotal)}</dd></div>
            {discount > 0 && <div className="flex justify-between"><dt>Discount</dt><dd className="tabular-nums">-{money(discount)}</dd></div>}
            {delivery > 0 && <div className="flex justify-between"><dt>Delivery</dt><dd className="tabular-nums">{money(delivery)}</dd></div>}
            <div className="flex justify-between border-t border-stone-200 pt-2 text-base font-bold"><dt>Total</dt><dd className="tabular-nums">{total === null ? '-' : money(total)}</dd></div>
            {paid !== null && total !== null && <div className="flex justify-between"><dt>Paid now</dt><dd className="tabular-nums">{money(paid)}</dd></div>}
            {owed > 0 && <div className="flex justify-between font-semibold text-red-700"><dt>Customer still owes</dt><dd className="tabular-nums">{money(owed)}</dd></div>}
          </dl>
        </section>

        {problem && v.lines.length > 0 && <p className="text-sm text-amber-800">{problem}</p>}
        {errors.form && <p role="alert" className="text-sm font-medium text-red-700">{errors.form}</p>}
        {errors.payment_method && <p role="alert" className="text-sm font-medium text-red-700">{errors.payment_method}</p>}
        <div className="flex flex-wrap justify-end gap-2">
          <Link to="/seller/erp/sales" className="btn-ghost">Cancel</Link>
          <button type="submit" className="btn-primary" disabled={busy || !!problem}>{busy ? 'Saving...' : 'Record sale'}</button>
        </div>
      </form>
    </>
  )
}
