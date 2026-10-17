import { ArrowLeft, Printer } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ErrorState, PageHeader, Spinner, Stats } from '../../../components/ui'
import { useConfig } from '../../../context/ConfigContext'
import { ORDER_STATUS_TONES, orderStatusLabel } from '../../../lib/format'
import { useFetch } from '../../../lib/useFetch'
import { ReturnForm, ReturnList } from './SaleParts'
import { money, useReadOnly } from './shared'

/**
 * A marketplace order seen as a sale: a receipt to print and the store's own record of goods that
 * came back. The order itself, its payment and the commission are never changed from here.
 */
export default function ErpOrderSale() {
  const { id } = useParams()
  const readOnly = useReadOnly()
  const brand = useConfig().name
  const res = useFetch(`/seller/erp/sales/orders/${id}`)
  const o = res.data?.data
  const [returning, setReturning] = useState(false)

  if (res.loading && !o) return <Spinner />
  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />

  const online = o.payment_method === 'online'
  const units = o.items.reduce((n, i) => n + i.quantity, 0)
  const returnable = o.can_return && o.items.some((i) => i.returnable > 0)

  return (
    <>
      <Link to="/seller/erp/sales" className="mb-3 inline-flex items-center gap-1 text-sm text-stone-600 hover:underline"><ArrowLeft className="size-4" aria-hidden="true" />Sales & invoices</Link>
      <PageHeader title={`Marketplace order ${o.number}`}
        subtitle={<span className="inline-flex flex-wrap items-center gap-2">
          <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${ORDER_STATUS_TONES[o.status] || 'bg-stone-100 text-stone-700'}`}>{orderStatusLabel(o.status, o.fulfillment, 'seller')}</span>
          <span>{o.date}{o.customer.name ? `, ${o.customer.name}` : ''}</span>
        </span>}
        actions={<>
          {!readOnly && !returning && returnable && <button type="button" className="btn-secondary" onClick={() => setReturning(true)}>Record a return</button>}
          <Link to={`/seller/orders/${o.id}`} className="btn-ghost">Open the order</Link>
          <Link to={`/seller/erp/sales/orders/${o.id}/print`} target="_blank" rel="noopener" className="btn-ghost"><Printer className="size-4" aria-hidden="true" />Print receipt</Link>
        </>} />

      {returning && <ReturnForm sale={o} onDone={(body) => { res.setData(body); setReturning(false) }} onCancel={() => setReturning(false)} />}

      <p className="mb-4 rounded-lg bg-stone-100 px-4 py-3 text-sm text-stone-700">
        {online ? `Paid online through ${brand}. ` : 'Cash on delivery. '}
        {brand} keeps its commission of {money(o.commission_santim)} on this order; returns recorded here are for your own stock and records and do not change it.
        {online && ` ${brand} support handles refunds of online payments.`}
        {!o.can_return && ' Returns can be recorded once the order is delivered.'}
      </p>

      <Stats items={[
        { label: 'Order total', value: money(o.total_santim), hint: `${units} unit${units === 1 ? '' : 's'}` },
        { label: 'Commission', value: money(o.commission_santim) },
        o.returned_santim > 0 && { label: 'Returned', value: money(o.returned_santim) },
        o.refunded_santim > 0 && { label: 'You gave back', value: money(o.refunded_santim) },
      ]} />

      <section className="card mt-5 p-4">
        <h2 className="mb-3 font-semibold text-stone-900">Items</h2>
        <div className="relative overflow-x-auto">
          <table className="rtable rtable-flush w-full text-left text-sm">
            <thead className="text-xs text-stone-500 uppercase">
              <tr><th className="py-2 pr-3">Item</th><th className="py-2 pr-3 text-right">Quantity</th><th className="py-2 pr-3 text-right">Unit price</th><th className="py-2 text-right">Line total</th></tr>
            </thead>
            <tbody className="divide-y divide-stone-100">
              {o.items.map((i) => (
                <tr key={i.id}>
                  <td className="rtable-title py-2 pr-3">
                    {i.product_id ? <Link to={`/seller/erp/inventory/${i.product_id}`} className="hover:underline">{i.description}</Link> : i.description}
                    {i.returned > 0 && <span className="block text-xs font-normal text-stone-500">{i.returned} returned</span>}
                  </td>
                  <td data-label="Quantity" className="py-2 pr-3 text-right tabular-nums">{i.quantity}</td>
                  <td data-label="Unit price" className="py-2 pr-3 text-right tabular-nums">{money(i.unit_price_santim)}</td>
                  <td data-label="Line total" className="py-2 text-right font-semibold tabular-nums">{money(i.line_total_santim)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <dl className="mt-3 ml-auto max-w-xs space-y-1 text-sm">
          <div className="flex justify-between"><dt>Items</dt><dd className="tabular-nums">{money(o.items_total_santim)}</dd></div>
          {o.delivery_santim > 0 && <div className="flex justify-between"><dt>Delivery</dt><dd className="tabular-nums">{money(o.delivery_santim)}</dd></div>}
          <div className="flex justify-between border-t border-stone-200 pt-1 font-bold"><dt>Total</dt><dd className="tabular-nums">{money(o.total_santim)}</dd></div>
        </dl>
      </section>

      <section className="card mt-5 p-4">
        <h2 className="mb-2 font-semibold text-stone-900">Customer</h2>
        <dl className="grid gap-3 text-sm sm:grid-cols-3">
          <div><dt className="text-xs text-stone-500">Name</dt><dd>{o.customer.name || 'Not shown'}</dd></div>
          <div><dt className="text-xs text-stone-500">Area</dt><dd>{o.customer.area || 'Not set'}</dd></div>
          <div><dt className="text-xs text-stone-500">{o.fulfillment === 'pickup' ? 'Picked up' : 'Delivered'}</dt><dd>{o.delivered_at ? o.date : 'Not yet'}</dd></div>
        </dl>
        <p className="mt-2 text-xs text-stone-500">Phone numbers and street addresses stay on the order page, where they are needed for delivery.</p>
      </section>

      <ReturnList returns={o.returns} readOnly={readOnly} onDone={(body) => res.setData(body)} />
    </>
  )
}
