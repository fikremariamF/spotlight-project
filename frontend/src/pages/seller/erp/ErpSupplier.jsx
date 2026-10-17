import { ArrowLeft } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ErrorState, PageHeader, Spinner, Stats } from '../../../components/ui'
import { api } from '../../../lib/api'
import { useFetch } from '../../../lib/useFetch'
import { PurchaseTable } from './ErpPurchases'
import { Owed } from './ErpSuppliers'
import { Err, money, useReadOnly, useSubmit } from './shared'
import { InvoiceForm, InvoiceTable, PaymentForm, PaymentTable } from './SupplierMoney'

const back = <Link to="/seller/erp/suppliers" className="mb-3 inline-flex items-center gap-1 text-sm text-stone-600 hover:underline"><ArrowLeft className="size-4" aria-hidden="true" />Suppliers</Link>

export function SupplierForm({ supplier = null, onDone, onCancel }) {
  const [v, setV] = useState({
    name: supplier?.name || '', contact_person: supplier?.contact_person || '', phone: supplier?.phone || '', email: supplier?.email || '',
    address: supplier?.address || '', tin: supplier?.tin || '', payment_terms: supplier?.payment_terms || '',
    payment_days: supplier?.payment_days ?? '', notes: supplier?.notes || '',
  })
  const { busy, errors, submit } = useSubmit()
  const field = (name, label, props = {}) => (
    <label className="text-sm text-stone-700" htmlFor={`sup-${name}`}>{label}
      <input id={`sup-${name}`} className="input mt-1" value={v[name]} aria-invalid={errors[name] ? true : undefined} onChange={(e) => setV({ ...v, [name]: e.target.value })} {...props} />
      {errors[name] && <span className="mt-1 block text-xs font-medium text-red-700">{errors[name]}</span>}
    </label>
  )
  async function save(e) {
    e.preventDefault()
    const payload = Object.fromEntries(Object.entries(v).map(([k, x]) => [k, String(x).trim() || null]))
    payload.payment_days = payload.payment_days === null ? null : Number(payload.payment_days)
    const body = await submit(() => (supplier ? api.put(`/seller/erp/suppliers/${supplier.id}`, payload) : api.post('/seller/erp/suppliers', payload)), supplier ? 'Supplier saved' : 'Supplier added')
    if (body) onDone(body.data)
  }
  const other = Object.fromEntries(Object.entries(errors).filter(([k]) => !(k in v)))
  return (
    <form noValidate onSubmit={save} className="space-y-3">
      <div className="grid gap-3 sm:grid-cols-2">
        {field('name', 'Business name', { maxLength: 150, required: true })}
        {field('contact_person', 'Contact person', { maxLength: 100 })}
        {field('phone', 'Phone', { maxLength: 20, inputMode: 'tel', placeholder: '0911 234 567' })}
        {field('email', 'Email', { maxLength: 150, type: 'email' })}
        {field('address', 'Address', { maxLength: 255 })}
        {field('tin', 'TIN', { maxLength: 20, inputMode: 'numeric' })}
        {field('payment_terms', 'Payment terms', { maxLength: 120, placeholder: 'For example 50% upfront, rest on delivery' })}
        {field('payment_days', 'Days to pay an invoice', { inputMode: 'numeric', placeholder: 'For example 30' })}
      </div>
      <label className="block text-sm text-stone-700" htmlFor="sup-notes">Notes
        <textarea id="sup-notes" className="input mt-1" rows={3} maxLength={1000} value={v.notes} onChange={(e) => setV({ ...v, notes: e.target.value })} />
      </label>
      <Err errors={other} />
      <div className="flex justify-end gap-2">
        {onCancel && <button type="button" className="btn-ghost" onClick={onCancel} disabled={busy}>Cancel</button>}
        <button type="submit" className="btn-primary" disabled={busy || v.name.trim().length < 2}>{busy ? 'Saving...' : supplier ? 'Save supplier' : 'Add supplier'}</button>
      </div>
    </form>
  )
}

