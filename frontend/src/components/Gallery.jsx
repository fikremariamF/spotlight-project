import useEmblaCarousel from 'embla-carousel-react'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { ProductImage } from './ProductCard'

/** Swipeable product photos with thumbnails; a single photo renders without controls. */
export default function Gallery({ images, name, compact = false }) {
  const [viewportRef, embla] = useEmblaCarousel({ loop: false })
  const [index, setIndex] = useState(0)

  useEffect(() => {
    if (!embla) return
    const onSelect = () => setIndex(embla.selectedScrollSnap())
    embla.on('select', onSelect).on('reInit', onSelect)
    return () => { embla.off('select', onSelect).off('reInit', onSelect) }
  }, [embla])

  const go = useCallback((i) => embla?.scrollTo(i), [embla])

  if (images.length === 0) {
    return <ProductImage src={null} alt={name} className="aspect-[4/3] w-full rounded-2xl" />
  }

  const many = images.length > 1
  return (
    <div>
      <div
        className="group relative overflow-hidden rounded-2xl bg-sand-100"
        role="region"
        aria-roledescription="carousel"
        aria-label={`Photos of ${name}`}
        onKeyDown={(e) => {
          if (e.key === 'ArrowLeft') embla?.scrollPrev()
          if (e.key === 'ArrowRight') embla?.scrollNext()
        }}
      >
        <div ref={viewportRef} data-carousel className="overflow-hidden">
          <div className="flex touch-pan-y">
            {images.map((img, i) => (
              <div key={img.id} className="min-w-0 flex-[0_0_100%]" role="group" aria-roledescription="slide" aria-label={`${i + 1} of ${images.length}`}>
                <ProductImage src={img.url} alt={img.alt || name} className="aspect-[4/3] w-full" />
              </div>
            ))}
          </div>
        </div>
        {many && (
          <>
            <button type="button" onClick={() => embla?.scrollPrev()} disabled={index === 0} aria-label="Previous photo"
              className="icon-btn absolute top-1/2 left-3 -translate-y-1/2 bg-white/90 shadow-soft disabled:opacity-0">
              <ChevronLeft className="size-5" aria-hidden="true" />
            </button>
            <button type="button" onClick={() => embla?.scrollNext()} disabled={index === images.length - 1} aria-label="Next photo"
              className="icon-btn absolute top-1/2 right-3 -translate-y-1/2 bg-white/90 shadow-soft disabled:opacity-0">
              <ChevronRight className="size-5" aria-hidden="true" />
            </button>
            <p className="absolute right-3 bottom-3 rounded-full bg-stone-900/70 px-2.5 py-1 text-xs font-medium text-white" aria-live="polite">
              {index + 1} / {images.length}
            </p>
          </>
        )}
      </div>
      {many && !compact && (
        <div className="no-scrollbar mt-3 flex gap-2 relative overflow-x-auto">
          {images.map((img, i) => (
            <button key={img.id} type="button" onClick={() => go(i)} aria-label={`Show photo ${i + 1}`} aria-current={i === index}
              className={`shrink-0 overflow-hidden rounded-xl ring-2 transition ${i === index ? 'ring-brand-600' : 'opacity-70 ring-transparent hover:opacity-100'}`}>
              <img src={img.url} alt="" className="size-20 object-cover" />
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
