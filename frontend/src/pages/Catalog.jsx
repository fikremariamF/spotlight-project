import { Dialog, DialogBackdrop, DialogPanel, DialogTitle } from '@headlessui/react'
import { SlidersHorizontal, X } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { ProductGrid, ProductGridSkeleton } from '../components/ProductCard'
import { EmptyState, ErrorState, Pagination } from '../components/ui'
import { categoryIcon, useCategories } from '../lib/categories'
import { CONDITION_LABELS, toQuery } from '../lib/format'
import { useFetch } from '../lib/useFetch'

const SORTS = [
  ['recommended', 'Recommended'],
  ['newest', 'Newest'],
  ['price_asc', 'Price: low to high'],
  ['price_desc', 'Price: high to low'],
]

const FILTER_KEYS = ['q', 'min_price', 'max_price', 'condition', 'stock_type', 'area', 'custom', 'delivery', 'in_stock', 'sort', 'page']

const OPTION_LABELS = { in_stock: 'Available now', delivery: 'Delivery offered', custom: 'Custom orders accepted' }
const STOCK_LABELS = { in_stock: 'Ready-made only', made_to_order: 'Made to order only' }

/** One removable chip per active filter, so shoppers see and undo what narrows the list. */
function activeChips(params, areas) {
  const chips = []
  const min = params.get('min_price')
  const max = params.get('max_price')
  if (min || max) chips.push([['min_price', 'max_price'], min && max ? `ETB ${min} to ${max}` : min ? `From ETB ${min}` : `Up to ETB ${max}`])
  if (params.get('condition')) chips.push([['condition'], CONDITION_LABELS[params.get('condition')] || params.get('condition')])
  if (params.get('stock_type')) chips.push([['stock_type'], STOCK_LABELS[params.get('stock_type')] || params.get('stock_type')])
  if (params.get('area')) chips.push([['area'], areas?.find((a) => String(a.id) === params.get('area'))?.name || 'Location'])
  for (const key of ['in_stock', 'delivery', 'custom']) if (params.get(key) === '1') chips.push([[key], OPTION_LABELS[key]])
  return chips
}

