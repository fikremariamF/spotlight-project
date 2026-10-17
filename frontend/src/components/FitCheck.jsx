import { CheckCircle2, CircleAlert, CircleHelp, CircleX, DoorOpen } from 'lucide-react'
import { useState } from 'react'
import { useAuth } from '../context/AuthContext'
import { api, parseError } from '../lib/api'
import { checkFit, readSpace, writeSpace } from '../lib/fit'
import { useFetch } from '../lib/useFetch'

const LOOK = {
  fits: { icon: CheckCircle2, tone: 'text-emerald-700', text: 'Fits through your door' },
  tight: { icon: CircleAlert, tone: 'text-amber-700', text: 'Fits, but only just' },
  no: { icon: CircleX, tone: 'text-red-700', text: 'May not fit through' },
  unknown: { icon: CircleHelp, tone: 'text-stone-500', text: 'Add your door size to check' },
}

const FIELDS = ['door_width_cm', 'stair_width_cm', 'lift_door_width_cm', 'floor', 'has_lift']
const pick = (src) => Object.fromEntries(FIELDS.map((k) => [k, src?.[k] ?? null]))
const hasSizes = (s) => Boolean(s && (s.door_width_cm || s.stair_width_cm || s.lift_door_width_cm))

function SpaceForm({ initial, onSave, onCancel, saving, error }) {
  const [f, setF] = useState(() => ({ ...pick(initial), floor: initial?.floor ?? '', has_lift: initial?.has_lift ?? null }))
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value })
  const upstairs = Number(f.floor) > 0
  const toNum = (v) => (v === '' || v === null ? null : Number(v))

  function submit(e) {
    e.preventDefault()
    onSave({
      door_width_cm: toNum(f.door_width_cm), stair_width_cm: toNum(f.stair_width_cm),
      lift_door_width_cm: upstairs && f.has_lift ? toNum(f.lift_door_width_cm) : null,
      floor: toNum(f.floor), has_lift: upstairs ? f.has_lift : null,
    })
  }

  const box = (key, label) => (
    <label className="block text-xs text-stone-600">
      {label}
      <input className="input mt-1" type="number" inputMode="numeric" min={40} max={400} value={f[key] ?? ''} onChange={set(key)} />
    </label>
  )

  return (
    <form onSubmit={submit} className="mt-3 space-y-3 rounded-xl bg-stone-50 p-3" noValidate>
      <p className="text-xs text-stone-600">Measure the narrowest point in centimetres, frame to frame.</p>
      <div className="grid grid-cols-2 gap-3">
        {box('door_width_cm', 'Front door width')}
        <label className="block text-xs text-stone-600">
          Floor
          <input className="input mt-1" type="number" inputMode="numeric" min={0} max={60} value={f.floor ?? ''} onChange={set('floor')} placeholder="0 = ground" />
        </label>
      </div>
      {upstairs && (
        <fieldset className="flex gap-4 text-sm">
          <legend className="mb-1 text-xs text-stone-600">Is there a lift?</legend>
          <label className="flex items-center gap-1.5"><input type="radio" name="fit-lift" checked={f.has_lift === true} onChange={() => setF({ ...f, has_lift: true })} />Yes</label>
          <label className="flex items-center gap-1.5"><input type="radio" name="fit-lift" checked={f.has_lift === false} onChange={() => setF({ ...f, has_lift: false })} />No</label>
        </fieldset>
      )}
      {upstairs && (
        <div className="grid grid-cols-2 gap-3">
          {box('stair_width_cm', 'Stair width')}
          {f.has_lift && box('lift_door_width_cm', 'Lift door width')}
        </div>
      )}
      {error && <p className="text-xs font-medium text-red-700">{error}</p>}
      <div className="flex gap-2">
        <button type="submit" className="btn-primary px-3 py-1.5" disabled={saving || (upstairs && f.has_lift === null)}>{saving ? 'Saving...' : 'Check'}</button>
        <button type="button" className="btn-ghost px-3 py-1.5" onClick={onCancel}>Cancel</button>
      </div>
    </form>
  )
}

/**
 * "Will it fit?" on a listing. Uses the door, stair and lift sizes of the customer's default
 * address, or sizes kept in this browser for everyone else.
 */
export default function FitCheck({ dimensions }) {
  const { user } = useAuth()
  const addresses = useFetch(user?.role === 'customer' ? '/addresses' : null)
  const address = addresses.data?.data?.find((a) => a.is_default)
  const [local, setLocal] = useState(readSpace)
  const [editing, setEditing] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)

  if (!dimensions?.width_cm || !dimensions?.depth_cm || !dimensions?.height_cm) return null
  const space = address && (hasSizes(address) || !hasSizes(local)) ? address : local
  const result = checkFit(dimensions, space)
  const look = LOOK[result.status]

  async function save(next) {
    setError(null)
    if (address) {
      setSaving(true)
      try {
        await api.put(`/addresses/${address.id}`, next)
        addresses.reload()
        setEditing(false)
      } catch (err) {
        const p = parseError(err)
        setError(Object.values(p.fields)[0] || p.message)
      } finally {
        setSaving(false)
      }
      return
    }
    writeSpace(next)
    setLocal(next)
    setEditing(false)
  }

  return (
    <div className="flex gap-3 p-4 text-sm" aria-live="polite">
      <DoorOpen className="mt-0.5 size-5 shrink-0 text-brand-700" aria-hidden="true" />
      <div className="min-w-0 flex-1">
        <p className="font-semibold text-stone-900">Will it fit?</p>
        <p className={`mt-1 flex items-center gap-1.5 font-medium ${look.tone}`}>
          <look.icon className="size-4 shrink-0" aria-hidden="true" />{look.text}
        </p>
        {result.checks.length > 0 && (
          <ul className="mt-1 space-y-0.5 text-xs text-stone-600">
            {result.checks.filter((c) => c.room !== null).map((c) => (
              <li key={c.key}>{c.label}: {c.room < 0 ? `about ${Math.ceil(-c.room)} cm too big` : `${Math.floor(c.room)} cm to spare`}</li>
            ))}
          </ul>
        )}
        {result.note && <p className="mt-1 text-xs text-stone-600">{result.note}</p>}
        {result.status === 'no' && <p className="mt-1 text-xs text-stone-600">Ask Betoch Support whether it comes apart or can be built on site.</p>}
        {!editing && (
          <button type="button" className="mt-1 text-xs font-semibold text-brand-700 hover:underline" onClick={() => setEditing(true)}>
            {hasSizes(space) ? `Change sizes${space === address ? ` (${address.label || 'default address'})` : ''}` : 'Add your door and stair sizes'}
          </button>
        )}
        {editing && <SpaceForm initial={space} onSave={save} onCancel={() => setEditing(false)} saving={saving} error={error} />}
        <p className="mt-1 text-[11px] text-stone-400">A guide from the listed size, carried on its side. Tight turns on stairs can still be a problem.</p>
      </div>
    </div>
  )
}
