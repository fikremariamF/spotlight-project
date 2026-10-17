import { ArrowLeft, ArrowRight, X } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ErrorState, PageHeader, Spinner } from '../../../components/ui'
import { api } from '../../../lib/api'
import { formatDateTime, newIdempotencyKey } from '../../../lib/format'
import { useFetch } from '../../../lib/useFetch'
import { Badge, Err, ItemPicker, TRANSFER_STATUS, itemKey, itemName, useReadOnly, useSubmit } from './shared'

const back = <Link to="/seller/erp/warehouses" className="mb-3 inline-flex items-center gap-1 text-sm text-stone-600 hover:underline"><ArrowLeft className="size-4" aria-hidden="true" />Warehouses</Link>

/** Move stock from one location to another. */
export function ErpTransferNew() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const readOnly = useReadOnly()
  const places = useFetch('/seller/erp/warehouses')
  const open = (places.data?.data || []).filter((w) => w.is_active)
  const [from, setFrom] = useState(params.get('from') || '')
  const [to, setTo] = useState('')
  const [lines, setLines] = useState([])
  const [note, setNote] = useState('')
  const [now, setNow] = useState(false)
  const [requestId] = useState(newIdempotencyKey)
  const { busy, errors, lines: lineErrors, submit } = useSubmit()

  if (places.loading && !places.data) return <Spinner />
  if (places.error) return <ErrorState error={places.error} onRetry={places.reload} />

  // A location from the link (or picked before) only counts while it is still open.
  const isOpen = (x) => open.some((w) => String(w.id) === x)
  const source = isOpen(from) ? from : String(open.find((w) => w.is_default)?.id ?? open[0]?.id ?? '')
  const target = isOpen(to) && to !== source ? to : String(open.find((w) => String(w.id) !== source)?.id ?? '')
  const valid = source && target && source !== target && lines.length > 0 && lines.every((l) => /^\d+$/.test(l.quantity) && Number(l.quantity) > 0 && Number(l.quantity) <= l.at)

  async function send(e) {
    e.preventDefault()
    const body = await submit(() => api.post('/seller/erp/transfers', {
      from_warehouse_id: Number(source), to_warehouse_id: Number(target), note: note.trim() || null, received_now: now, request_id: requestId,
      items: lines.map((l) => ({ product_id: l.product_id, variant_id: l.variant_id, quantity: Number(l.quantity) })),
    }), now ? 'Stock moved' : 'Transfer sent')
    if (body) navigate(`/seller/erp/transfers/${body.data.id}`, { replace: true })
  }

  if (open.length < 2) {
    return (<>{back}<PageHeader title="Move stock" /><p className="card p-4 text-sm text-stone-600">You need two open locations to move stock. <Link to="/seller/erp/warehouses" className="font-semibold text-brand-700 underline">Add a location</Link> first.</p></>)
  }

  return (
    <>
      {back}
      <PageHeader title="Move stock" subtitle="Units leave the first location now. Until they are received they are on the way and cannot be sold." />
      {readOnly ? <p className="card p-4 text-sm text-stone-600">Your store cannot change records right now.</p> : (
        <form noValidate onSubmit={send} className="card space-y-4 p-4">
          <div className="grid items-end gap-3 sm:grid-cols-[1fr_auto_1fr]">
            <label className="text-sm text-stone-700" htmlFor="tr-from">From
              <select id="tr-from" className="input mt-1" value={source} onChange={(e) => { setFrom(e.target.value); setLines([]) }}>
                {open.map((w) => <option key={w.id} value={w.id}>{w.name} ({w.units} units)</option>)}
              </select>
            </label>
            <ArrowRight className="mx-auto mb-3 hidden size-5 text-stone-400 sm:block" aria-hidden="true" />
            <label className="text-sm text-stone-700" htmlFor="tr-to">To
              <select id="tr-to" className="input mt-1" value={target} onChange={(e) => setTo(e.target.value)}>
                {open.filter((w) => String(w.id) !== source).map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
              </select>
            </label>
          </div>
          {lines.length > 0 && (
            <ul className="divide-y divide-stone-100 rounded-lg border border-stone-200">
              {lines.map((l, i) => (
                <li key={itemKey(l)} className="flex flex-wrap items-center gap-3 px-3 py-2">
                  <span className="min-w-0 flex-1 text-sm">
                    <span className="block font-medium text-stone-900">{itemName(l)}</span>
                    <span className="text-xs text-stone-500">{l.at} at {open.find((w) => String(w.id) === source)?.name}</span>
                    {lineErrors[i] && <span role="alert" className="block text-xs font-medium text-red-700">{lineErrors[i]}</span>}
                  </span>
                  <label className="text-xs text-stone-600" htmlFor={`tr-q-${i}`}>Units
                    <input id={`tr-q-${i}`} className="input mt-0.5 w-24" inputMode="numeric" value={l.quantity}
                      aria-invalid={Number(l.quantity) > l.at || lineErrors[i] ? true : undefined}
                      onChange={(e) => setLines(lines.map((x, j) => (j === i ? { ...x, quantity: e.target.value.trim() } : x)))} />
                  </label>
                  <button type="button" className="icon-btn" aria-label={`Remove ${itemName(l)}`} onClick={() => setLines(lines.filter((_, j) => j !== i))}><X className="size-4" aria-hidden="true" /></button>
                </li>
              ))}
            </ul>
          )}
          {lines.length < 50 && (
            <ItemPicker key={source} warehouseId={source} added={lines.map(itemKey)} label={lines.length ? 'Add more items' : 'Choose items to move'}
              onPick={(it) => setLines([...lines, { ...it, at: it.at_location ?? 0, quantity: it.at_location > 0 ? '1' : '0' }])} />
          )}
          {lines.some((l) => Number(l.quantity) > l.at) && <p className="text-xs font-medium text-red-700">You can move at most the units at the first location.</p>}
          <label className="block text-sm text-stone-700" htmlFor="tr-note">Note
            <input id="tr-note" className="input mt-1" maxLength={255} value={note} onChange={(e) => setNote(e.target.value)} placeholder="For example who is taking it" />
          </label>
          <label className="flex items-start gap-2 text-sm">
            <input type="checkbox" className="mt-0.5 size-4 accent-brand-700" checked={now} onChange={(e) => setNow(e.target.checked)} />
            <span>It has already arrived<span className="block text-xs text-stone-500">Records the move and the arrival together, for example for a shelf in the same building.</span></span>
          </label>
          <Err errors={errors} />
          <div className="flex justify-end"><button type="submit" className="btn-primary" disabled={busy || !valid}>{busy ? 'Saving...' : now ? 'Move stock' : 'Send transfer'}</button></div>
        </form>
      )}
    </>
  )
}

