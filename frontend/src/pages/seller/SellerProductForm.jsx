import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Alert, ErrorState, Field, PageHeader, Spinner, StatusBadge } from '../../components/ui'
import { useConfig } from '../../context/ConfigContext'
import { api, parseError } from '../../lib/api'
import { formatETB } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'
import VariantEditor from './VariantEditor'

const EMPTY = {
  name: '', category_id: '', description: '', price: '', price_negotiable: false,
  condition: 'new', stock_type: 'in_stock', stock_quantity: '1', production_days: '', custom_order_available: false,
  width_cm: '', depth_cm: '', height_cm: '', material: '', color: '',
  area_id: '', offers_delivery: true, pickup_available: false, delivery_fee: '', delivery_notes: '',
}

const BOOL_FIELDS = ['price_negotiable', 'custom_order_available', 'offers_delivery', 'pickup_available']

function fromProduct(p) {
  return {
    ...EMPTY,
    name: p.name, category_id: String(p.category?.id ?? ''), description: p.description,
    price: p.price.endsWith('.00') ? p.price.slice(0, -3) : p.price,
    price_negotiable: p.price_negotiable, condition: p.condition, stock_type: p.stock_type,
    stock_quantity: String(p.stock_quantity ?? ''), production_days: String(p.production_days ?? ''),
    custom_order_available: p.custom_order_available,
    width_cm: String(p.dimensions.width_cm ?? ''), depth_cm: String(p.dimensions.depth_cm ?? ''), height_cm: String(p.dimensions.height_cm ?? ''),
    material: p.material ?? '', color: p.color ?? '', area_id: String(p.area_id ?? ''),
    offers_delivery: p.delivery.offers_delivery, pickup_available: p.delivery.pickup_available,
    delivery_fee: p.delivery.fee ? (p.delivery.fee.endsWith('.00') ? p.delivery.fee.slice(0, -3) : p.delivery.fee) : '',
    delivery_notes: p.delivery.notes ?? '',
  }
}

const isFree = (fee) => fee !== '' && fee !== null && fee !== undefined && Number(String(fee).replace(/,/g, '')) === 0

/** Send empty strings as null so optional numeric fields clear instead of failing validation. */
function payload(values) {
  const out = {}
  for (const [k, v] of Object.entries(values)) {
    if (BOOL_FIELDS.includes(k)) out[k] = Boolean(v)
    else out[k] = typeof v === 'string' && v.trim() === '' ? null : typeof v === 'string' ? v.trim() : v
  }
  out.price = out.price ? String(out.price).replace(/,/g, '') : out.price
  out.delivery_fee = out.delivery_fee ? String(out.delivery_fee).replace(/,/g, '') : out.delivery_fee
  return out
}

