import { GitCompareArrows, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import AddToCart from '../components/AddToCart'
import { ProductImage } from '../components/ProductCard'
import { RatingSummary } from '../components/Reviews'
import { EmptyState, ErrorState } from '../components/ui'
import { COMPARE_MAX, useCompare } from '../context/CompareContext'
import { api, parseError } from '../lib/api'
import { CONDITION_LABELS, formatDimensions, formatETB } from '../lib/format'

function deliveryText(p) {
  if (!p.delivery.offers_delivery) return p.delivery.pickup_available ? 'Pickup only' : 'Ask the seller'
  const fee = p.delivery.fee_santim === null ? 'fee set by seller' : p.delivery.fee_santim === 0 ? 'free' : formatETB(p.delivery.fee_santim)
  return `Delivery, ${fee}${p.delivery.pickup_available ? '; pickup too' : ''}`
}

function availability(p) {
  if (p.stock_type === 'made_to_order') return `Made to order, about ${p.production_days} days`
  return p.is_available ? `In stock (${p.stock_quantity})` : 'Out of stock'
}

const ROWS = [
  ['Price', (p) => <span className="font-bold text-stone-900">{formatETB(p.price_santim)}{p.price_negotiable ? <span className="block text-xs font-medium text-clay-600">Negotiable</span> : null}</span>, (p) => p.price_santim],
  ['Rating', (p) => (p.rating?.count ? <RatingSummary rating={p.rating} /> : <span className="text-stone-400">No reviews yet</span>)],
  ['Availability', availability],
  ['Dimensions', (p) => formatDimensions(p.dimensions) || '-'],
  ['Material', (p) => p.material || '-'],
  ['Color', (p) => p.color || '-'],
  ['Condition', (p) => CONDITION_LABELS[p.condition]],
  ['Delivery', deliveryText],
  ['Custom orders', (p) => (p.custom_order_available ? 'Accepted' : 'No')],
  ['Seller', (p) => <Link to={`/sellers/${p.seller.slug}`} className="font-medium text-brand-700 hover:underline">{p.seller.store_name}</Link>],
  ['Location', (p) => p.area || p.seller.area || '-'],
]

export default function Compare() {
  const { slugs, remove, clear } = useCompare()
  const [state, setState] = useState({ products: null, error: null })
  const [dropped, setDropped] = useState(0)
  const key = slugs.join(',')

  useEffect(() => {
    if (!key) return
    let live = true
    api.get('/products', { params: { slugs: key, per_page: COMPARE_MAX } })
      .then((res) => {
        if (!live) return
        const bySlug = new Map(res.data.data.map((p) => [p.slug, p]))
        const gone = key.split(',').filter((s) => !bySlug.has(s))
        setState({ products: key.split(',').map((s) => bySlug.get(s)).filter(Boolean), error: null })
        // Listings removed or hidden since they were picked leave the list, so they stop taking up a slot.
        if (gone.length > 0) {
          setDropped((n) => n + gone.length)
          gone.forEach(remove)
        }
      })
      .catch((err) => live && setState({ products: null, error: parseError(err) }))
    return () => { live = false }
  }, [key, remove])

  const products = key ? state.products?.filter((p) => slugs.includes(p.slug)) : []
  const cheapest = products?.length > 1 ? Math.min(...products.map((p) => p.price_santim)) : null

  return (
    <div className="container-page py-8">
      <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="font-display text-3xl font-semibold tracking-tight text-stone-900 sm:text-4xl">Compare furniture</h1>
          <p className="mt-1 text-sm text-stone-600">Side by side, up to {COMPARE_MAX} items. Your list stays in this browser.</p>
        </div>
        {slugs.length > 0 && <button type="button" className="btn-ghost self-start sm:self-auto" onClick={clear}>Clear all</button>}
      </div>

      {state.error && key && <ErrorState error={state.error} />}
      {dropped > 0 && <p role="status" className="mb-4 text-sm text-stone-600">{dropped === 1 ? 'One item is' : `${dropped} items are`} no longer for sale and {dropped === 1 ? 'was' : 'were'} removed from your list.</p>}

      {slugs.length === 0 && (
        <EmptyState title="Nothing to compare yet" action={<Link to="/search" className="btn-primary">Browse furniture</Link>}>
          Tap the <GitCompareArrows className="inline size-4 align-text-bottom" aria-label="compare" /> button on any listing to add it here.
        </EmptyState>
      )}

      {key && !products && !state.error && (
        <div className="grid grid-cols-2 gap-4 md:grid-cols-4" aria-busy="true">
          {slugs.map((s) => <div key={s} className="skeleton h-80 rounded-2xl" />)}
        </div>
      )}

      {products?.length > 0 && (
        <div className="card relative overflow-x-auto">
          <table className="w-full min-w-[600px] table-fixed border-collapse text-sm">
            <caption className="sr-only">Comparison of {products.length} listings</caption>
            <colgroup><col className="w-28 sm:w-36" />{products.map((p) => <col key={p.id} />)}</colgroup>
            <thead>
              <tr>
                <td />
                {products.map((p) => (
                  <th key={p.id} scope="col" className="p-4 text-left align-top font-normal">
                    <div className="relative">
                      <button type="button" onClick={() => remove(p.slug)} className="icon-btn absolute top-2 right-2 z-10 size-8 bg-white/90 shadow-soft" aria-label={`Remove ${p.name} from compare`}>
                        <X className="size-4" aria-hidden="true" />
                      </button>
                      <Link to={`/products/${p.slug}`}>
                        <ProductImage src={p.image} alt="" className="aspect-[4/3] w-full rounded-xl" />
                        <span className="mt-3 line-clamp-2 block font-semibold text-stone-900 hover:underline">{p.name}</span>
                      </Link>
                    </div>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {ROWS.map(([label, render, value]) => (
                <tr key={label} className="border-t border-stone-100">
                  <th scope="row" className="sticky left-0 z-20 bg-white p-4 text-left align-top font-medium text-stone-500">{label}</th>
                  {products.map((p) => (
                    <td key={p.id} className={`p-4 align-top text-stone-800 ${value && cheapest !== null && value(p) === cheapest ? 'bg-emerald-50/70' : ''}`}>
                      {render(p)}
                      {value && cheapest !== null && value(p) === cheapest && <span className="mt-1 block text-xs font-semibold text-emerald-700">Lowest price</span>}
                    </td>
                  ))}
                </tr>
              ))}
              <tr className="border-t border-stone-100">
                <td />
                {products.map((p) => <td key={p.id} className="p-4">{p.is_available && <AddToCart product={p} compact />}</td>)}
              </tr>
            </tbody>
          </table>
        </div>
      )}
      {products?.length === 1 && <p className="mt-4 text-sm text-stone-600">Add one more item to see them side by side.</p>}
    </div>
  )
}
