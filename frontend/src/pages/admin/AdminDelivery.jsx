import { useState } from 'react'
import { Alert, ErrorState, Field, PageHeader, Spinner } from '../../components/ui'
import { api, parseError } from '../../lib/api'
import { formatETB, santimToBirr } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'

const EMPTY = { name: '', fee: '', min_days: 1, max_days: 3, notes: '', is_active: true, area_ids: [] }

function ZoneForm({ initial, areas, zones, onSaved, onCancel }) {
  const [form, setForm] = useState(initial)
  const [errors, setErrors] = useState({})
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const zoneName = Object.fromEntries(zones.map((z) => [z.id, z.name]))
  const set = (key) => (e) => setForm({ ...form, [key]: e.target.type === 'checkbox' ? e.target.checked : e.target.value })

  function toggleArea(id) {
    setForm((f) => ({ ...f, area_ids: f.area_ids.includes(id) ? f.area_ids.filter((x) => x !== id) : [...f.area_ids, id] }))
  }

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    const payload = { ...form, min_days: Number(form.min_days), max_days: Number(form.max_days), notes: form.notes || null }
    try {
      if (form.id) await api.put(`/admin/delivery-zones/${form.id}`, payload)
      else await api.post('/admin/delivery-zones', payload)
      onSaved(form.id ? 'Delivery zone updated.' : 'Delivery zone created.')
    } catch (err) {
      const parsed = parseError(err)
      setErrors(parsed.fields)
      setError(Object.values(parsed.fields)[0] || parsed.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} className="card space-y-3 p-4" noValidate>
      {error && <Alert tone="error">{error}</Alert>}
      <div className="grid gap-3 sm:grid-cols-4">
        <div className="sm:col-span-2">
          <Field label="Zone name" name="z-name" error={errors.name}>
            <input id="z-name" className="input" maxLength={100} value={form.name} onChange={set('name')} />
          </Field>
        </div>
        <Field label="Delivery fee (ETB)" name="z-fee" error={errors.fee}>
          <input id="z-fee" className="input" inputMode="decimal" value={form.fee} onChange={set('fee')} />
        </Field>
        <div className="grid grid-cols-2 gap-2">
          <Field label="Min days" name="z-min" error={errors.min_days}>
            <input id="z-min" type="number" min="0" max="60" className="input" value={form.min_days} onChange={set('min_days')} />
          </Field>
          <Field label="Max days" name="z-max" error={errors.max_days}>
            <input id="z-max" type="number" min="0" max="60" className="input" value={form.max_days} onChange={set('max_days')} />
          </Field>
        </div>
      </div>
      <Field label="Notes shown to customers (optional)" name="z-notes" error={errors.notes}>
        <input id="z-notes" className="input" maxLength={500} value={form.notes || ''} onChange={set('notes')} />
      </Field>
      <fieldset>
        <legend className="label">Sub-cities in this zone</legend>
        <div className="grid gap-1 sm:grid-cols-3">
          {areas.map((a) => {
            const elsewhere = a.delivery_zone_id && a.delivery_zone_id !== form.id
            return (
              <label key={a.id} className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={form.area_ids.includes(a.id)} onChange={() => toggleArea(a.id)} />
                {a.name}
                {elsewhere && !form.area_ids.includes(a.id) && <span className="text-xs text-stone-500">({zoneName[a.delivery_zone_id]})</span>}
              </label>
            )
          })}
        </div>
        <p className="mt-1 text-xs text-stone-500">Ticking a sub-city that belongs to another zone moves it here.</p>
      </fieldset>
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={form.is_active} onChange={set('is_active')} /> Active
      </label>
      <div className="flex gap-2">
        <button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Saving...' : 'Save zone'}</button>
        <button type="button" className="btn-ghost" onClick={onCancel}>Cancel</button>
      </div>
    </form>
  )
}

export default function AdminDelivery() {
  const list = useFetch('/admin/delivery-zones')
  const [editing, setEditing] = useState(null)
  const [notice, setNotice] = useState(null)

  const zones = list.data?.data.zones || []
  const areas = list.data?.data.areas || []
  const unassigned = areas.filter((a) => a.is_active && !a.delivery_zone_id)

  async function remove(z) {
    if (!window.confirm(`Delete "${z.name}"? Its sub-cities will show "fee confirmed by the seller" until assigned to another zone.`)) return
    setNotice(null)
    try {
      await api.delete(`/admin/delivery-zones/${z.id}`)
      setNotice({ tone: 'success', text: 'Delivery zone deleted.' })
    } catch (err) {
      setNotice({ tone: 'error', text: parseError(err).message })
    }
    list.reload()
  }

  return (
    <>
      <PageHeader title="Delivery zones"
        subtitle="Default delivery fee and time by sub-city. A listing's own delivery fee always wins over the zone fee."
        actions={!editing && <button type="button" className="btn-primary" onClick={() => setEditing(EMPTY)}>Add zone</button>} />
      {notice && <div className="mb-4"><Alert tone={notice.tone}>{notice.text}</Alert></div>}
      {editing && (
        <div className="mb-6">
          <ZoneForm key={editing.id || 'new'} initial={editing} areas={areas} zones={zones} onCancel={() => setEditing(null)}
            onSaved={(text) => { setEditing(null); setNotice({ tone: 'success', text }); list.reload() }} />
        </div>
      )}
      {list.loading && !list.data && <Spinner />}
      {list.error && <ErrorState error={list.error} onRetry={list.reload} />}
      {list.data && (
        <>
          {unassigned.length > 0 && (
            <div className="mb-4">
              <Alert tone="warning">Not in any zone: {unassigned.map((a) => a.name).join(', ')}. Customers there see "fee confirmed by the seller".</Alert>
            </div>
          )}
          <div className="card relative overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-stone-200 text-xs text-stone-500 uppercase">
                <tr><th className="px-4 py-3">Zone</th><th className="px-4 py-3">Fee</th><th className="px-4 py-3">Days</th><th className="px-4 py-3">Sub-cities</th><th className="px-4 py-3">Status</th><th className="px-4 py-3"><span className="sr-only">Actions</span></th></tr>
              </thead>
              <tbody className="divide-y divide-stone-100">
                {zones.length === 0 && <tr><td colSpan={6} className="px-4 py-6 text-center text-stone-500">No zones yet.</td></tr>}
                {zones.map((z) => (
                  <tr key={z.id}>
                    <td className="px-4 py-3 font-medium text-stone-900">{z.name}</td>
                    <td className="px-4 py-3">{z.fee_santim === 0 ? 'Free' : formatETB(z.fee_santim)}</td>
                    <td className="px-4 py-3">{z.min_days === z.max_days ? z.min_days : `${z.min_days} to ${z.max_days}`}</td>
                    <td className="px-4 py-3 text-stone-600">{z.areas.map((a) => a.name).join(', ') || 'None'}</td>
                    <td className="px-4 py-3">{z.is_active ? 'Active' : <span className="text-stone-500">Inactive</span>}</td>
                    <td className="px-4 py-3">
                      <div className="flex justify-end gap-1">
                        <button type="button" className="btn-ghost"
                          onClick={() => setEditing({ ...EMPTY, ...z, fee: santimToBirr(z.fee_santim), notes: z.notes || '', area_ids: z.areas.map((a) => a.id) })}>Edit</button>
                        <button type="button" className="btn-ghost text-red-700" onClick={() => remove(z)}>Delete</button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </>
  )
}
