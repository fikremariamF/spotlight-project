import { Eye, ImageOff } from 'lucide-react'
import { lazy, Suspense, useState } from 'react'
import { Link } from 'react-router-dom'
import { CONDITION_LABELS, formatETB } from '../lib/format'
import CompareButton from './CompareButton'
import FavoriteButton from './FavoriteButton'
import { RatingSummary } from './Reviews'

// Loaded on first use, so the catalog does not pay for the dialog up front.
const QuickView = lazy(() => import('./QuickView'))

export function ProductImage({ src, alt, className = '' }) {
  if (!src) {
    return (
      <div className={`flex flex-col items-center justify-center gap-1 bg-sand-100 text-xs text-stone-400 ${className}`} role="img" aria-label="No photo">
        <ImageOff className="size-6" aria-hidden="true" />
        No photo
      </div>
    )
  }
  return <img src={src} alt={alt} loading="lazy" decoding="async" className={`object-cover ${className}`} />
}

function Badge({ tone, children }) {
  const tones = {
    dark: 'bg-stone-900/80 text-white',
    brand: 'bg-white/95 text-brand-800',
    red: 'bg-red-600 text-white',
  }
  return <span className={`rounded-full px-2.5 py-1 text-[11px] font-semibold backdrop-blur ${tones[tone]}`}>{children}</span>
}

export default function ProductCard({ product }) {
  const [quickView, setQuickView] = useState(false)
  const madeToOrder = product.stock_type === 'made_to_order'
  const url = `/products/${product.slug}`

  return (
    <article className="group relative flex flex-col">
      <div className="relative overflow-hidden rounded-2xl bg-sand-100 ring-1 ring-stone-200/70">
        <ProductImage src={product.image} alt="" className="aspect-[4/3] w-full transition duration-500 ease-out group-hover:scale-[1.04]" />
        <div className="pointer-events-none absolute top-2.5 left-2.5 flex flex-wrap gap-1.5">
          {!product.is_available && <Badge tone="red">Out of stock</Badge>}
          {madeToOrder && <Badge tone="brand">Made to order</Badge>}
          {product.condition !== 'new' && <Badge tone="dark">{CONDITION_LABELS[product.condition]}</Badge>}
        </div>
        <div className="absolute top-2.5 right-2.5 z-10 flex flex-col gap-2">
          <FavoriteButton productId={product.id} initial={product.is_favorite} compact />
          <CompareButton slug={product.slug} compact />
        </div>
        <button
          type="button"
          onClick={() => setQuickView(true)}
          className="absolute inset-x-3 bottom-3 z-10 hidden translate-y-2 items-center justify-center gap-2 rounded-xl bg-white/95 py-2 text-sm font-semibold text-stone-900 opacity-0 shadow-soft backdrop-blur transition group-hover:translate-y-0 group-hover:opacity-100 focus-visible:translate-y-0 focus-visible:opacity-100 sm:flex"
          aria-label={`Quick view: ${product.name}`}
        >
          <Eye className="size-4" aria-hidden="true" /> Quick view
        </button>
      </div>
      <div className="flex flex-1 flex-col gap-1 px-1 pt-3">
        {product.seller && (
          <p className="truncate text-xs text-stone-500">{product.seller.store_name}{product.area ? ` · ${product.area}` : ''}</p>
        )}
        <h3 className="line-clamp-2 text-[15px] leading-snug font-semibold text-stone-900">
          <Link to={url} className="after:absolute after:inset-0 after:content-[''] focus-visible:outline-none" aria-label={product.name}>
            {product.name}
          </Link>
        </h3>
        <RatingSummary rating={product.rating} className="text-xs" />
        <div className="mt-auto flex flex-wrap items-baseline gap-x-2 pt-1">
          <span className="text-base font-bold whitespace-nowrap text-stone-900 sm:text-lg">{formatETB(product.price_santim)}</span>
          {product.price_negotiable && <span className="text-xs font-medium text-clay-600">Negotiable</span>}
        </div>
      </div>
      {quickView && (
        <Suspense fallback={null}>
          <QuickView slug={product.slug} onClose={() => setQuickView(false)} />
        </Suspense>
      )}
    </article>
  )
}

export function ProductCardSkeleton() {
  return (
    <div aria-hidden="true">
      <div className="skeleton aspect-[4/3] w-full rounded-2xl" />
      <div className="skeleton mt-3 h-3 w-1/2" />
      <div className="skeleton mt-2 h-4 w-4/5" />
      <div className="skeleton mt-3 h-5 w-1/3" />
    </div>
  )
}

export function ProductGridSkeleton({ count = 8 }) {
  return (
    <div role="status" className="grid grid-cols-2 gap-x-3 gap-y-8 sm:gap-x-5 md:grid-cols-3 xl:grid-cols-4">
      <span className="sr-only">Loading furniture...</span>
      {Array.from({ length: count }, (_, i) => <ProductCardSkeleton key={i} />)}
    </div>
  )
}

export function ProductGrid({ products }) {
  return (
    <div className="grid grid-cols-2 gap-x-3 gap-y-8 sm:gap-x-5 md:grid-cols-3 xl:grid-cols-4">
      {products.map((p) => (
        <ProductCard key={p.id} product={p} />
      ))}
    </div>
  )
}
