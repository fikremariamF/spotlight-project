import { GitCompareArrows } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { COMPARE_MAX, useCompare } from '../context/CompareContext'

/** Adds or removes a listing from the compare list. */
export default function CompareButton({ slug, compact = false }) {
  const { has, toggle, slugs } = useCompare()
  const navigate = useNavigate()
  const active = has(slug)

  function onClick() {
    const result = toggle(slug)
    if (result === 'added') {
      const count = slugs.length + 1
      toast.success('Added to compare', { action: count > 1 ? { label: `Compare ${count}`, onClick: () => navigate('/compare') } : undefined })
    } else if (result === 'full') {
      toast.warning(`You can compare up to ${COMPARE_MAX} items. Remove one first.`, { action: { label: 'Open compare', onClick: () => navigate('/compare') } })
    }
  }

  const label = active ? 'Remove from compare' : 'Add to compare'
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      aria-label={label}
      title={label}
      className={compact
        ? `flex size-9 items-center justify-center rounded-full shadow-soft backdrop-blur transition hover:scale-105 ${active ? 'bg-brand-700 text-white' : 'bg-white/95 text-stone-700 hover:bg-white'}`
        : `btn-secondary ${active ? 'border-brand-500 bg-brand-50 text-brand-800' : ''}`}
    >
      <GitCompareArrows className="size-[18px]" aria-hidden="true" />
      {!compact && <span>{active ? 'In compare' : 'Compare'}</span>}
    </button>
  )
}
