import { ArrowLeft, X } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { ErrorState, PageHeader, Spinner } from '../../../components/ui'
import { api, parseError } from '../../../lib/api'
import { useFetch } from '../../../lib/useFetch'
import { ItemPicker, birr, cleanBirr, itemKey, itemName, money, toSantim, useReadOnly, useSubmit } from './shared'

const MAX_LINES = 100
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Addis_Ababa' })

/** Create a purchase order, or change a draft. A sent order only changes its dates and notes on its own page. */
export default function ErpPurchaseForm() {
  const { id } = useParams()
  const [params] = useSearchParams()
  const existing = useFetch(id ? `/seller/erp/purchases/${id}` : null)
  const suppliers = useFetch('/seller/erp/suppliers?brief=1')
  const places = useFetch('/seller/erp/warehouses')
  const po = existing.data?.data

  const failed = existing.error || suppliers.error || places.error
  if (failed) return <ErrorState error={failed} onRetry={() => { existing.reload(); suppliers.reload(); places.reload() }} />
  if ((id && !po) || !suppliers.data || !places.data) return <Spinner />

  const initial = po ? {
    supplier_id: String(po.supplier.id), warehouse_id: String(po.warehouse.id), order_date: po.order_date, expected_date: po.expected_date || '',
    supplier_reference: po.supplier_reference || '', discount: birr(po.discount_santim || null), charges: birr(po.charges_santim || null),
    charges_note: po.charges_note || '', notes: po.notes || '',
    lines: po.items.map((i) => ({ product_id: i.product_id, variant_id: i.variant_id, name: i.description, label: null, quantity: String(i.quantity), unit_cost: birr(i.unit_cost_santim), discount: birr(i.discount_santim || null) })),
  } : {
    supplier_id: params.get('supplier') || '', warehouse_id: '', order_date: today(), expected_date: '', supplier_reference: '',
    discount: '', charges: '', charges_note: '', notes: '', lines: [],
  }
  return <PurchaseForm key={id || 'new'} id={id} po={po} initial={initial} suppliers={suppliers.data.data} places={places.data.data} />
}

