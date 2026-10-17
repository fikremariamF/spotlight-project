import { Printer } from 'lucide-react'
import { useEffect } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ErrorState, Spinner } from '../../../components/ui'
import { useConfig } from '../../../context/ConfigContext'
import { useFetch } from '../../../lib/useFetch'
import { PAY_METHODS, money } from './shared'

/**
 * An invoice for a shop sale, or a receipt for a marketplace order, to print or save as PDF without
 * the site around it. A marketplace receipt carries the customer's name and area only.
 */
export default function ErpSalePrint({ marketplace = false }) {
  const { id } = useParams()
  const brand = useConfig().name
  const res = useFetch(`/seller/erp/sales/${marketplace ? 'orders' : 'offline'}/${id}`)
  const s = res.data?.data
  useEffect(() => {
    if (s) document.title = `${s.number} - ${s.store.name}`
  }, [s])

  if (res.loading && !s) return <Spinner />
  if (res.error) return <div className="p-6"><ErrorState error={res.error} onRetry={res.reload} /></div>

  const voided = s.status === 'voided'
  const payments = marketplace ? [] : s.payments.filter((p) => p.status === 'recorded')
  const returns = s.returns.filter((r) => r.status === 'recorded')
  const back = marketplace ? `/seller/erp/sales/orders/${s.id}` : `/seller/erp/sales/offline/${s.id}`

  return (
    <div className="min-h-screen bg-stone-100 py-6 print:bg-white print:py-0">
      <div className="mx-auto mb-4 flex max-w-3xl justify-end gap-2 px-4 print:hidden">
        <Link to={back} className="btn-ghost">Back to the sale</Link>
        <button type="button" className="btn-primary" onClick={() => window.print()}><Printer className="size-4" aria-hidden="true" />Print or save as PDF</button>
      </div>
      <article className="relative mx-auto max-w-3xl bg-white p-6 text-sm text-stone-900 shadow-sm sm:p-10 print:max-w-none print:p-0 print:shadow-none">
        {voided && <p className="mb-4 rounded border-2 border-red-700 px-3 py-1 text-center text-base font-bold tracking-widest text-red-700 uppercase">Voided</p>}
        <header className="flex flex-col gap-4 border-b border-stone-300 pb-4 sm:flex-row sm:justify-between">
          <div>
            <p className="text-lg font-bold">{s.store.name}</p>
            {s.store.address && <p>{s.store.address}</p>}
            {s.store.phone && <p>Phone: {s.store.phone}</p>}
            {s.store.tin && <p>TIN: {s.store.tin}</p>}
          </div>
          <div className="sm:text-right">
            <h1 className="text-xl font-bold uppercase">{marketplace ? 'Order receipt' : 'Sales invoice'}</h1>
            <p className="text-base font-semibold">{s.number}</p>
            <p>Date: {s.date}</p>
            {!marketplace && s.due_date && s.balance_santim > 0 && <p>Pay by: {s.due_date}</p>}
            {marketplace && <p className="text-stone-600">Ordered on {brand}</p>}
          </div>
        </header>

        <section className="border-b border-stone-300 py-4">
          <h2 className="text-xs font-semibold text-stone-500 uppercase">{marketplace ? 'Customer' : 'Bill to'}</h2>
          <p className="font-semibold">{s.customer.name || (marketplace ? 'Customer' : 'Walk-in customer')}</p>
          {marketplace && s.customer.area && <p>{s.customer.area}</p>}
          {!marketplace && s.customer_phone && <p>Phone: {s.customer_phone}</p>}
          {!marketplace && s.customer_tin && <p>TIN: {s.customer_tin}</p>}
        </section>

        <table className="mt-4 w-full border-collapse text-left">
          <thead>
            <tr className="border-b border-stone-400 text-xs uppercase">
              <th className="py-2 pr-2">#</th><th className="py-2 pr-2">Item</th><th className="py-2 pr-2 text-right">Qty</th><th className="py-2 pr-2 text-right">Unit price</th>
              {!marketplace && <th className="py-2 pr-2 text-right">Discount</th>}
              <th className="py-2 text-right">Amount</th>
            </tr>
          </thead>
          <tbody>
            {s.items.map((i, n) => (
              <tr key={i.id} className="border-b border-stone-200 align-top">
                <td className="py-1.5 pr-2">{n + 1}</td>
                <td className="py-1.5 pr-2">{i.description}</td>
                <td className="py-1.5 pr-2 text-right tabular-nums">{i.quantity}</td>
                <td className="py-1.5 pr-2 text-right tabular-nums">{money(i.unit_price_santim)}</td>
                {!marketplace && <td className="py-1.5 pr-2 text-right tabular-nums">{i.discount_santim ? money(i.discount_santim) : ''}</td>}
                <td className="py-1.5 text-right tabular-nums">{money(i.line_total_santim)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <dl className="mt-3 ml-auto w-full max-w-72 space-y-1">
          <div className="flex justify-between"><dt>Items</dt><dd className="tabular-nums">{money(marketplace ? s.items_total_santim : s.subtotal_santim)}</dd></div>
          {!marketplace && s.discount_santim > 0 && <div className="flex justify-between"><dt>Discount</dt><dd className="tabular-nums">-{money(s.discount_santim)}</dd></div>}
          {s.delivery_santim > 0 && <div className="flex justify-between"><dt>Delivery</dt><dd className="tabular-nums">{money(s.delivery_santim)}</dd></div>}
          <div className="flex justify-between border-t border-stone-400 pt-1 text-base font-bold"><dt>Total</dt><dd className="tabular-nums">{money(s.total_santim)}</dd></div>
          {marketplace ? (
            <div className="flex justify-between"><dt>Paid</dt><dd>{s.payment_method === 'online' ? `Online through ${brand}` : s.status === 'delivered' ? 'Cash on delivery' : 'Cash on delivery, not yet paid'}</dd></div>
          ) : !voided && (
            <>
              {s.credited_santim > 0 && <div className="flex justify-between"><dt>Returned</dt><dd className="tabular-nums">-{money(s.credited_santim)}</dd></div>}
              <div className="flex justify-between"><dt>Paid</dt><dd className="tabular-nums">{money(s.paid_santim)}</dd></div>
              <div className="flex justify-between font-semibold"><dt>{s.balance_santim < 0 ? 'Credit owed to the customer' : 'Balance due'}</dt><dd className="tabular-nums">{money(Math.abs(s.balance_santim))}</dd></div>
            </>
          )}
        </dl>

        {payments.length > 0 && (
          <section className="mt-6">
            <h2 className="mb-1 text-xs font-semibold text-stone-500 uppercase">Payments received</h2>
            <ul className="space-y-0.5">
              {payments.map((p) => <li key={p.id} className="flex justify-between gap-4"><span>{p.paid_on}, {PAY_METHODS[p.method] || p.method}{p.reference ? ` (${p.reference})` : ''}</span><span className="tabular-nums">{money(p.amount_santim)}</span></li>)}
            </ul>
          </section>
        )}
        {returns.length > 0 && (
          <section className="mt-6">
            <h2 className="mb-1 text-xs font-semibold text-stone-500 uppercase">Returns</h2>
            <ul className="space-y-0.5">
              {returns.map((r) => (
                <li key={r.id}>
                  {r.number}, {r.returned_on}: {r.items.map((x) => `${x.quantity} x ${x.description}`).join(', ')}, credit {money(r.credit_santim)}
                  {r.refund_santim > 0 && `, ${money(r.refund_santim)} given back`}
                </li>
              ))}
            </ul>
          </section>
        )}
        {!marketplace && s.note && <p className="mt-6 whitespace-pre-line"><span className="font-semibold">Note:</span> {s.note}</p>}

        <footer className="mt-12 space-y-6 text-xs text-stone-600">
          <div className="grid grid-cols-2 gap-8">
            <p className="border-t border-stone-400 pt-1">Issued by{!marketplace && s.by ? `: ${s.by}` : ''}</p>
            <p className="border-t border-stone-400 pt-1">Received by the customer</p>
          </div>
          <p>This document is not a fiscal (tax) receipt.</p>
        </footer>
      </article>
    </div>
  )
}
