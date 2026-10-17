import { useState } from 'react'
import { useSearchParams, useParams } from 'react-router-dom'
import AskSupport from '../components/AskSupport'
import { ProductGrid } from '../components/ProductCard'
import { EmptyState, ErrorState, Pagination, Spinner } from '../components/ui'
import { BUSINESS_TYPES, toQuery } from '../lib/format'
import { RatingSummary, ReviewList } from '../components/Reviews'
import { useFetch } from '../lib/useFetch'

export default function Storefront() {
  const { slug } = useParams()
  const [params, setParams] = useSearchParams()
  const seller = useFetch(`/sellers/${encodeURIComponent(slug)}`)
  const products = useFetch(seller.data ? `/products?${toQuery({ seller: slug, page: params.get('page') || '' })}` : null)
  const [contactOpen, setContactOpen] = useState(false)

  if (seller.loading) return <Spinner />
  if (seller.error) return <div className="container-page py-12"><ErrorState error={seller.error} /></div>

  const s = seller.data.data
  return (
    <div className="container-page py-8">
      <header className="card mb-8 p-6">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <h1 className="text-2xl font-bold text-stone-900">{s.store_name}</h1>
            <RatingSummary rating={s.rating} />
            <p className="mt-1 text-sm text-stone-600">
              {BUSINESS_TYPES[s.business_type]}{s.area ? ` in ${s.area}, ${s.city}` : ''}. Selling since {new Date(s.member_since).getFullYear()}.
            </p>
            {s.description && <p className="mt-3 max-w-2xl text-sm text-stone-700">{s.description}</p>}
            <div className="mt-3 flex flex-wrap gap-2 text-xs">
              <span className="rounded-full bg-emerald-50 px-2.5 py-1 font-semibold text-emerald-800">Verified seller</span>
              {s.offers_custom_orders && <span className="rounded-full bg-brand-50 px-2.5 py-1 font-semibold text-brand-800">Custom orders</span>}
              {s.offers_delivery && <span className="rounded-full bg-stone-100 px-2.5 py-1 font-semibold text-stone-700">Delivers in Addis Ababa</span>}
            </div>
          </div>
          <div className="flex shrink-0 flex-wrap gap-2">
            {!contactOpen && <button type="button" className="btn-primary" onClick={() => setContactOpen(true)}>Ask about this store</button>}
          </div>
        </div>
        {contactOpen && <div className="mt-4 max-w-xl"><AskSupport seller={s} title={`Ask Betoch about ${s.store_name}`} onCancel={() => setContactOpen(false)} /></div>}
      </header>

      <h2 className="mb-4 text-lg font-bold text-stone-900">Listings ({s.products_count})</h2>
      {products.loading && <Spinner />}
      {products.error && <ErrorState error={products.error} onRetry={products.reload} />}
      {products.data && (products.data.data.length ? (
        <>
          <ProductGrid products={products.data.data} />
          <Pagination meta={products.data.meta} onPage={(p) => setParams({ page: String(p) })} />
        </>
      ) : (
        <EmptyState title="No listings right now">This seller has no published furniture at the moment.</EmptyState>
      ))}

      <section className="mt-12">
        <h2 className="mb-4 text-lg font-bold text-stone-900">Customer reviews</h2>
        <ReviewList url={`/sellers/${s.slug}/reviews`} showProduct emptyText="No reviews yet." />
      </section>
    </div>
  )
}
