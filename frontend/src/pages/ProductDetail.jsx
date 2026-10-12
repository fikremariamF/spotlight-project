import { BadgeCheck, Banknote, Clock, MapPin, MessageCircle, Phone, Ruler, Store, Truck } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import AddToCart from '../components/AddToCart'
import CompareButton from '../components/CompareButton'
import ContactSeller from '../components/ContactSeller'
import DeliveryEstimate from '../components/DeliveryEstimate'
import FavoriteButton from '../components/FavoriteButton'
import Gallery from '../components/Gallery'
import ProductRail from '../components/ProductRail'
import { RatingSummary, ReviewList } from '../components/Reviews'
import ShareMenu from '../components/ShareMenu'
import { ErrorState } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { usePaymentWording } from '../context/ConfigContext'
import { BUSINESS_TYPES, CONDITION_LABELS, formatDimensions, formatETB } from '../lib/format'
import { rememberViewed } from '../lib/localList'
import { useFetch } from '../lib/useFetch'
import { useRecentlyViewed } from '../lib/useRecent'

function Spec({ label, value }) {
  if (value === null || value === undefined || value === '') return null
  return (
    <div className="grid grid-cols-[140px_1fr] gap-3 border-b border-stone-100 py-3 text-sm last:border-0">
      <dt className="text-stone-500">{label}</dt>
      <dd className="font-medium text-stone-900">{value}</dd>
    </div>
  )
}

function DetailSkeleton() {
  return (
    <div className="container-page py-8" aria-busy="true">
      <span className="sr-only" role="status">Loading...</span>
      <div className="skeleton mb-6 h-4 w-60" />
      <div className="grid gap-10 lg:grid-cols-[1.25fr_1fr]">
        <div className="skeleton aspect-[4/3] rounded-2xl" />
        <div className="space-y-4"><div className="skeleton h-9 w-3/4" /><div className="skeleton h-4 w-1/3" /><div className="skeleton h-10 w-1/2" /><div className="skeleton h-40 rounded-2xl" /></div>
      </div>
    </div>
  )
}

