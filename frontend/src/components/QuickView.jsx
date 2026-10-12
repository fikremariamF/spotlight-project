import { Dialog, DialogBackdrop, DialogPanel, DialogTitle } from '@headlessui/react'
import { ArrowRight, Ruler, Truck, X } from 'lucide-react'
import { Link } from 'react-router-dom'
import { CONDITION_LABELS, formatDimensions, formatETB } from '../lib/format'
import { useFetch } from '../lib/useFetch'
import AddToCart from './AddToCart'
import CompareButton from './CompareButton'
import FavoriteButton from './FavoriteButton'
import Gallery from './Gallery'
import { RatingSummary } from './Reviews'
import { ErrorState } from './ui'

/** A listing's key facts in a dialog, opened from a product card. */
export default function QuickView({ slug, onClose }) {
  const { data, error, loading } = useFetch(`/products/${encodeURIComponent(slug)}`)
  const p = data?.data

  return (
    <Dialog open onClose={onClose} className="relative z-50">
      <DialogBackdrop transition className="fixed inset-0 bg-stone-900/50 transition-opacity duration-200 data-closed:opacity-0" />
      <div className="fixed inset-0 overflow-y-auto p-4 sm:p-8">
        <div className="flex min-h-full items-center justify-center">
          <DialogPanel transition className="relative w-full max-w-4xl overflow-hidden rounded-3xl bg-white shadow-lift transition duration-200 ease-out data-closed:scale-95 data-closed:opacity-0">
            <button type="button" onClick={onClose} className="icon-btn absolute top-3 right-3 z-10 bg-white/90" aria-label="Close quick view">
              <X className="size-5" aria-hidden="true" />
            </button>
            {loading && (
              <div className="grid gap-6 p-6 md:grid-cols-2" aria-hidden="true">
                <div className="skeleton aspect-[4/3] rounded-2xl" />
                <div className="space-y-3"><div className="skeleton h-7 w-3/4" /><div className="skeleton h-4 w-1/2" /><div className="skeleton h-8 w-1/3" /></div>
              </div>
            )}
            {error && <div className="p-6"><ErrorState error={error} /></div>}
            {p && (
              <div className="grid md:grid-cols-2">
                <div className="bg-sand-100 p-4 md:p-6">
                  <Gallery images={p.images || []} name={p.name} compact />
                </div>
                <div className="flex flex-col gap-4 p-6">
                  <div>
                    <p className="text-xs text-stone-500">{p.seller.store_name}{p.area ? ` · ${p.area}` : ''}</p>
                    <DialogTitle className="mt-1 font-display text-2xl font-semibold tracking-tight text-stone-900">{p.name}</DialogTitle>
                    <RatingSummary rating={p.rating} className="mt-1" />
                  </div>
                  <div className="flex items-baseline gap-2">
                    <span className="text-2xl font-bold text-stone-900">{formatETB(p.price_santim)}</span>
                    {p.price_negotiable && <span className="text-sm font-medium text-clay-600">Negotiable</span>}
                  </div>
                  <ul className="space-y-2 text-sm text-stone-700">
                    {formatDimensions(p.dimensions) && <li className="flex gap-2"><Ruler className="mt-0.5 size-4 shrink-0 text-stone-400" aria-hidden="true" />{formatDimensions(p.dimensions)}</li>}
                    <li className="flex gap-2">
                      <span className="mt-0.5 size-4 shrink-0 text-center text-stone-400" aria-hidden="true">•</span>
                      {[CONDITION_LABELS[p.condition], p.material, p.color].filter(Boolean).join(' · ')}
                    </li>
                    {p.delivery.offers_delivery && (
                      <li className="flex gap-2"><Truck className="mt-0.5 size-4 shrink-0 text-stone-400" aria-hidden="true" />
                        {p.delivery.fee_santim === null ? 'Delivery fee confirmed by the seller' : p.delivery.fee_santim === 0 ? 'Free delivery in Addis Ababa' : `Delivery ${formatETB(p.delivery.fee_santim)}`}
                      </li>
                    )}
                  </ul>
                  <p className={`text-sm font-medium ${p.is_available ? 'text-emerald-700' : 'text-red-700'}`}>
                    {p.stock_type === 'made_to_order' ? `Made to order in about ${p.production_days} days` : p.is_available ? 'In stock' : 'Out of stock'}
                  </p>
                  <AddToCart product={p} />
                  <div className="flex flex-wrap gap-2">
                    <FavoriteButton productId={p.id} initial={p.is_favorite} />
                    <CompareButton slug={p.slug} />
                  </div>
                  <Link to={`/products/${p.slug}`} onClick={onClose} className="mt-auto inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:underline">
                    See full details <ArrowRight className="size-4" aria-hidden="true" />
                  </Link>
                </div>
              </div>
            )}
          </DialogPanel>
        </div>
      </div>
    </Dialog>
  )
}