function Filters({ params, update, areas, onClose }) {
  const [minPrice, setMinPrice] = useState(params.get('min_price') || '')
  const [maxPrice, setMaxPrice] = useState(params.get('max_price') || '')
  const check = (key) => params.get(key) === '1'

  return (
    <div className="space-y-6 text-sm">
      <form
        onSubmit={(e) => {
          e.preventDefault()
          update({ min_price: minPrice.replace(/[^\d.]/g, ''), max_price: maxPrice.replace(/[^\d.]/g, '') })
          onClose?.()
        }}
      >
        <p className="mb-2 font-semibold text-stone-900">Price (ETB)</p>
        <div className="flex items-center gap-2">
          <input aria-label="Minimum price" inputMode="decimal" className="input" placeholder="Min" value={minPrice} onChange={(e) => setMinPrice(e.target.value)} />
          <span className="text-stone-400">to</span>
          <input aria-label="Maximum price" inputMode="decimal" className="input" placeholder="Max" value={maxPrice} onChange={(e) => setMaxPrice(e.target.value)} />
        </div>
        <button type="submit" className="btn-secondary mt-2 w-full">Apply price</button>
      </form>

      <fieldset>
        <legend className="mb-2 font-semibold text-stone-900">Condition</legend>
        <select className="input" value={params.get('condition') || ''} onChange={(e) => update({ condition: e.target.value })}>
          <option value="">Any condition</option>
          <option value="new">New</option>
          <option value="used">Used</option>
          <option value="refurbished">Refurbished</option>
        </select>
      </fieldset>

      <fieldset>
        <legend className="mb-2 font-semibold text-stone-900">Availability</legend>
        <select className="input" value={params.get('stock_type') || ''} onChange={(e) => update({ stock_type: e.target.value })}>
          <option value="">Any availability</option>
          <option value="in_stock">Ready-made only</option>
          <option value="made_to_order">Made to order only</option>
        </select>
      </fieldset>

      {areas?.length > 0 && (
        <fieldset>
          <legend className="mb-2 font-semibold text-stone-900">Seller location</legend>
          <select className="input" value={params.get('area') || ''} onChange={(e) => update({ area: e.target.value })}>
            <option value="">All of Addis Ababa</option>
            {areas.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
          </select>
        </fieldset>
      )}

      <fieldset className="space-y-2">
        <legend className="mb-2 font-semibold text-stone-900">Options</legend>
        {[
          ['in_stock', 'Available now'],
          ['delivery', 'Delivery offered'],
          ['custom', 'Custom orders accepted'],
        ].map(([key, label]) => (
          <label key={key} className="flex items-center gap-2">
            <input type="checkbox" className="size-4 accent-brand-700" checked={check(key)} onChange={(e) => update({ [key]: e.target.checked ? '1' : '' })} />
            {label}
          </label>
        ))}
      </fieldset>
    </div>
  )
}

export default function Catalog() {
  const { slug } = useParams()
  const [params, setParams] = useSearchParams()
  const [filtersOpen, setFiltersOpen] = useState(false)
  const { data: categoryList } = useCategories()
  const areas = useFetch('/areas')

  const query = useMemo(() => {
    const q = {}
    FILTER_KEYS.forEach((k) => { q[k] = params.get(k) || '' })
    return toQuery({ ...q, category: slug || '' })
  }, [params, slug])

  const products = useFetch(`/products?${query}`)

  const category = useMemo(() => {
    const all = (categoryList || []).flatMap((c) => [c, ...(c.children || [])])
    return all.find((c) => c.slug === slug)
  }, [categoryList, slug])

  function update(changes) {
    const next = new URLSearchParams(params)
    Object.entries(changes).forEach(([k, v]) => (v ? next.set(k, v) : next.delete(k)))
    if (!('page' in changes)) next.delete('page')
    setParams(next)
  }

  const term = params.get('q')
  const title = category ? category.name : term ? `Results for "${term}"` : 'All furniture'
  const activeFilters = FILTER_KEYS.filter((k) => !['q', 'sort', 'page'].includes(k) && params.get(k)).length
  const meta = products.data?.meta

  const chips = activeChips(params, areas.data?.data)
  const clearAll = () => setParams(term ? { q: term } : {})
  const filterPanel = (onClose) => (
    <>
      <Filters key={query} params={params} update={update} areas={areas.data?.data} onClose={onClose} />
      {activeFilters > 0 && (
        <button type="button" className="btn-ghost mt-4 w-full" onClick={() => { clearAll(); onClose?.() }}>
          Clear filters
        </button>
      )}
    </>
  )

  return (
    <div className="container-page py-8">
      <nav aria-label="Breadcrumb" className="mb-3 text-sm text-stone-500">
        <Link to="/" className="hover:underline">Home</Link> <span aria-hidden="true">/</span>{' '}
        <span className="text-stone-700">{title}</span>
      </nav>
      <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="font-display text-3xl font-semibold tracking-tight text-stone-900 sm:text-4xl">{title}</h1>
          {category?.description && <p className="mt-2 max-w-2xl text-sm text-stone-600">{category.description}</p>}
          {meta && <p className="mt-1 text-sm text-stone-500">{meta.total} {meta.total === 1 ? 'listing' : 'listings'}</p>}
        </div>
        <div className="flex gap-2">
          <button type="button" className="btn-secondary lg:hidden" onClick={() => setFiltersOpen(true)} aria-haspopup="dialog">
            <SlidersHorizontal className="size-4" aria-hidden="true" />
            Filters{activeFilters ? ` (${activeFilters})` : ''}
          </button>
          <label htmlFor="sort" className="sr-only">Sort by</label>
          <select id="sort" className="input w-auto rounded-full" value={params.get('sort') || 'recommended'} onChange={(e) => update({ sort: e.target.value === 'recommended' ? '' : e.target.value })}>
            {SORTS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </div>
      </div>

      {categoryList && !slug && (
        <div className="no-scrollbar -mx-4 mb-6 flex gap-2 relative overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:px-0">
          {categoryList.map((c) => {
            const Icon = categoryIcon(c.slug)
            return (
              <Link key={c.id} to={`/category/${c.slug}${params.get('q') ? `?q=${encodeURIComponent(params.get('q'))}` : ''}`} className="chip">
                <Icon className="size-4 text-stone-500" aria-hidden="true" />{c.name}
              </Link>
            )
          })}
        </div>
      )}

      {chips.length > 0 && (
        <div className="mb-6 flex flex-wrap items-center gap-2" aria-label="Active filters">
          {chips.map(([keys, label]) => (
            <button key={keys.join()} type="button" className="chip border-brand-200 bg-brand-50 text-brand-800"
              onClick={() => update(Object.fromEntries(keys.map((k) => [k, ''])))} aria-label={`Remove filter: ${label}`}>
              {label} <X className="size-3.5" aria-hidden="true" />
            </button>
          ))}
          <button type="button" className="text-sm font-semibold text-stone-600 underline-offset-2 hover:underline" onClick={clearAll}>Clear all</button>
        </div>
      )}

      <div className="grid gap-8 lg:grid-cols-[250px_1fr]">
        <aside className="card hidden p-5 lg:block lg:self-start" aria-label="Filters">
          {filterPanel()}
        </aside>

        <Dialog open={filtersOpen} onClose={setFiltersOpen} className="relative z-50 lg:hidden">
          <DialogBackdrop transition className="fixed inset-0 bg-stone-900/40 transition-opacity duration-200 data-closed:opacity-0" />
          <DialogPanel transition className="fixed inset-x-0 bottom-0 max-h-[85vh] overflow-y-auto rounded-t-3xl bg-white p-5 shadow-lift transition duration-200 ease-out data-closed:translate-y-full">
            <div className="mb-4 flex items-center justify-between">
              <DialogTitle className="text-lg font-semibold text-stone-900">Filters</DialogTitle>
              <button type="button" className="icon-btn" onClick={() => setFiltersOpen(false)} aria-label="Close filters"><X className="size-5" aria-hidden="true" /></button>
            </div>
            {filterPanel(() => setFiltersOpen(false))}
            <button type="button" className="btn-primary mt-4 w-full" onClick={() => setFiltersOpen(false)}>
              Show {meta ? `${meta.total} ${meta.total === 1 ? 'result' : 'results'}` : 'results'}
            </button>
          </DialogPanel>
        </Dialog>

        <section aria-live="polite" aria-busy={products.loading}>
          {products.loading && <ProductGridSkeleton count={8} />}
          {products.error && <ErrorState error={products.error} onRetry={products.reload} />}
          {products.data && !products.loading && (products.data.data.length ? (
            <>
              <ProductGrid products={products.data.data} />
              <Pagination meta={meta} onPage={(p) => { update({ page: String(p) }); window.scrollTo({ top: 0 }) }} />
            </>
          ) : (
            <EmptyState
              title={slug && categoryList && !category ? 'Category not found' : 'No furniture matches your search'}
              action={<Link to="/search" className="btn-secondary">Browse all furniture</Link>}
            >
              Try fewer filters or a different keyword. Looking for something specific? Many sellers build custom furniture to order.
            </EmptyState>
          ))}
        </section>
      </div>
    </div>
  )
}