export function ErpSupplierNew() {
  const navigate = useNavigate()
  const readOnly = useReadOnly()
  return (
    <div className="max-w-3xl">
      {back}
      <PageHeader title="Add supplier" subtitle="Only your store sees your suppliers." />
      {readOnly ? <p className="card p-4 text-sm text-stone-600">Your store cannot change records right now.</p>
        : <section className="card p-4"><SupplierForm onDone={(s) => navigate(`/seller/erp/suppliers/${s.id}`, { replace: true })} onCancel={() => navigate('/seller/erp/suppliers')} /></section>}
    </div>
  )
}

export default function ErpSupplier() {
  const { id } = useParams()
  const navigate = useNavigate()
  const readOnly = useReadOnly()
  const res = useFetch(`/seller/erp/suppliers/${id}`)
  const s = res.data?.data
  const [panel, setPanel] = useState(null)
  const { busy, errors, submit } = useSubmit()

  if (res.loading && !s) return <Spinner />
  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />

  const billable = s.orders.filter((o) => !['draft', 'cancelled'].includes(o.status))
  const done = () => { setPanel(null); res.reload() }
  const setActive = (on) => submit(() => api.put(`/seller/erp/suppliers/${s.id}`, { ...pick(s), is_active: on }), on ? 'Supplier active again' : 'Supplier marked inactive').then((b) => b && res.setData(b))
  const remove = async () => {
    const body = await submit(() => api.delete(`/seller/erp/suppliers/${s.id}`), 'Supplier deleted')
    if (body) navigate('/seller/erp/suppliers', { replace: true })
  }

  return (
    <>
      {back}
      <PageHeader title={s.name}
        subtitle={s.is_active ? (s.payment_terms || 'Supplier') : <span className="rounded-full bg-stone-200 px-2 py-0.5 text-xs font-semibold text-stone-700">Inactive</span>}
        actions={!readOnly && <>
          {s.is_active && <Link to={`/seller/erp/purchases/new?supplier=${s.id}`} className="btn-primary">New purchase order</Link>}
          {panel !== 'edit' && <button type="button" className="btn-secondary" onClick={() => setPanel('edit')}>Edit</button>}
          <button type="button" className="btn-ghost" onClick={() => setActive(!s.is_active)} disabled={busy}>{s.is_active ? 'Mark inactive' : 'Make active'}</button>
          {s.can_delete && <button type="button" className="btn-ghost text-red-700" onClick={() => setPanel('delete')}>Delete</button>}
        </>} />
      {panel !== 'delete' && <Err errors={errors} />}

      {panel === 'delete' && (
        <div className="mb-4 space-y-2 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900" role="alertdialog" aria-label="Delete this supplier">
          <p>Delete {s.name}? This supplier has no orders, invoices or payments, so nothing else changes. Listings that named it as their usual supplier will show none.</p>
          <Err errors={errors} />
          <div className="flex justify-end gap-2">
            <button type="button" className="btn-ghost" onClick={() => setPanel(null)} disabled={busy}>Back</button>
            <button type="button" className="btn-danger" onClick={remove} disabled={busy}>{busy ? 'Deleting...' : 'Delete supplier'}</button>
          </div>
        </div>
      )}

      {panel === 'edit' ? (
        <section className="card mb-5 p-4"><h2 className="mb-3 font-semibold text-stone-900">Supplier details</h2><SupplierForm supplier={s} onDone={done} onCancel={() => setPanel(null)} /></section>
      ) : (
        <dl className="card mb-5 grid gap-3 p-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
          <Detail label="Contact person" value={s.contact_person} />
          <Detail label="Phone" value={s.phone && <a href={`tel:${s.phone.replace(/\s/g, '')}`} className="text-brand-700 hover:underline">{s.phone}</a>} />
          <Detail label="Email" value={s.email && <a href={`mailto:${s.email}`} className="break-all text-brand-700 hover:underline">{s.email}</a>} />
          <Detail label="Address" value={s.address} />
          <Detail label="TIN" value={s.tin} />
          <Detail label="Payment terms" value={[s.payment_terms, s.payment_days !== null && `invoices due in ${s.payment_days} days`].filter(Boolean).join(', ')} />
          {s.notes && <div className="sm:col-span-2 lg:col-span-3"><dt className="text-xs text-stone-500">Notes</dt><dd className="whitespace-pre-line">{s.notes}</dd></div>}
        </dl>
      )}

      <Stats items={[
        { label: 'Bought', value: money(s.bought_santim), hint: 'invoiced, or received where no invoice yet' },
        { label: 'Paid', value: money(s.paid_santim) },
        { label: s.owed_santim < 0 ? 'Paid ahead' : 'You owe', value: money(Math.abs(s.owed_santim)), tone: s.owed_santim > 0 ? 'warn' : undefined },
        { label: 'Received, no invoice yet', value: money(s.received_not_invoiced_santim) },
      ]} />

      <section className="card mt-5 p-4">
        <div className="mb-3 flex items-center justify-between"><h2 className="font-semibold text-stone-900">Purchase orders</h2>{s.orders.length >= 30 && <Link to={`/seller/erp/purchases?supplier_id=${s.id}`} className="text-sm font-semibold text-brand-700 hover:underline">All of them</Link>}</div>
        {s.orders.length === 0 ? <p className="text-sm text-stone-500">No orders yet.</p> : <PurchaseTable orders={s.orders} flush hideSupplier />}
      </section>

      <div className="mt-5 grid grid-cols-[minmax(0,1fr)] gap-5 xl:grid-cols-2">
        <section className="card p-4">
          <div className="mb-3 flex items-center justify-between gap-2">
            <h2 className="font-semibold text-stone-900">Invoices</h2>
            {!readOnly && panel !== 'invoice' && <button type="button" className="btn-secondary" onClick={() => setPanel('invoice')}>Record invoice</button>}
          </div>
          {panel === 'invoice' && <div className="mb-3"><InvoiceForm supplierId={s.id} orders={billable} onDone={done} onCancel={() => setPanel(null)} /></div>}
          <InvoiceTable invoices={s.invoices} readOnly={readOnly} onChange={res.reload} />
        </section>
        <section className="card p-4">
          <div className="mb-3 flex items-center justify-between gap-2">
            <h2 className="font-semibold text-stone-900">Payments</h2>
            {!readOnly && panel !== 'payment' && <button type="button" className="btn-secondary" onClick={() => setPanel('payment')}>Record payment</button>}
          </div>
          {panel === 'payment' && <div className="mb-3"><PaymentForm supplierId={s.id} orders={billable} suggested={s.owed_santim} onDone={done} onCancel={() => setPanel(null)} /></div>}
          <PaymentTable payments={s.payments} readOnly={readOnly} onChange={res.reload} />
          {s.owed_santim !== 0 && <p className="mt-3 text-sm text-stone-600">Balance: <Owed santim={s.owed_santim} /></p>}
        </section>
      </div>

      <section className="card mt-5 p-4">
        <h2 className="mb-1 font-semibold text-stone-900">Items from this supplier</h2>
        <p className="mb-3 text-xs text-stone-500">Items you set this supplier as usual for in Inventory, and anything you ordered from them.</p>
        {s.products.length === 0 ? <p className="text-sm text-stone-500">None yet.</p> : (
          <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            {s.products.map((p) => (
              <li key={p.id}>
                <Link to={`/seller/erp/inventory/${p.id}`} className="flex items-center gap-3 rounded-lg border border-stone-200 p-2 text-sm hover:bg-stone-50">
                  {p.image ? <img src={p.image} alt="" className="size-10 shrink-0 rounded-md object-cover" /> : <span className="size-10 shrink-0 rounded-md bg-stone-100" />}
                  <span className="min-w-0 flex-1">
                    <span className="block truncate font-medium text-stone-900">{p.name}</span>
                    <span className="text-xs text-stone-500">{[p.sku, p.preferred && 'usual supplier', p.cost_price_santim !== null && `cost ${money(p.cost_price_santim)}`].filter(Boolean).join(' · ')}</span>
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>
    </>
  )
}

function Detail({ label, value }) {
  return <div><dt className="text-xs text-stone-500">{label}</dt><dd className="text-stone-900">{value || <span className="text-stone-400">Not set</span>}</dd></div>
}

/** The supplier fields the update endpoint expects, from a supplier as the API returned it. */
function pick(s) {
  return { name: s.name, contact_person: s.contact_person, phone: s.phone, email: s.email, address: s.address, tin: s.tin, payment_terms: s.payment_terms, payment_days: s.payment_days, notes: s.notes }
}