export default function SellerProductForm() {
  const { id } = useParams()
  const editing = Boolean(id)
  const navigate = useNavigate()
  const config = useConfig()
  const storesSetFee = Boolean(config.delivery?.stores_set_fee)
  const categories = useFetch('/categories')
  const areas = useFetch('/areas')
  const existing = useFetch(editing ? `/seller/products/${id}` : null)
  const [values, setValues] = useState(EMPTY)
  const [files, setFiles] = useState([])
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState(null)
  const [busy, setBusy] = useState(false)
  // Options are saved on their own, so the listing as the server last returned it.
  const [saved, setSaved] = useState(null)

  useEffect(() => {
    if (existing.data) {
      setValues(fromProduct(existing.data.data))
      setSaved(existing.data.data)
    }
  }, [existing.data])

  const previews = useMemo(() => files.map((f) => ({ name: f.name, url: URL.createObjectURL(f) })), [files])
  useEffect(() => () => previews.forEach((p) => URL.revokeObjectURL(p.url)), [previews])

  const categoryOptions = useMemo(() => (categories.data?.data || []).flatMap((c) => [
    { id: c.id, name: c.name },
    ...(c.children || []).map((ch) => ({ id: ch.id, name: `${c.name} / ${ch.name}` })),
  ]), [categories.data])

  const product = saved || existing.data?.data
  const hasOptions = (product?.variants || []).length > 0
  const existingImages = product?.images || []
  const remainingSlots = config.max_product_images - existingImages.length

  const bind = (name, type = 'text') => ({
    id: name, name, type,
    ...(type === 'checkbox'
      ? { checked: Boolean(values[name]), onChange: (e) => setValues((v) => ({ ...v, [name]: e.target.checked })) }
      : { value: values[name] ?? '', onChange: (e) => setValues((v) => ({ ...v, [name]: e.target.value })) }),
    'aria-invalid': errors[name] ? true : undefined,
    'aria-describedby': errors[name] ? `${name}-error` : undefined,
  })

  function pickFiles(e) {
    const chosen = Array.from(e.target.files || [])
    const tooBig = chosen.filter((f) => f.size > config.max_image_kb * 1024)
    const ok = chosen.filter((f) => f.size <= config.max_image_kb * 1024)
    setErrors((er) => ({ ...er, images: tooBig.length ? `${tooBig.map((f) => f.name).join(', ')} is larger than ${Math.round(config.max_image_kb / 1024)} MB.` : undefined }))
    setFiles((prev) => [...prev, ...ok].slice(0, Math.max(0, remainingSlots)))
    e.target.value = ''
  }

  async function save(action) {
    setBusy(true)
    setErrors({})
    setMessage(null)
    try {
      // While the marketplace prices delivery, a listing is either free to deliver or left to it.
      const data = payload(storesSetFee ? values : { ...values, delivery_fee: isFree(values.delivery_fee) ? '0' : '' })
      if (editing) {
        // With options, stock is set per option. Otherwise the server checks no order came in since the form opened.
        if (hasOptions) delete data.stock_quantity
        else data.stock_expected = product.stock_quantity
      }
      if (!editing) {
        const form = new FormData()
        Object.entries({ ...data, action }).forEach(([k, v]) => {
          if (v === null) return
          form.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : v)
        })
        files.forEach((f) => form.append('images[]', f))
        await api.post('/seller/products', form)
      } else {
        await api.put(`/seller/products/${id}`, data)
        if (files.length) {
          const form = new FormData()
          files.forEach((f) => form.append('images[]', f))
          await api.post(`/seller/products/${id}/images`, form)
        }
        if (action === 'submit') await api.post(`/seller/products/${id}/submit`)
      }
      navigate('/seller/listings', { replace: true })
    } catch (err) {
      const parsed = parseError(err)
      setErrors(parsed.fields)
      setMessage(Object.keys(parsed.fields).length ? 'Please fix the highlighted fields.' : parsed.message)
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } finally {
      setBusy(false)
    }
  }

  async function removeImage(imageId) {
    try {
      await api.delete(`/seller/products/${id}/images/${imageId}`)
      existing.reload()
    } catch (err) {
      const parsed = parseError(err)
      setMessage(Object.values(parsed.fields)[0] || parsed.message)
    }
  }

  if (existing.loading) return <Spinner />
  if (existing.error) return <ErrorState error={existing.error} />

  const madeToOrder = values.stock_type === 'made_to_order'
  const canSubmit = !product || ['draft', 'rejected'].includes(product.status)

  return (
    <div className="max-w-3xl">
      <PageHeader
        title={editing ? 'Edit listing' : 'Add a listing'}
        subtitle={product ? <span className="inline-flex items-center gap-2">Status: <StatusBadge status={product.status} /></span> : 'Listings are reviewed before they appear on the marketplace.'}
        actions={<Link to="/seller/listings" className="btn-ghost">Cancel</Link>}
      />
      {product?.status === 'published' && (
        <div className="mb-4"><Alert tone="info">Price and stock changes go live immediately. Changing the name, description, category, material or condition sends the listing back for review.</Alert></div>
      )}
      {product?.status === 'rejected' && product.moderation_note && (
        <div className="mb-4"><Alert tone="error">Reviewer note: {product.moderation_note}</Alert></div>
      )}
      {message && <div className="mb-4"><Alert tone="error">{message}</Alert></div>}

      <form className="space-y-8" noValidate onSubmit={(e) => { e.preventDefault(); save('draft') }}>
        <section className="card space-y-4 p-5">
          <h2 className="font-semibold text-stone-900">Basics</h2>
          <Field label="Name" name="name" error={errors.name}><input className="input" maxLength={160} {...bind('name')} /></Field>
          <Field label="Category" name="category_id" error={errors.category_id}>
            <select className="input" {...bind('category_id')}>
              <option value="">Choose a category</option>
              {categoryOptions.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </Field>
          <Field label="Description" name="description" error={errors.description} hint="Describe build quality, finish, what is included and any wear.">
            <textarea rows={5} className="input" maxLength={5000} {...bind('description')} />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Condition" name="condition" error={errors.condition}>
              <select className="input" {...bind('condition')}>
                <option value="new">New</option><option value="used">Used</option><option value="refurbished">Refurbished</option>
              </select>
            </Field>
            <Field label="Price (ETB)" name="price" error={errors.price} hint="For example 38500">
              <input className="input" inputMode="decimal" {...bind('price')} />
            </Field>
          </div>
          <label className="flex items-center gap-2 text-sm"><input className="size-4 accent-brand-700" {...bind('price_negotiable', 'checkbox')} /> Price is negotiable</label>
        </section>

        <section className="card space-y-4 p-5">
          <h2 className="font-semibold text-stone-900">Availability</h2>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Stock type" name="stock_type" error={errors.stock_type}>
              <select className="input" {...bind('stock_type')}>
                <option value="in_stock">Ready-made, in stock</option>
                <option value="made_to_order">Made to order</option>
              </select>
            </Field>
            {madeToOrder ? (
              <Field label="Production time (days)" name="production_days" error={errors.production_days}>
                <input className="input" inputMode="numeric" {...bind('production_days')} />
              </Field>
            ) : hasOptions ? (
              <div className="text-sm">
                <p className="label">Units in stock</p>
                <p className="mt-2 text-stone-700">{product.stock_quantity} in total, set for each option below.</p>
              </div>
            ) : (
              <Field label="Units in stock" name="stock_quantity" error={errors.stock_quantity}>
                <input className="input" inputMode="numeric" {...bind('stock_quantity')} />
              </Field>
            )}
          </div>
          <label className="flex items-center gap-2 text-sm"><input className="size-4 accent-brand-700" {...bind('custom_order_available', 'checkbox')} /> Customers can request custom sizes or finishes</label>
          {editing && <p className="text-xs text-stone-500"><a href="#options-title" className="font-semibold text-brand-700 hover:underline">Options</a> (sizes, colors, materials) are below the Save button.</p>}
        </section>

        <section className="card space-y-4 p-5">
          <h2 className="font-semibold text-stone-900">Specifications</h2>
          <div className="grid grid-cols-3 gap-3">
            <Field label="Width (cm)" name="width_cm" error={errors.width_cm}><input className="input" inputMode="numeric" {...bind('width_cm')} /></Field>
            <Field label="Depth (cm)" name="depth_cm" error={errors.depth_cm}><input className="input" inputMode="numeric" {...bind('depth_cm')} /></Field>
            <Field label="Height (cm)" name="height_cm" error={errors.height_cm}><input className="input" inputMode="numeric" {...bind('height_cm')} /></Field>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Material" name="material" error={errors.material}><input className="input" placeholder="e.g. Mahogany, Fabric" {...bind('material')} /></Field>
            <Field label="Color" name="color" error={errors.color}><input className="input" {...bind('color')} /></Field>
          </div>
        </section>

        {!editing && (
          <p className="rounded-lg bg-stone-100 px-4 py-3 text-sm text-stone-700">Selling it in several sizes, colors or materials? Save the listing first, then add options with their own stock.</p>
        )}

        <section className="card space-y-4 p-5">
          <h2 className="font-semibold text-stone-900">Delivery and pickup</h2>
          {errors.offers_delivery && <p className="text-sm font-medium text-red-700">{errors.offers_delivery}</p>}
          <div className="flex flex-col gap-2 text-sm">
            <label className="flex items-center gap-2"><input className="size-4 accent-brand-700" {...bind('offers_delivery', 'checkbox')} /> I deliver this item in Addis Ababa</label>
            <label className="flex items-center gap-2"><input className="size-4 accent-brand-700" {...bind('pickup_available', 'checkbox')} /> Customers can pick it up</label>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            {values.offers_delivery && storesSetFee && (
              <Field label="Delivery fee (ETB)" name="delivery_fee" error={errors.delivery_fee}
                hint="Leave empty and the marketplace prices it by distance or sub-city. Enter 0 for free delivery.">
                <input className="input" inputMode="decimal" {...bind('delivery_fee')} />
              </Field>
            )}
            {values.offers_delivery && !storesSetFee && (
              <div className="text-sm sm:col-span-2">
                <label className="flex items-center gap-2">
                  <input id="free-delivery" type="checkbox" className="size-4 accent-brand-700" checked={isFree(values.delivery_fee)}
                    onChange={(e) => setValues((v) => ({ ...v, delivery_fee: e.target.checked ? '0' : '' }))} />
                  Free delivery for this item
                </label>
                <p className="mt-1 text-xs text-stone-500">Otherwise the marketplace sets the delivery price from the distance or sub-city, the same for every store.</p>
                {existing.data?.data?.delivery?.fee_ignored && !isFree(values.delivery_fee) && (
                  <p className="mt-1 text-xs text-amber-800">The {formatETB(existing.data.data.delivery.fee_santim)} fee you set earlier is no longer used.</p>
                )}
                {errors.delivery_fee && <p className="mt-1 text-xs font-medium text-red-700">{errors.delivery_fee}</p>}
              </div>
            )}
            <Field label="Item location" name="area_id" error={errors.area_id} hint="Defaults to your store location">
              <select className="input" {...bind('area_id')}>
                <option value="">Store location</option>
                {(areas.data?.data || []).map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
              </select>
            </Field>
          </div>
          <Field label="Delivery notes" name="delivery_notes" error={errors.delivery_notes}>
            <input className="input" maxLength={500} placeholder="e.g. Delivered and assembled within 3 days" {...bind('delivery_notes')} />
          </Field>
        </section>

        <section className="card space-y-4 p-5">
          <h2 className="font-semibold text-stone-900">Photos</h2>
          <p className="text-sm text-stone-600">Up to {config.max_product_images} photos, JPG, PNG or WebP, max {Math.round(config.max_image_kb / 1024)} MB each. The first photo is the cover.</p>
          {existingImages.length > 0 && (
            <ul className="grid grid-cols-3 gap-3 sm:grid-cols-4">
              {existingImages.map((img) => (
                <li key={img.id} className="relative">
                  <img src={img.thumb || img.url} alt="" className="aspect-square w-full rounded-lg object-cover" />
                  <button type="button" onClick={() => removeImage(img.id)} className="absolute top-1 right-1 rounded bg-white/90 px-2 py-0.5 text-xs font-semibold text-red-700">Remove</button>
                </li>
              ))}
            </ul>
          )}
          {previews.length > 0 && (
            <ul className="grid grid-cols-3 gap-3 sm:grid-cols-4">
              {previews.map((p, i) => (
                <li key={p.url} className="relative">
                  <img src={p.url} alt={p.name} className="aspect-square w-full rounded-lg object-cover opacity-90" />
                  <button type="button" onClick={() => setFiles((f) => f.filter((_, j) => j !== i))} className="absolute top-1 right-1 rounded bg-white/90 px-2 py-0.5 text-xs font-semibold text-stone-700">Remove</button>
                </li>
              ))}
            </ul>
          )}
          {remainingSlots - files.length > 0 && (
            <input type="file" accept="image/jpeg,image/png,image/webp" multiple onChange={pickFiles} aria-label="Add photos"
              className="block w-full max-w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:font-semibold file:text-brand-800" />
          )}
          {errors.images && <p className="text-xs font-medium text-red-700">{errors.images}</p>}
        </section>

        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="submit" className="btn-secondary" disabled={busy}>{editing ? 'Save changes' : 'Save as draft'}</button>
          {canSubmit && (
            <button type="button" className="btn-primary" disabled={busy} onClick={() => save('submit')}>
              {busy ? 'Saving...' : editing ? 'Save and submit for review' : 'Submit for review'}
            </button>
          )}
        </div>
      </form>

      {editing && product && product.status !== 'archived' && (
        <div className="mt-8">
          <VariantEditor product={product} tracked={product.stock_type === 'in_stock'}
            onChange={(p) => { setSaved(p); setValues((v) => ({ ...v, stock_quantity: String(p.stock_quantity) })) }} />
        </div>
      )}
    </div>
  )
}