/** One transfer, with receiving and cancelling while it is on the way. */
export default function ErpTransfer() {
  const { id } = useParams()
  const readOnly = useReadOnly()
  const res = useFetch(`/seller/erp/transfers/${id}`)
  const t = res.data?.data
  const [mode, setMode] = useState(null)
  const [got, setGot] = useState({})
  const { busy, errors, submit } = useSubmit((body) => { res.setData(body); setMode(null) })

  if (res.loading && !t) return <Spinner />
  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />

  const arrived = (i) => (got[i.id] ?? String(i.quantity))
  const validReceive = t.items.every((i) => /^\d+$/.test(arrived(i)) && Number(arrived(i)) <= i.quantity)
  const short = t.items.reduce((n, i) => n + (validReceive ? i.quantity - Number(arrived(i)) : 0), 0)
  const moving = t.status === 'in_transit'

  return (
    <>
      {back}
      <PageHeader title={`Transfer ${t.number}`}
        subtitle={<span className="inline-flex flex-wrap items-center gap-2"><Badge map={TRANSFER_STATUS} value={t.status} /><span className="inline-flex items-center gap-1">{t.from.name}<ArrowRight className="size-3.5" aria-label="to" />{t.to.name}</span></span>}
        actions={moving && !readOnly && !mode && <>
          <button type="button" className="btn-primary" onClick={() => setMode('receive')}>Receive at {t.to.name}</button>
          <button type="button" className="btn-ghost" onClick={() => setMode('cancel')}>Cancel transfer</button>
        </>} />

      <dl className="card mb-5 grid gap-3 p-4 text-sm sm:grid-cols-3">
        <div><dt className="text-xs text-stone-500">Sent</dt><dd>{formatDateTime(t.dispatched_at)}{t.by && ` by ${t.by}`}</dd></div>
        {t.received_at && <div><dt className="text-xs text-stone-500">Received</dt><dd>{formatDateTime(t.received_at)}{t.received_by && ` by ${t.received_by}`}</dd></div>}
        {t.cancelled_at && <div><dt className="text-xs text-stone-500">Cancelled</dt><dd>{formatDateTime(t.cancelled_at)}, units back at {t.from.name}</dd></div>}
        {t.note && <div><dt className="text-xs text-stone-500">Note</dt><dd>{t.note}</dd></div>}
      </dl>

      {mode === 'cancel' && (
        <div className="mb-4 space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" role="alertdialog" aria-label="Cancel this transfer">
          <p>All {t.units} units go back to {t.from.name} and can be sold again.</p>
          <Err errors={errors} />
          <div className="flex justify-end gap-2">
            <button type="button" className="btn-ghost" onClick={() => setMode(null)} disabled={busy}>Back</button>
            <button type="button" className="btn-danger" disabled={busy} onClick={() => submit(() => api.post(`/seller/erp/transfers/${t.id}/cancel`), 'Transfer cancelled')}>{busy ? 'Saving...' : 'Cancel transfer'}</button>
          </div>
        </div>
      )}

      <form noValidate className="card p-4" onSubmit={(e) => {
        e.preventDefault()
        submit(() => api.post(`/seller/erp/transfers/${t.id}/receive`, { items: t.items.map((i) => ({ id: i.id, received_quantity: Number(arrived(i)) })) }), 'Transfer received')
      }}>
        <h2 className="mb-3 font-semibold text-stone-900">Items</h2>
        <div className="relative overflow-x-auto">
          <table className="rtable rtable-flush w-full text-left text-sm">
            <thead className="text-xs text-stone-500 uppercase"><tr><th className="py-2 pr-3">Item</th><th className="py-2 pr-3 text-right">Sent</th><th className="py-2 text-right">{mode === 'receive' ? 'Arrived' : 'Received'}</th></tr></thead>
            <tbody className="divide-y divide-stone-100">
              {t.items.map((i) => (
                <tr key={i.id}>
                  <td className="rtable-title py-2 pr-3"><Link to={`/seller/erp/inventory/${i.product_id}`} className="hover:underline">{i.description}</Link></td>
                  <td data-label="Sent" className="py-2 pr-3 text-right tabular-nums">{i.quantity}</td>
                  <td data-label={mode === 'receive' ? 'Arrived' : 'Received'} className="py-2 text-right tabular-nums">
                    {mode === 'receive' ? (
                      <input className="input w-24 text-right" inputMode="numeric" aria-label={`Units of ${i.description} that arrived`} value={arrived(i)}
                        aria-invalid={/^\d+$/.test(arrived(i)) && Number(arrived(i)) <= i.quantity ? undefined : true}
                        onChange={(e) => setGot({ ...got, [i.id]: e.target.value.trim() })} />
                    ) : i.received_quantity === null ? <span className="text-stone-500">{t.status === 'cancelled' ? 'Returned' : 'On the way'}</span>
                      : <span className={i.received_quantity < i.quantity ? 'font-semibold text-red-700' : ''}>{i.received_quantity}</span>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {mode === 'receive' && (
          <div className="mt-3 space-y-2">
            {short > 0 && <p className="rounded-lg bg-amber-50 p-2 text-sm text-amber-900">{short} {short === 1 ? 'unit did' : 'units did'} not arrive. They will not come back into stock; if they turn up later, record them as found on the item.</p>}
            <Err errors={errors} />
            <div className="flex justify-end gap-2">
              <button type="button" className="btn-ghost" onClick={() => setMode(null)} disabled={busy}>Back</button>
              <button type="submit" className="btn-primary" disabled={busy || !validReceive}>{busy ? 'Saving...' : 'Confirm received'}</button>
            </div>
          </div>
        )}
      </form>
    </>
  )
}
