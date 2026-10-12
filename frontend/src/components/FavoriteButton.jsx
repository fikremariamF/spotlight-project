import { Heart } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { useAuth } from '../context/AuthContext'
import { api } from '../lib/api'

export default function FavoriteButton({ productId, initial = false, compact = false }) {
  const { user } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [saved, setSaved] = useState(Boolean(initial))
  const [busy, setBusy] = useState(false)

  useEffect(() => setSaved(Boolean(initial)), [initial])

  async function toggle() {
    if (!user) {
      navigate('/login', { state: { from: location.pathname + location.search } })
      return
    }
    setBusy(true)
    const next = !saved
    setSaved(next) // optimistic
    try {
      if (next) await api.post(`/favorites/${productId}`)
      else await api.delete(`/favorites/${productId}`)
      if (next) toast.success('Saved to your wishlist', { action: { label: 'View', onClick: () => navigate('/wishlist') } })
    } catch {
      setSaved(!next)
      toast.error('Could not update your wishlist. Try again.')
    } finally {
      setBusy(false)
    }
  }

  const label = saved ? 'Remove from wishlist' : 'Save to wishlist'
  return (
    <button
      type="button"
      onClick={toggle}
      disabled={busy}
      aria-pressed={saved}
      aria-label={label}
      title={label}
      className={compact
        ? 'flex size-9 items-center justify-center rounded-full bg-white/95 shadow-soft backdrop-blur transition hover:scale-105 hover:bg-white'
        : 'btn-secondary'}
    >
      <Heart className={`size-[18px] transition-colors ${saved ? 'fill-red-500 stroke-red-500' : 'stroke-stone-700'}`} strokeWidth={2} aria-hidden="true" />
      {!compact && <span>{saved ? 'Saved' : 'Save'}</span>}
    </button>
  )
}

