import { ArrowLeft } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ErrorState, PageHeader, Spinner, Stats } from '../../../components/ui'
import { api } from '../../../lib/api'
import { useFetch } from '../../../lib/useFetch'
import { MovementTable } from './ErpItem'
import { LocationForm, TransferTable } from './ErpWarehouses'
import { Err, itemName, money, useReadOnly, useSubmit } from './shared'

export default function ErpWarehouse() {
  const { id } = useParams()
  const readOnly = useReadOnly()
  const res = useFetch(`/seller/erp/warehouses/${id}`)
  const all = useFetch('/seller/erp/warehouses')
  const [editing, setEditing] = useState(false)
  const [closing, setClosing] = useState(false)
  const { busy, errors, submit } = useSubmit()
  const w = res.data?.data

  if (res.loading && !w) return <Spinner />
  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />

  const others = (all.data?.data || []).filter((x) => x.is_active && x.id !== w.id)
  const toggle = async () => {
    const body = await submit(() => api.post(`/seller/erp/warehouses/${w.id}/${w.is_active ? 'close' : 'reopen'}`), w.is_active ? 'Location closed' : 'Location reopened')
    if (body) {
      setClosing(false)
      res.reload()
    }
  }

  return (
    <>
      <Link to="/seller/erp/warehouses" className="mb-3 inline-flex items-center gap-1 text-sm text-stone-600 hover:underline"><ArrowLeft className="size-4" aria-hidden="true" />Warehouses</Link>
      <PageHeader title={w.name}
        subtitle={<span className="inline-flex flex-wrap items-center gap-2">
          {w.is_default && <span className="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-800">Main location</span>}
          {!w.is_active && <span className="rounded-full bg-stone-200 px-2 py-0.5 text-xs font-semibold text-stone-700">Closed</span>}
          {w.code && <span>{w.code}</span>}
        </span>}
        actions={!readOnly && <>
          {w.is_active && w.units > 0 && others.length > 0 && <Link to={`/seller/erp/transfers/new?from=${w.id}`} className="btn-primary">Move stock from here</Link>}
          {!editing && <button type="button" className="btn-secondary" onClick={() => setEditing(true)}>Edit</button>}
          {!w.is_default && (w.is_active
            ? <button type="button" className="btn-ghost" onClick={() => setClosing(true)} disabled={closing}>Close location</button>
            : <button type="button" className="btn-secondary" onClick={toggle} disabled={busy}>Reopen</button>)}
        </>} />

      {closing && (
        <div className="mb-4 space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" role="alertdialog" aria-label="Close this location">
          <p>A closed location keeps its history but cannot take stock, transfers or deliveries. It must be empty first, with nothing on the way to or from it.</p>
          <Err errors={errors} />
          <div className="flex justify-end gap-2">
            <button type="button" className="btn-ghost" onClick={() => setClosing(false)} disabled={busy}>Back</button>
            <button type="button" className="btn-danger" onClick={toggle} disabled={busy}>{busy ? 'Closing...' : 'Close location'}</button>
          </div>
        </div>
      )}
      {!closing && <Err errors={errors} />}

      {editing ? (
        <section className="card mb-5 p-4">
          <h2 className="mb-3 font-semibold text-stone-900">Location details</h2>
          <LocationForm location={w} onDone={() => { setEditing(false); res.reload() }} onCancel={() => setEditing(false)} />
        </section>
      ) : (w.address_line || w.contact_name || w.phone || w.notes) && (
        <dl className="card mb-5 grid gap-3 p-4 text-sm sm:grid-cols-2">
          {w.address_line && <div><dt className="text-xs text-stone-500">Address</dt><dd>{w.address_line}</dd></div>}
          {w.contact_name && <div><dt className="text-xs text-stone-500">Person in charge</dt><dd>{w.contact_name}</dd></div>}
          {w.phone && <div><dt className="text-xs text-stone-500">Phone</dt><dd><a href={`tel:${w.phone.replace(/\s/g, '')}`} className="text-brand-700 hover:underline">{w.phone}</a></dd></div>}
          {w.notes && <div><dt className="text-xs text-stone-500">Notes</dt><dd>{w.notes}</dd></div>}
        </dl>
      )}

      <Stats items={[
        { label: 'Units here', value: w.units },
        { label: 'Items', value: w.items },
        { label: 'Value at cost', value: money(w.value_cost_santim), hint: w.missing_cost ? `${w.missing_cost} items have no cost price` : undefined, tone: w.missing_cost ? 'warn' : undefined },
      ]} />
      <p className="mt-2 text-xs text-stone-500">Units held for open orders are not counted at any location.</p>

      <section className="card mt-5 p-4">
        <h2 className="mb-3 font-semibold text-stone-900">Stock here</h2>
        {w.stock.length === 0 ? <p className="text-sm text-stone-500">Nothing is kept here right now.</p> : (
          <div className="relative overflow-x-auto">
            <table className="rtable rtable-flush w-full text-left text-sm">
              <thead className="text-xs text-stone-500 uppercase"><tr><th className="py-2 pr-3">Item</th><th className="py-2 pr-3 text-right">Here</th><th className="py-2 pr-3 text-right">All locations</th><th className="py-2 text-right">Value at cost</th></tr></thead>
              <tbody className="divide-y divide-stone-100">
                {w.stock.map((s) => (
                  <tr key={`${s.product_id}-${s.variant_id}`}>
                    <td className="rtable-title py-2 pr-3"><Link to={`/seller/erp/inventory/${s.product_id}`} className="hover:underline">{itemName(s)}</Link>{s.sku && <span className="ml-2 text-xs font-normal text-stone-500">{s.sku}</span>}</td>
                    <td data-label="Here" className="py-2 pr-3 text-right font-semibold tabular-nums">{s.quantity}</td>
                    <td data-label="All locations" className="py-2 pr-3 text-right tabular-nums">{s.available}</td>
                    <td data-label="Value at cost" className="py-2 text-right tabular-nums">{s.cost_price_santim === null ? <span className="text-amber-800">No cost</span> : money(s.cost_price_santim * s.quantity)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <div className="mt-5 grid grid-cols-[minmax(0,1fr)] gap-5 lg:grid-cols-2">
        <section className="card p-4">
          <h2 className="mb-3 font-semibold text-stone-900">Transfers</h2>
          {w.transfers.length === 0 ? <p className="text-sm text-stone-500">No stock moved to or from here yet.</p> : <TransferTable transfers={w.transfers} compact />}
        </section>
        <section className="card p-4">
          <h2 className="mb-3 font-semibold text-stone-900">Deliveries from suppliers</h2>
          {w.receipts.length === 0 ? <p className="text-sm text-stone-500">No goods received here yet.</p> : (
            <ul className="divide-y divide-stone-100 text-sm">
              {w.receipts.map((r) => (
                <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                  <span><Link to={`/seller/erp/purchases/${r.purchase_order.id}`} className="font-semibold text-brand-700 hover:underline">{r.purchase_order.number}</Link> <span className="text-stone-500">{r.number} · {r.supplier}</span></span>
                  <span className="text-stone-600">{r.units} units · {r.received_on}</span>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>

      <section className="card mt-5 p-4">
        <div className="mb-3 flex items-center justify-between"><h2 className="font-semibold text-stone-900">Latest stock changes here</h2><Link to={`/seller/erp/movements?warehouse_id=${w.id}`} className="text-sm font-semibold text-brand-700 hover:underline">All of them</Link></div>
        <MovementTable movements={w.movements} withProduct />
      </section>
    </>
  )
}
