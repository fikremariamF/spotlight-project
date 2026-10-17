import { formatETB } from '../lib/format'

/**
 * The options of a listing (size, color, material) as a radio group. Each shows its own price
 * when it differs from the listing's, and whether it can be bought now.
 */
export default function VariantPicker({ product, value, onChange }) {
  const variants = product.variants || []
  if (variants.length === 0) return null
  const madeToOrder = product.stock_type === 'made_to_order'
  return (
    <fieldset id="options">
      <legend className="mb-2 text-sm font-semibold text-stone-900">Choose an option</legend>
      <div className="flex flex-wrap gap-2">
        {variants.map((v) => {
          const selected = value?.id === v.id
          const out = !v.is_available
          return (
            <label key={v.id}
              className={`flex min-w-[7rem] cursor-pointer flex-col rounded-xl border px-3 py-2 text-sm transition has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-500 ${selected ? 'border-brand-600 bg-brand-50' : 'border-stone-300 bg-white hover:border-stone-400'} ${out ? 'opacity-60' : ''}`}>
              <input type="radio" name="variant" className="sr-only" checked={selected} disabled={out} onChange={() => onChange(v)} />
              <span className="font-semibold text-stone-900">{v.label}</span>
              <span className="text-xs text-stone-600">
                {v.price_santim !== product.price_santim ? formatETB(v.price_santim) : null}
                {v.price_santim !== product.price_santim && ' · '}
                {out ? 'Out of stock' : madeToOrder ? 'Made to order' : `${v.stock_quantity} available`}
              </span>
            </label>
          )
        })}
      </div>
    </fieldset>
  )
}
