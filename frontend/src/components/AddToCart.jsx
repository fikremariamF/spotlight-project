import { Minus, Plus, ShoppingBag } from 'lucide-react'
import { useId, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { useAuth } from '../context/AuthContext'
import { useCart } from '../context/CartContext'
import { api, parseError } from '../lib/api'

/**
 * Quantity stepper and "Add to cart" for a listing. Only customer accounts can buy.
 */
export default function AddToCart({ product, full = false, compact = false }) {
  const { user } = useAuth()
  const { refreshCart } = useCart()
  const location = useLocation()
  const navigate = useNavigate()
  const id = useId()
  const [quantity, setQuantity] = useState(1)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const inStock = product.stock_type === 'in_stock'
  const max = Math.min(inStock ? product.stock_quantity : 10, 99)
  if (inStock && product.stock_quantity < 1) return null

  if (!user) {
    return <Link to="/login" state={{ from: location.pathname }} className={`btn-primary ${full ? 'w-full' : ''}`}>Sign in to buy</Link>
  }
  if (user.role !== 'customer') return null

  async function add() {
    setBusy(true)
    setError(null)
    try {
      await api.post('/cart/items', { product_slug: product.slug, quantity })
      refreshCart()
      toast.success(`Added to your cart: ${product.name}`, { action: { label: 'View cart', onClick: () => navigate('/cart') } })
    } catch (err) {
      const e = parseError(err)
      const message = Object.values(e.fields)[0] || e.message
      if (compact) toast.error(message)
      else setError(message)
    } finally {
      setBusy(false)
    }
  }

  const set = (n) => setQuantity(Math.max(1, Math.min(max, n)))
  if (compact) {
    return (
      <button type="button" className="btn-primary" disabled={busy} onClick={add}>
        <ShoppingBag className="size-4" aria-hidden="true" />
        {busy ? 'Adding...' : 'Add to cart'}
      </button>
    )
  }
  return (
    <div className="flex flex-wrap items-center gap-2">
      <div className="flex items-center rounded-xl border border-stone-300 bg-white">
        <button type="button" className="icon-btn size-10 rounded-l-xl rounded-r-none" onClick={() => set(quantity - 1)} disabled={quantity <= 1} aria-label="Fewer">
          <Minus className="size-4" aria-hidden="true" />
        </button>
        <label htmlFor={id} className="sr-only">Quantity</label>
        <input id={id} type="number" inputMode="numeric" min={1} max={max} value={quantity}
          onChange={(e) => set(Number(e.target.value) || 1)}
          className="w-12 [appearance:textfield] border-0 bg-transparent text-center text-sm font-semibold text-stone-900 focus:outline-none [&::-webkit-inner-spin-button]:appearance-none" />
        <button type="button" className="icon-btn size-10 rounded-l-none rounded-r-xl" onClick={() => set(quantity + 1)} disabled={quantity >= max} aria-label="More">
          <Plus className="size-4" aria-hidden="true" />
        </button>
      </div>
      <button type="button" className={`btn-primary ${full ? 'flex-1' : ''}`} disabled={busy} onClick={add}>
        <ShoppingBag className="size-4" aria-hidden="true" />
        {busy ? 'Adding...' : 'Add to cart'}
      </button>
      {error && <p role="alert" className="w-full text-sm text-red-700">{error}</p>}
    </div>
  )
}