export default function ProductDetail() {
  const { slug } = useParams()
  const { user } = useAuth()
  const { data, error, loading, reload } = useFetch(`/products/${encodeURIComponent(slug)}`)
  const p = data?.data
  const seller = useFetch(p ? `/sellers/${p.seller.slug}` : null)
  const similar = useFetch(p ? `/products?category=${encodeURIComponent(p.category.slug)}&per_page=9` : null)
  const recent = useRecentlyViewed(slug)
  const pay = usePaymentWording()
  const [contactOpen, setContactOpen] = useState(false)

  useEffect(() => {
    if (p?.slug) rememberViewed(p.slug)
  }, [p?.slug])

  const similarList = useMemo(() => (similar.data?.data || []).filter((x) => x.slug !== slug).slice(0, 8), [similar.data, slug])

  if (loading && !p) return <DetailSkeleton />
  if (error) {
    return (
      <div className="container-page py-12">
        <ErrorState error={error} onRetry={error.message?.includes('connection') ? reload : undefined} />
      </div>
    )
  }

  const images = p.images || []
  const madeToOrder = p.stock_type === 'made_to_order'
  const store = seller.data?.data
  const phone = store?.phone?.replace(/\s/g, '')
  const canBuy = (!user || user.role === 'customer') && p.is_available
  const dims = formatDimensions(p.dimensions)

  return (
    <div className="container-page py-6 sm:py-8">
      <nav aria-label="Breadcrumb" className="mb-5 text-sm text-stone-500">
        <Link to="/" className="hover:underline">Home</Link> /{' '}
        <Link to={`/category/${p.category.slug}`} className="hover:underline">{p.category.name}</Link> /{' '}
        <span className="text-stone-700">{p.name}</span>
      </nav>

      <div className="grid gap-8 lg:grid-cols-[1.25fr_1fr] lg:gap-12">
        <div className="lg:sticky lg:top-24 lg:self-start">
          <Gallery images={images} name={p.name} />
        </div>

        <div>
          <div className="flex flex-wrap gap-2">
            {madeToOrder && <span className="rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-800">Made to order</span>}
            {p.condition !== 'new' && <span className="rounded-full bg-stone-100 px-3 py-1 text-xs font-semibold text-stone-700">{CONDITION_LABELS[p.condition]}</span>}
          </div>
          <h1 className="mt-3 font-display text-3xl leading-tight font-semibold tracking-tight text-stone-900 sm:text-4xl">{p.name}</h1>
          <p className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-stone-600">
            <span>Sold by <Link to={`/sellers/${p.seller.slug}`} className="font-semibold text-brand-700 hover:underline">{p.seller.store_name}</Link></span>
            {p.area && <span className="inline-flex items-center gap-1"><MapPin className="size-3.5" aria-hidden="true" />{p.area}</span>}
            {p.rating?.count > 0 && <a href="#reviews"><RatingSummary rating={p.rating} /></a>}
          </p>

          <div className="mt-5 flex items-baseline gap-3">
            <span className="text-3xl font-bold tracking-tight text-stone-900 sm:text-4xl">{formatETB(p.price_santim)}</span>
            {p.price_negotiable && <span className="rounded-full bg-clay-500/10 px-2.5 py-1 text-xs font-semibold text-clay-600">Price negotiable</span>}
          </div>
          <p className={`mt-2 inline-flex items-center gap-1.5 text-sm font-medium ${p.is_available ? 'text-emerald-700' : 'text-red-700'}`}>
            {madeToOrder && <Clock className="size-4" aria-hidden="true" />}
            {madeToOrder
              ? `Made to order. Estimated production time ${p.production_days} days.`
              : p.is_available ? `In stock (${p.stock_quantity} available)` : 'Out of stock. Ask the seller about restocking.'}
          </p>

          <div id="buy" className="mt-6 space-y-3 scroll-mt-24">
            <AddToCart product={p} full />
            <div className="flex flex-wrap gap-2">
              {!contactOpen && (
                <button type="button" className="btn-secondary" onClick={() => setContactOpen(true)}>
                  <MessageCircle className="size-4" aria-hidden="true" />Message seller
                </button>
              )}
              {phone && <a href={`tel:${phone}`} className="btn-secondary"><Phone className="size-4" aria-hidden="true" />Call {store.phone}</a>}
              <FavoriteButton productId={p.id} initial={p.is_favorite} />
              <CompareButton slug={p.slug} />
              <ShareMenu title={p.name} text={formatETB(p.price_santim)} />
            </div>
            {contactOpen && <ContactSeller product={p} onCancel={() => setContactOpen(false)} />}
          </div>

          <div className="card mt-6 divide-y divide-stone-100">
            <div className="flex gap-3 p-4 text-sm">
              <Truck className="mt-0.5 size-5 shrink-0 text-brand-700" aria-hidden="true" />
              <div className="flex-1 space-y-1">
                <p className="font-semibold text-stone-900">Delivery and pickup</p>
                <ul className="space-y-1 text-stone-600">
                  {p.delivery.offers_delivery && (
                    <li>Delivery in Addis Ababa: {p.delivery.fee_santim === null ? 'fee confirmed by the seller before you pay' : p.delivery.fee_santim === 0 ? 'free' : formatETB(p.delivery.fee_santim)}</li>
                  )}
                  {p.delivery.pickup_available && <li>Pickup available{p.area ? ` in ${p.area}` : ''}</li>}
                  {p.delivery.notes && <li>{p.delivery.notes}</li>}
                </ul>
                {p.delivery.offers_delivery && <div className="pt-2"><DeliveryEstimate slug={p.slug} /></div>}
              </div>
            </div>
            <div className="flex gap-3 p-4 text-sm">
              <Banknote className="mt-0.5 size-5 shrink-0 text-brand-700" aria-hidden="true" />
              <p className="text-stone-600"><span className="font-semibold text-stone-900">{pay.short}</span> The seller confirms your order and delivery details before preparing it.</p>
            </div>
            {p.custom_order_available && (
              <div className="flex gap-3 p-4 text-sm">
                <Ruler className="mt-0.5 size-5 shrink-0 text-brand-700" aria-hidden="true" />
                <p className="text-stone-600"><span className="font-semibold text-stone-900">Custom orders accepted.</span> Send a custom request to ask about other sizes, materials or colors and get a quote.</p>
              </div>
            )}
          </div>

          {store && (
            <Link to={`/sellers/${store.slug}`} className="card mt-4 flex items-center gap-3 p-4 transition hover:shadow-lift">
              <span className="flex size-11 items-center justify-center rounded-xl bg-brand-50 text-brand-700"><Store className="size-5" aria-hidden="true" /></span>
              <span className="min-w-0 flex-1">
                <span className="flex items-center gap-1.5 font-semibold text-stone-900">{store.store_name}<BadgeCheck className="size-4 text-brand-600" aria-label="Verified store" /></span>
                <span className="block text-xs text-stone-500">{[BUSINESS_TYPES[store.business_type], store.area].filter(Boolean).join(' · ')}</span>
              </span>
              <span className="text-sm font-semibold text-brand-700">Visit store</span>
            </Link>
          )}
        </div>
      </div>

      <div className="mt-14 grid gap-10 lg:grid-cols-[1.25fr_1fr] lg:gap-12">
        <section>
          <h2 className="section-title mb-4">Description</h2>
          <p className="leading-relaxed whitespace-pre-line text-stone-700">{p.description}</p>
        </section>
        <section>
          <h2 className="section-title mb-4">Specifications</h2>
          <dl className="card px-5">
            <Spec label="Category" value={p.category.name} />
            <Spec label="Condition" value={CONDITION_LABELS[p.condition]} />
            <Spec label="Dimensions" value={dims} />
            <Spec label="Material" value={p.material} />
            <Spec label="Color" value={p.color} />
            <Spec label="Location" value={p.area} />
            <Spec label="Seller type" value={store ? BUSINESS_TYPES[store.business_type] : null} />
          </dl>
        </section>
      </div>

      <section id="reviews" className="mt-14 scroll-mt-20">
        <h2 className="section-title mb-4">Reviews</h2>
        <ReviewList url={`/products/${p.slug}/reviews`} emptyText="No reviews yet. Only customers who received this item can review it." />
      </section>

      <ProductRail title="Similar furniture" subtitle={`More from ${p.category.name}`} products={similarList} seeAll={`/category/${p.category.slug}`} />
      <ProductRail title="Recently viewed" products={recent} />

      {canBuy && (
        <div className="fixed inset-x-0 bottom-0 z-20 border-t border-stone-200 bg-white/95 px-4 py-3 backdrop-blur lg:hidden" aria-label="Buy bar">
          <div className="flex items-center gap-3">
            <div className="min-w-0 flex-1">
              <p className="truncate text-xs text-stone-500">{p.name}</p>
              <p className="text-lg font-bold text-stone-900">{formatETB(p.price_santim)}</p>
            </div>
            <AddToCart product={p} compact />
          </div>
        </div>
      )}
      {canBuy && <div className="h-20 lg:hidden" aria-hidden="true" />}
    </div>
  )
}
