import useEmblaCarousel from 'embla-carousel-react'
import { ArrowRight, ChevronLeft, ChevronRight } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import ProductCard, { ProductCardSkeleton } from './ProductCard'

/** A titled, swipeable row of product cards with arrow buttons on wide screens. */
export default function ProductRail({ title, subtitle, products, loading = false, seeAll }) {
  const [viewportRef, embla] = useEmblaCarousel({ align: 'start', containScroll: 'trimSnaps', dragFree: true })
  const [edges, setEdges] = useState({ prev: false, next: false })

  useEffect(() => {
    if (!embla) return
    const update = () => setEdges({ prev: embla.canScrollPrev(), next: embla.canScrollNext() })
    update()
    embla.on('select', update).on('reInit', update).on('scroll', update)
    return () => { embla.off('select', update).off('reInit', update).off('scroll', update) }
  }, [embla])

  if (!loading && (!products || products.length === 0)) return null
  const titleId = `rail-${title.replace(/\W+/g, '-').toLowerCase()}`

  return (
    <section aria-labelledby={titleId} className="py-8">
      <div className="mb-5 flex items-end justify-between gap-4">
        <div>
          <h2 id={titleId} className="section-title">{title}</h2>
          {subtitle && <p className="mt-1 text-sm text-stone-600">{subtitle}</p>}
        </div>
        <div className="flex shrink-0 items-center gap-1">
          {seeAll && (
            <Link to={seeAll} className="mr-2 inline-flex items-center gap-1 text-sm font-semibold text-brand-700 hover:underline">
              See all <ArrowRight className="size-4" aria-hidden="true" />
            </Link>
          )}
          <button type="button" className="icon-btn hidden border border-stone-200 bg-white sm:inline-flex" onClick={() => embla?.scrollPrev()} disabled={!edges.prev} aria-label={`Scroll ${title} back`}>
            <ChevronLeft className="size-5" aria-hidden="true" />
          </button>
          <button type="button" className="icon-btn hidden border border-stone-200 bg-white sm:inline-flex" onClick={() => embla?.scrollNext()} disabled={!edges.next} aria-label={`Scroll ${title} forward`}>
            <ChevronRight className="size-5" aria-hidden="true" />
          </button>
        </div>
      </div>
      <div ref={viewportRef} data-carousel className="-mx-1 overflow-hidden px-1">
        <div className="flex gap-3 sm:gap-5">
          {loading
            ? Array.from({ length: 4 }, (_, i) => <div key={i} className="min-w-0 flex-[0_0_70%] sm:flex-[0_0_40%] md:flex-[0_0_30%] xl:flex-[0_0_23%]"><ProductCardSkeleton /></div>)
            : products.map((p) => (
              <div key={p.id} className="min-w-0 flex-[0_0_70%] sm:flex-[0_0_40%] md:flex-[0_0_30%] xl:flex-[0_0_23%]">
                <ProductCard product={p} />
              </div>
            ))}
        </div>
      </div>
    </section>
  )
}
