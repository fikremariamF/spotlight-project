import { ArrowRight, BadgeCheck, Banknote, Hammer, MessageCircle, Ruler, Search, Store } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { ProductImage } from '../components/ProductCard'
import ProductRail from '../components/ProductRail'
import { EmptyState, ErrorState } from '../components/ui'
import { useConfig, usePaymentWording } from '../context/ConfigContext'
import { categoryIcon, useCategories } from '../lib/categories'
import { useFetch } from '../lib/useFetch'
import { useRecentlyViewed } from '../lib/useRecent'

const PROMISES = [
  [BadgeCheck, 'Verified sellers', 'Every store is checked by our team before it can list.'],
  [Ruler, 'Clear specifications', 'Dimensions, materials and delivery terms on every listing.'],
  [MessageCircle, 'Talk to the maker', 'Ask about finishes, custom sizes or a better price.'],
]

function HeroSearch() {
  const navigate = useNavigate()
  const [q, setQ] = useState('')
  return (
    <form
      role="search"
      className="mt-8 max-w-xl"
      onSubmit={(e) => {
        e.preventDefault()
        navigate(q.trim() ? `/search?q=${encodeURIComponent(q.trim())}` : '/search')
      }}
    >
      <label htmlFor="hero-search" className="sr-only">What are you looking for?</label>
      <div className="flex items-center gap-2 rounded-2xl bg-white p-2 shadow-lift ring-1 ring-stone-200">
        <Search className="ml-2 size-5 shrink-0 text-stone-400" aria-hidden="true" />
        <input id="hero-search" type="search" value={q} onChange={(e) => setQ(e.target.value)} maxLength={100}
          placeholder="Try &quot;wardrobe&quot;" className="min-w-0 flex-1 bg-transparent py-2 text-base text-stone-900 placeholder:text-stone-400 focus:outline-none" />
        <button type="submit" className="btn-primary px-5">Find furniture</button>
      </div>
    </form>
  )
}

function HeroCollage({ products }) {
  const [a, b, c] = products
  if (!a) return <div className="hidden lg:block" />
  return (
    <div className="relative hidden h-[420px] lg:block" aria-hidden="true">
      <div className="absolute top-0 right-0 w-[72%] overflow-hidden rounded-3xl shadow-lift ring-1 ring-black/5">
        <ProductImage src={a.image} alt="" className="aspect-[4/3] w-full" />
      </div>
      {b && (
        <div className="absolute bottom-0 left-0 w-[52%] overflow-hidden rounded-3xl border-4 border-sand-50 shadow-lift">
          <ProductImage src={b.image} alt="" className="aspect-[4/3] w-full" />
        </div>
      )}
      {c && (
        <div className="absolute top-10 left-0 flex items-center gap-3 rounded-2xl bg-white/95 p-3 pr-5 shadow-lift backdrop-blur">
          <ProductImage src={c.image} alt="" className="size-14 rounded-xl" />
          <div>
            <p className="max-w-[12rem] truncate text-sm font-semibold text-stone-900">{c.name}</p>
            <p className="text-xs text-stone-500">{c.seller?.store_name}</p>
          </div>
        </div>
      )}
    </div>
  )
}

