import { Printer } from 'lucide-react'
import { useEffect } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ErrorState, Spinner } from '../../../components/ui'
import { useFetch } from '../../../lib/useFetch'
import { PO_STATUS, money } from './shared'

/** A plain purchase order to print or save as PDF, without the site around it. */
export default function ErpPurchasePrint() {
  const { id } = useParams()
  const res = useFetch(`/seller/erp/purchases/${id}`)
  const po = res.data?.data
  useEffect(() => {
    if (po) document.title = `${po.number} - ${po.store.name}`
  }, [po])

  if (res.loading && !po) return <Spinner />
  if (res.error) return <div className="p-6"><ErrorState error={res.error} onRetry={res.reload} /></div>

  const s = po.supplier
  return (
    <div className="min-h-screen bg-stone-100 py-6 print:bg-white print:py-0">
      <div className="mx-auto mb-4 flex max-w-3xl justify-end gap-2 px-4 print:hidden">
        <Link to={`/seller/erp/purchases/${po.id}`} className="btn-ghost">Back to the order</Link>
        <button type="button" className="btn-primary" onClick={() => window.print()}><Printer className="size-4" aria-hidden="true" />Print or save as PDF</button>
      </div>
      <article className="relative mx-auto max-w-3xl bg-white p-6 text-sm text-stone-900 shadow-sm sm:p-10 print:max-w-none print:p-0 print:shadow-none">
        {['draft', 'cancelled'].includes(po.status) && (
          <p className="mb-4 rounded border-2 border-red-700 px-3 py-1 text-center text-base font-bold tracking-widest text-red-700 uppercase">{po.status === 'draft' ? 'Draft, not sent yet' : 'Cancelled'}</p>
        )}
        <header className="flex flex-col gap-4 border-b border-stone-300 pb-4 sm:flex-row sm:justify-between">
          <div>
            <p className="text-lg font-bold">{po.store.name}</p>
            {po.store.address && <p>{po.store.address}</p>}
            {po.store.phone && <p>Phone: {po.store.phone}</p>}
            {po.store.tin && <p>TIN: {po.store.tin}</p>}
          </div>
          <div className="sm:text-right">
            <h1 className="text-xl font-bold uppercase">Purchase order</h1>
            <p className="text-base font-semibold">{po.number}</p>
            <p>Date: {po.order_date}</p>
            {po.expected_date && <p>Deliver by: {po.expected_date}</p>}
            {po.supplier_reference && <p>Your reference: {po.supplier_reference}</p>}
            {!['draft', 'cancelled'].includes(po.status) && <p className="text-stone-600">{PO_STATUS[po.status]?.[0]}{po.closed_short ? ', rest not coming' : ''}</p>}
          </div>
        </header>

        <section className="grid gap-4 border-b border-stone-300 py-4 sm:grid-cols-2">
          <div>
            <h2 className="text-xs font-semibold text-stone-500 uppercase">Supplier</h2>
            <p className="font-semibold">{s.name}</p>
            {s.contact_person && <p>{s.contact_person}</p>}
            {s.address && <p>{s.address}</p>}
            {s.phone && <p>Phone: {s.phone}</p>}
            {s.email && <p>{s.email}</p>}
            {s.tin && <p>TIN: {s.tin}</p>}
          </div>
          <div>
            <h2 className="text-xs font-semibold text-stone-500 uppercase">Deliver to</h2>
            <p className="font-semibold">{po.warehouse.name}</p>
            {po.warehouse.address_line && <p>{po.warehouse.address_line}</p>}
          </div>
        </section>

        <table className="mt-4 w-full border-collapse text-left">
          <thead>
            <tr className="border-b border-stone-400 text-xs uppercase">
              <th className="py-2 pr-2">#</th><th className="py-2 pr-2">Item</th><th className="py-2 pr-2 text-right">Qty</th><th className="py-2 pr-2 text-right">Unit price</th><th className="py-2 pr-2 text-right">Discount</th><th className="py-2 text-right">Amount</th>
            </tr>
          </thead>
          <tbody>
            {po.items.map((i, n) => (
              <tr key={i.id} className="border-b border-stone-200 align-top">
                <td className="py-1.5 pr-2">{n + 1}</td>
                <td className="py-1.5 pr-2">{i.description}</td>
                <td className="py-1.5 pr-2 text-right tabular-nums">{i.quantity}</td>
                <td className="py-1.5 pr-2 text-right tabular-nums">{money(i.unit_cost_santim)}</td>
                <td className="py-1.5 pr-2 text-right tabular-nums">{i.discount_santim ? money(i.discount_santim) : ''}</td>
                <td className="py-1.5 text-right tabular-nums">{money(i.line_total_santim)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <dl className="mt-3 ml-auto w-64 space-y-1">
          <div className="flex justify-between"><dt>Subtotal</dt><dd className="tabular-nums">{money(po.subtotal_santim)}</dd></div>
          {po.discount_santim > 0 && <div className="flex justify-between"><dt>Discount</dt><dd className="tabular-nums">-{money(po.discount_santim)}</dd></div>}
          {po.charges_santim > 0 && <div className="flex justify-between"><dt>{po.charges_note || 'Charges'}</dt><dd className="tabular-nums">{money(po.charges_santim)}</dd></div>}
          <div className="flex justify-between border-t border-stone-400 pt-1 text-base font-bold"><dt>Total</dt><dd className="tabular-nums">{money(po.total_santim)}</dd></div>
        </dl>

        {(po.notes || s.payment_terms) && (
          <section className="mt-6 space-y-2">
            {s.payment_terms && <p><span className="font-semibold">Payment terms:</span> {s.payment_terms}</p>}
            {po.notes && <p className="whitespace-pre-line"><span className="font-semibold">Notes:</span> {po.notes}</p>}
          </section>
        )}

        <footer className="mt-12 grid grid-cols-2 gap-8 text-xs text-stone-600">
          <p className="border-t border-stone-400 pt-1">Ordered by{po.by ? `: ${po.by}` : ''}</p>
          <p className="border-t border-stone-400 pt-1">Received by the supplier</p>
        </footer>
      </article>
    </div>
  )
}
