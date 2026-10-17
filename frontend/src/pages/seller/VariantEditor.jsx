import { Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { api, parseError } from '../../lib/api'

const plain = (price) => (price && price.endsWith('.00') ? price.slice(0, -3) : price || '')
const blank = { color: '', size: '', material: '', price: '', stock_quantity: '' }

function OptionRow({ productId, option, tracked, first, onSaved }) {
  const isNew = !option
  const [values, setValues] = useState(isNew ? blank : {
    color: option.color || '', size: option.size || '', material: option.material || '',
    price: plain(option.own_price), stock_quantity: String(option.stock_quantity),
  })
  const [errors, setErrors] = useState({})
  const [busy, setBusy] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const id = isNew ? 'new' : option.id
  const bind = (name) => ({
    id: `opt-${id}-${name}`, value: values[name], 'aria-invalid': errors[name] ? true : undefined,
    onChange: (e) => setValues((v) => ({ ...v, [name]: e.target.value })),
  })

  async function save(e) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    const body = {
      color: values.color.trim() || null, size: values.size.trim() || null, material: values.material.trim() || null,
      price: values.price.trim().replace(/,/g, '') || null,
    }
    if (tracked && values.stock_quantity !== '') body.stock_quantity = Number(values.stock_quantity)
    // The stock shown when the page opened: if an order came in since, the server asks to check again.
    if (!isNew && tracked) body.stock_expected = option.stock_quantity
    try {
      const r = isNew
        ? await api.post(`/seller/products/${productId}/variants`, body)
        : await api.put(`/seller/products/${productId}/variants/${option.id}`, body)
      onSaved(r.data.data)
      if (isNew) setValues(blank)
    } catch (err) {
      const p = parseError(err)
      setErrors(Object.keys(p.fields).length ? p.fields : { form: p.message })
    } finally {
      setBusy(false)
    }
  }

  async function remove() {
    setBusy(true)
    try {
      const r = await api.delete(`/seller/products/${productId}/variants/${option.id}`)
      onSaved(r.data.data)
    } catch (err) {
      const p = parseError(err)
      setErrors({ form: Object.values(p.fields)[0] || p.message })
      setConfirming(false)
    } finally {
      setBusy(false)
    }
  }

  const error = Object.values(errors)[0]
  return (
    <form onSubmit={save} noValidate className="rounded-xl border border-stone-200 p-3">
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-[1fr_1fr_1fr_7rem_6rem]">
        <label className="text-xs text-stone-600" htmlFor={`opt-${id}-color`}>Color<input className="input mt-1" maxLength={60} {...bind('color')} /></label>
        <label className="text-xs text-stone-600" htmlFor={`opt-${id}-size`}>Size<input className="input mt-1" maxLength={60} {...bind('size')} /></label>
        <label className="text-xs text-stone-600" htmlFor={`opt-${id}-material`}>Material<input className="input mt-1" maxLength={100} {...bind('material')} /></label>
        <label className="text-xs text-stone-600" htmlFor={`opt-${id}-price`}>Price (ETB)<input className="input mt-1" inputMode="decimal" placeholder="Same" {...bind('price')} /></label>
        {tracked && <label className="text-xs text-stone-600" htmlFor={`opt-${id}-stock_quantity`}>In stock<input className="input mt-1" inputMode="numeric" {...bind('stock_quantity')} /></label>}
      </div>
      {isNew && first && tracked && <p className="mt-2 text-xs text-stone-500">The listing's current stock moves to this first option. Change the number here if it is different.</p>}
      {error && <p role="alert" className="mt-2 text-xs font-medium text-red-700">{error}</p>}
      <div className="mt-2 flex flex-wrap justify-end gap-2">
        {!isNew && (confirming ? (
          <>
            <span className="self-center text-xs text-stone-600">Remove this option?</span>
            <button type="button" className="btn-ghost" onClick={() => setConfirming(false)} disabled={busy}>Keep</button>
            <button type="button" className="btn-danger" onClick={remove} disabled={busy}>Remove</button>
          </>
        ) : (
          <button type="button" className="btn-ghost text-red-700" onClick={() => setConfirming(true)} disabled={busy}>
            <Trash2 className="size-4" aria-hidden="true" />Remove
          </button>
        ))}
        <button type="submit" className={isNew ? 'btn-primary' : 'btn-secondary'} disabled={busy}>
          {isNew ? <><Plus className="size-4" aria-hidden="true" />{busy ? 'Adding...' : 'Add option'}</> : busy ? 'Saving...' : 'Save option'}
        </button>
      </div>
    </form>
  )
}

/**
 * Sizes, colors and materials of a listing, each saved on its own (the rest of the form is not).
 * `onChange` gets the updated listing from the server.
 */
export default function VariantEditor({ product, tracked, onChange }) {
  const options = product.variants || []
  return (
    <section className="card space-y-3 p-5" aria-labelledby="options-title">
      <div>
        <h2 id="options-title" className="font-semibold text-stone-900">Options</h2>
        <p className="mt-1 text-sm text-stone-600">
          Sell one item in several sizes, colors or materials, each with its own stock and, if you want, its own price.
          Options are saved straight away.
        </p>
      </div>
      {options.map((o) => <OptionRow key={`${o.id}-${o.stock_quantity}-${o.own_price}`} productId={product.id} option={o} tracked={tracked} onSaved={onChange} />)}
      <OptionRow key={`new-${options.length}`} productId={product.id} tracked={tracked} first={options.length === 0} onSaved={onChange} />
    </section>
  )
}