function PurchaseForm({ id, po, initial, suppliers, places }) {
  const navigate = useNavigate()
  const readOnly = useReadOnly()
  const [v, setV] = useState(initial)
  const { busy, errors, lines: lineErrors, submit, setErrors } = useSubmit()

  const back = id ? `/seller/erp/purchases/${id}` : '/seller/erp/purchases'
  const header = <Link to={back} className="mb-3 inline-flex items-center gap-1 text-sm text-stone-600 hover:underline"><ArrowLeft className="size-4" aria-hidden="true" />{id ? po.number : 'Purchases'}</Link>
  if (readOnly) return <>{header}<p className="card p-4 text-sm text-stone-600">Your store cannot change records right now.</p></>
  if (po && po.status !== 'draft') {
    return <>{header}<p className="card p-4 text-sm text-stone-600">{po.number} was already sent, so its items and prices stay as they are. You can still change its expected date, reference and notes on <Link to={back} className="font-semibold text-brand-700 underline">its page</Link>.</p></>
  }

  const supplierList = suppliers.filter((s) => s.is_active || String(s.id) === v.supplier_id)
  const placeList = places.filter((w) => w.is_active || String(w.id) === v.warehouse_id)
  const warehouseId = v.warehouse_id || String(placeList.find((w) => w.is_default)?.id ?? placeList[0]?.id ?? '')
  const supplierId = v.supplier_id && supplierList.some((s) => String(s.id) === v.supplier_id) ? v.supplier_id : ''

  const set = (k) => (e) => setV({ ...v, [k]: e.target.value })
  const setLine = (i, k, value) => setV({ ...v, lines: v.lines.map((l, j) => (j === i ? { ...l, [k]: value } : l)) })

  // Worked out here only to show; the server works the totals out again and its numbers are kept.
  const lineTotals = v.lines.map((l) => {
    const q = /^\d+$/.test(l.quantity) ? Number(l.quantity) : null
    const unit = toSantim(l.unit_cost)
    const off = toSantim(l.discount)
    return q === null || q < 1 || unit === null || off === null || cleanBirr(l.unit_cost) === '' ? null : q * unit - off
  })
  const subtotal = lineTotals.every((t) => t !== null) ? lineTotals.reduce((a, b) => a + b, 0) : null
  const discount = toSantim(v.discount)
  const charges = toSantim(v.charges)
  const total = subtotal === null || discount === null || charges === null ? null : subtotal - discount + charges
  const problem = v.lines.length === 0 ? 'Add at least one item.'
    : lineTotals.some((t) => t === null) ? 'Every line needs a quantity of at least 1 and a cost in birr.'
      : lineTotals.some((t) => t < 0) ? 'A line discount is more than the line total.'
        : discount === null || charges === null ? 'Check the order discount and charges.'
          : total < 0 ? 'The discount is more than the items total.' : null

  async function save(send) {
    setErrors({})
    const payload = {
      supplier_id: supplierId ? Number(supplierId) : null, warehouse_id: warehouseId ? Number(warehouseId) : null,
      order_date: v.order_date || null, expected_date: v.expected_date || null, supplier_reference: v.supplier_reference.trim() || null,
      discount: cleanBirr(v.discount) || null, charges: cleanBirr(v.charges) || null, charges_note: v.charges_note.trim() || null, notes: v.notes.trim() || null,
      items: v.lines.map((l) => ({ product_id: l.product_id, variant_id: l.variant_id, quantity: Number(l.quantity), unit_cost: cleanBirr(l.unit_cost), discount: cleanBirr(l.discount) || null })),
    }
    const body = await submit(() => (id ? api.put(`/seller/erp/purchases/${id}`, payload) : api.post('/seller/erp/purchases', payload)), send ? null : 'Draft saved')
    if (!body) return
    const saved = body.data
    if (send) {
      try {
        await api.post(`/seller/erp/purchases/${saved.id}/order`)
        toast.success(`${saved.number} marked as ordered`)
      } catch (err) {
        toast.error(`Saved as a draft, but not sent: ${parseError(err).message}`)
      }
    }
    navigate(`/seller/erp/purchases/${saved.id}`, { replace: true })
  }

  return (
    <>
      {header}
      <PageHeader title={po ? `Edit ${po.number}` : 'New purchase order'} subtitle="Nothing changes in your stock until goods arrive and you receive them." />
      <form noValidate className="space-y-5" onSubmit={(e) => { e.preventDefault(); save(false) }}>
        <section className="card grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
          <label className="text-sm text-stone-700" htmlFor="po-supplier">Supplier
            <select id="po-supplier" className="input mt-1" value={supplierId} onChange={set('supplier_id')} aria-invalid={errors.supplier_id ? true : undefined}>
              <option value="">Choose a supplier</option>
              {supplierList.map((s) => <option key={s.id} value={s.id}>{s.name}{s.is_active ? '' : ' (inactive)'}</option>)}
            </select>
            {errors.supplier_id && <span className="mt-1 block text-xs font-medium text-red-700">{errors.supplier_id}</span>}
            {supplierList.length === 0 && <span className="mt-1 block text-xs text-stone-500"><Link to="/seller/erp/suppliers/new" className="font-semibold text-brand-700 underline">Add a supplier</Link> first.</span>}
          </label>
          <label className="text-sm text-stone-700" htmlFor="po-warehouse">Deliver to
            <select id="po-warehouse" className="input mt-1" value={warehouseId} onChange={set('warehouse_id')} aria-invalid={errors.warehouse_id ? true : undefined}>
              {placeList.map((w) => <option key={w.id} value={w.id}>{w.name}{w.is_active ? '' : ' (closed)'}</option>)}
            </select>
            {errors.warehouse_id && <span className="mt-1 block text-xs font-medium text-red-700">{errors.warehouse_id}</span>}
          </label>
          <label className="text-sm text-stone-700" htmlFor="po-ref">Supplier's reference
            <input id="po-ref" className="input mt-1" maxLength={60} value={v.supplier_reference} onChange={set('supplier_reference')} placeholder="Their quote or order number" />
          </label>
          <label className="text-sm text-stone-700" htmlFor="po-date">Order date
            <input id="po-date" type="date" className="input mt-1" max={today()} value={v.order_date} onChange={set('order_date')} aria-invalid={errors.order_date ? true : undefined} />
            {errors.order_date && <span className="mt-1 block text-xs font-medium text-red-700">{errors.order_date}</span>}
          </label>
          <label className="text-sm text-stone-700" htmlFor="po-expected">Expected delivery
            <input id="po-expected" type="date" className="input mt-1" min={v.order_date || undefined} value={v.expected_date} onChange={set('expected_date')} aria-invalid={errors.expected_date ? true : undefined} />
            {errors.expected_date && <span className="mt-1 block text-xs font-medium text-red-700">{errors.expected_date}</span>}
          </label>
        </section>

        <section className="card p-4">
          <h2 className="mb-3 font-semibold text-stone-900">Items</h2>
          {v.lines.length > 0 && (
            <ul className="mb-3 divide-y divide-stone-100 rounded-lg border border-stone-200">
              {v.lines.map((l, i) => (
                <li key={itemKey(l)} className="grid grid-cols-2 gap-2 p-3 sm:grid-cols-[minmax(0,1fr)_6rem_8rem_7rem_8rem_auto] sm:items-end">
                  <span className="col-span-2 min-w-0 text-sm sm:col-span-1 sm:self-center">
                    <span className="block font-medium text-stone-900">{itemName(l)}</span>
                    {l.sku && <span className="text-xs text-stone-500">{l.sku}</span>}
                    {lineErrors[i] && <span role="alert" className="block text-xs font-medium text-red-700">{lineErrors[i]}</span>}
                  </span>
                  <label className="text-xs text-stone-600" htmlFor={`po-q-${i}`}>Quantity
                    <input id={`po-q-${i}`} className="input mt-0.5" inputMode="numeric" value={l.quantity} onChange={(e) => setLine(i, 'quantity', e.target.value.trim())} />
                  </label>
                  <label className="text-xs text-stone-600" htmlFor={`po-c-${i}`}>Unit cost (ETB)
                    <input id={`po-c-${i}`} className="input mt-0.5" inputMode="decimal" value={l.unit_cost} onChange={(e) => setLine(i, 'unit_cost', e.target.value)} />
                  </label>
                  <label className="text-xs text-stone-600" htmlFor={`po-d-${i}`}>Discount (ETB)
                    <input id={`po-d-${i}`} className="input mt-0.5" inputMode="decimal" value={l.discount} placeholder="0" onChange={(e) => setLine(i, 'discount', e.target.value)} />
                  </label>
                  <p className="text-xs text-stone-600">Line total<span className="mt-1.5 block text-sm font-semibold text-stone-900 tabular-nums">{lineTotals[i] === null ? '-' : money(lineTotals[i])}</span></p>
                  <button type="button" className="icon-btn col-span-2 justify-self-end sm:col-span-1" aria-label={`Remove ${itemName(l)}`} onClick={() => setV({ ...v, lines: v.lines.filter((_, j) => j !== i) })}><X className="size-4" aria-hidden="true" /></button>
                </li>
              ))}
            </ul>
          )}
          {v.lines.length < MAX_LINES ? (
            <ItemPicker supplierId={supplierId ? Number(supplierId) : undefined} added={v.lines.map(itemKey)} label={v.lines.length ? 'Add more items' : 'Add items'}
              onPick={(it) => setV((s) => ({ ...s, lines: [...s.lines, { ...it, quantity: '1', unit_cost: birr(it.cost_price_santim), discount: '' }] }))} />
          ) : <p className="text-sm text-stone-500">An order can have up to {MAX_LINES} lines.</p>}
          {errors.items && !Object.keys(lineErrors).length && <p role="alert" className="mt-2 text-xs font-medium text-red-700">{errors.items}</p>}
        </section>

        <section className="card grid gap-5 p-4 lg:grid-cols-2">
          <div className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-2">
              <label className="text-sm text-stone-700" htmlFor="po-discount">Order discount (ETB)
                <input id="po-discount" className="input mt-1" inputMode="decimal" value={v.discount} placeholder="0" onChange={set('discount')} aria-invalid={errors.discount ? true : undefined} />
                {errors.discount && <span className="mt-1 block text-xs font-medium text-red-700">{errors.discount}</span>}
              </label>
              <label className="text-sm text-stone-700" htmlFor="po-charges">Delivery and other charges (ETB)
                <input id="po-charges" className="input mt-1" inputMode="decimal" value={v.charges} placeholder="0" onChange={set('charges')} aria-invalid={errors.charges ? true : undefined} />
                {errors.charges && <span className="mt-1 block text-xs font-medium text-red-700">{errors.charges}</span>}
              </label>
            </div>
            <label className="block text-sm text-stone-700" htmlFor="po-charges-note">What the charges are for
              <input id="po-charges-note" className="input mt-1" maxLength={120} value={v.charges_note} onChange={set('charges_note')} placeholder="For example truck from Merkato" />
            </label>
            <label className="block text-sm text-stone-700" htmlFor="po-notes">Notes for the supplier
              <textarea id="po-notes" className="input mt-1" rows={3} maxLength={1000} value={v.notes} onChange={set('notes')} />
            </label>
            <p className="text-xs text-stone-500">The discount and charges are shared across the items by value, so each unit's cost includes its part.</p>
          </div>
          <dl className="space-y-1.5 self-start rounded-xl bg-stone-50 p-4 text-sm">
            <div className="flex justify-between"><dt>Items</dt><dd className="tabular-nums">{subtotal === null ? '-' : money(subtotal)}</dd></div>
            {discount > 0 && <div className="flex justify-between"><dt>Discount</dt><dd className="tabular-nums">-{money(discount)}</dd></div>}
            {charges > 0 && <div className="flex justify-between"><dt>Charges</dt><dd className="tabular-nums">{money(charges)}</dd></div>}
            <div className="flex justify-between border-t border-stone-200 pt-2 text-base font-bold"><dt>Total</dt><dd className="tabular-nums">{total === null ? '-' : money(total)}</dd></div>
          </dl>
        </section>

        {problem && v.lines.length > 0 && <p className="text-sm text-amber-800">{problem}</p>}
        {errors.form && <p role="alert" className="text-sm font-medium text-red-700">{errors.form}</p>}
        {errors.status && <p role="alert" className="text-sm font-medium text-red-700">{errors.status}</p>}
        <div className="flex flex-wrap justify-end gap-2">
          <Link to={back} className="btn-ghost">Cancel</Link>
          <button type="submit" className="btn-secondary" disabled={busy || !!problem || !supplierId}>{busy ? 'Saving...' : 'Save draft'}</button>
          <button type="button" className="btn-primary" disabled={busy || !!problem || !supplierId} onClick={() => save(true)}>Save and mark as ordered</button>
        </div>
      </form>
    </>
  )
}