export default function Home() {
  const { name } = useConfig()
  const pay = usePaymentWording()
  const promises = [...PROMISES, [Banknote, pay.title, pay.body]]
  const { data: categories, error: categoriesError } = useCategories()
  const featured = useFetch('/products?per_page=8&sort=recommended')
  const newest = useFetch('/products?per_page=8&sort=newest')
  const recent = useRecentlyViewed()
  const total = (categories || []).reduce((sum, c) => sum + (c.products_count || 0), 0)
  const featuredList = featured.data?.data || []

  return (
    <>
      <section className="relative overflow-hidden bg-gradient-to-b from-sand-100 to-sand-50">
        <div className="pointer-events-none absolute -top-32 -right-24 size-[28rem] rounded-full bg-brand-100/60 blur-3xl" aria-hidden="true" />
        <div className="container-page relative grid gap-10 py-12 sm:py-16 lg:grid-cols-[1.1fr_1fr] lg:items-center lg:py-20">
          <div className="min-w-0">
            <p className="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 text-xs font-semibold text-brand-800 shadow-soft ring-1 ring-brand-100">
              <Store className="size-3.5" aria-hidden="true" /> Local shops and workshops in Addis Ababa
            </p>
            <h1 className="mt-5 font-display text-4xl leading-[1.05] font-semibold tracking-tight text-stone-900 sm:text-5xl lg:text-6xl">
              Furniture for every home in Addis Ababa
            </h1>
            <p className="mt-5 max-w-lg text-base text-stone-600 sm:text-lg">
              Compare sofas, beds, wardrobes and office furniture from verified local sellers. Buy ready-made, or ask a maker to build it for you.
            </p>
            <HeroSearch />
            <div className="no-scrollbar -mx-4 mt-4 flex gap-2 relative overflow-x-auto px-4 sm:mx-0 sm:flex-wrap sm:px-0">
              {(categories || []).slice(0, 5).map((c) => (
                <Link key={c.id} to={`/category/${c.slug}`} className="chip bg-white/70">{c.name}</Link>
              ))}
            </div>
            <dl className="mt-8 flex flex-wrap gap-x-8 gap-y-3 text-sm">
              {total > 0 && <div><dt className="text-stone-500">Listings</dt><dd className="text-xl font-bold text-stone-900">{total}</dd></div>}
              <div><dt className="text-stone-500">Payment</dt><dd className="text-xl font-bold text-stone-900">{pay.stat}</dd></div>
              <div><dt className="text-stone-500">Custom orders</dt><dd className="text-xl font-bold text-stone-900">Quotes from makers</dd></div>
            </dl>
          </div>
          <HeroCollage products={featuredList} />
        </div>
      </section>

      <div className="container-page">
        <section aria-labelledby="categories-title" className="py-10">
          <h2 id="categories-title" className="section-title mb-5">Shop by category</h2>
          {categoriesError && <ErrorState error={{ message: 'Could not load categories.' }} />}
          {!categories && !categoriesError && (
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4" aria-hidden="true">
              {Array.from({ length: 8 }, (_, i) => <div key={i} className="skeleton h-24 rounded-2xl" />)}
            </div>
          )}
          {categories && (
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              {categories.map((c) => {
                const Icon = categoryIcon(c.slug)
                return (
                  <Link key={c.id} to={`/category/${c.slug}`} className="card group flex items-center gap-3 p-4 transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-lift">
                    <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700 transition group-hover:bg-brand-700 group-hover:text-white">
                      <Icon className="size-5" aria-hidden="true" />
                    </span>
                    <span className="min-w-0">
                      <span className="block truncate text-sm font-semibold text-stone-900">{c.name}</span>
                      <span className="block text-xs text-stone-500">{c.products_count} {c.products_count === 1 ? 'listing' : 'listings'}</span>
                    </span>
                  </Link>
                )
              })}
            </div>
          )}
        </section>

        {featured.error && <ErrorState error={featured.error} onRetry={featured.reload} />}
        {featured.data && featuredList.length === 0 ? (
          <EmptyState title="No listings yet" action={<Link to="/sell" className="btn-primary">Become a seller</Link>}>
            {name} is just getting started. Sellers are adding their furniture now.
          </EmptyState>
        ) : (
          <ProductRail title="Featured furniture" subtitle="Picked from verified stores" products={featuredList} loading={featured.loading} seeAll="/search" />
        )}

        <section className="grid gap-4 py-8 md:grid-cols-2" aria-label="Custom furniture and selling">
          <div className="relative overflow-hidden rounded-3xl bg-brand-800 p-8 text-white">
            <Hammer className="absolute -right-4 -bottom-6 size-40 text-white/10" aria-hidden="true" />
            <p className="text-sm font-semibold text-brand-200">Made to measure</p>
            <h2 className="mt-2 max-w-sm font-display text-3xl font-semibold">Need a piece that fits your space?</h2>
            <p className="mt-3 max-w-sm text-brand-100">Send your measurements to a workshop and get a quote with price and delivery date.</p>
            <Link to="/category/custom-made" className="btn mt-6 bg-white text-brand-900 hover:bg-sand-100">Request custom furniture <ArrowRight className="size-4" aria-hidden="true" /></Link>
          </div>
          <div className="relative overflow-hidden rounded-3xl bg-clay-500/10 p-8 ring-1 ring-clay-500/20">
            <Store className="absolute -right-4 -bottom-6 size-40 text-clay-500/15" aria-hidden="true" />
            <p className="text-sm font-semibold text-clay-600">For shops and makers</p>
            <h2 className="mt-2 max-w-sm font-display text-3xl font-semibold text-stone-900">Sell your furniture on {name}</h2>
            <p className="mt-3 max-w-sm text-stone-700">Reach buyers across Addis Ababa. Listing is free; a small commission applies to completed sales.</p>
            <Link to="/sell" className="btn-primary mt-6">Apply to sell <ArrowRight className="size-4" aria-hidden="true" /></Link>
          </div>
        </section>

        <ProductRail title="Just listed" products={newest.data?.data} loading={newest.loading} seeAll="/search?sort=newest" />
        <ProductRail title="Recently viewed" subtitle="Pick up where you left off" products={recent} />

        <section aria-label="Why buy here" className="mt-6 grid gap-4 rounded-3xl bg-white p-6 shadow-soft ring-1 ring-stone-200/80 sm:grid-cols-2 lg:grid-cols-4 lg:p-8">
          {promises.map(([Icon, title, text]) => (
            <div key={title} className="flex gap-3">
              <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-sand-100 text-brand-700"><Icon className="size-5" aria-hidden="true" /></span>
              <div>
                <p className="font-semibold text-stone-900">{title}</p>
                <p className="text-sm text-stone-600">{text}</p>
              </div>
            </div>
          ))}
        </section>
      </div>
    </>
  )
}
