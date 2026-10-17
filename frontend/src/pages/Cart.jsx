import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ProductImage } from '../components/ProductCard'
import { Alert, EmptyState, ErrorState, PageHeader, Spinner } from '../components/ui'
import { useCart } from '../context/CartContext'
import { api, parseError } from '../lib/api'
import { formatETB } from '../lib/format'
import { useFetch } from '../lib/useFetch'

export function CartLine({ line, onChange, readOnly }) {
  const [busy, setBusy] = useState(false)

  async function run(fn) {
    setBusy(true)
    try {
      await fn()
    } finally {
      setBusy(false)
    }
  }

  const options = Array.from({ length: Math.max(line.max_quantity, line.quantity) }, (_, i) => i + 1)

  return (
    <li className="flex gap-3 py-3">
      <ProductImage src={line.product?.image} alt="" className="size-16 shrink-0 rounded-lg" />
      <div className="min-w-0 flex-1">
        <p className="font-medium text-stone-900">
          {line.product?.slug && line.kind === 'product' ? <Link to={`/products/${line.product.slug}`} className="hover:underline">{line.name}</Link> : line.name}
        </p>
        <p className="text-sm text-stone-600">
          {line.kind === 'quote' ? `Custom quote, ${line.quantity} x ${formatETB(line.unit_price_santim)}` : `${formatETB(line.unit_price_santim)} each`}
          {line.product?.stock_type === 'made_to_order' && ` · made to order in ${line.product.production_days} days`}
          {line.kind === 'quote' && line.quote.production_days !== null && ` · ready ${line.quote.production_days} days after confirmation`}
        </p>
        {line.problem && <p className="mt-1 text-sm font-medium text-red-700">{line.problem}</p>}
        {!readOnly && (
          <div className="mt-2 flex items-center gap-2">
            {line.kind === 'product' ? (
              <>
                <label htmlFor={`qty-${line.id}`} className="sr-only">Quantity for {line.name}</label>
                <select id={`qty-${line.id}`} className="input w-20 py-1" value={line.quantity} disabled={busy}
                  onChange={(e) => run(() => onChange(api.put(`/cart/items/${line.id}`, { quantity: Number(e.target.value) })))}>
                  {options.map((n) => <option key={n} value={n}>{n}</option>)}
                </select>
              </>
            ) : <span className="text-sm text-stone-500">Quantity {line.quantity}</span>}
            <button type="button" className="btn-ghost text-sm text-red-700" disabled={busy}
              onClick={() => run(() => onChange(api.delete(`/cart/items/${line.id}`)))}>Remove</button>
          </div>
        )}
      </div>
      <p className="shrink-0 font-semibold text-stone-900">{formatETB(line.line_total_santim)}</p>
    </li>
  )
}

export default function Cart() {
  const cart = useFetch('/cart')
  const { refreshCart } = useCart()
  const [error, setError] = useState(null)

  async function change(request) {
    setError(null)
    try {
      const res = await request
      cart.setData(res.data)
    } catch (err) {
      const e = parseError(err)
      setError(Object.values(e.fields)[0] || e.message)
      cart.reload()
    }
    refreshCart()
  }

  if (cart.loading && !cart.data) return <Spinner />
  if (cart.error) return <div className="container-page py-10"><ErrorState error={cart.error} onRetry={cart.reload} /></div>
  const c = cart.data.data

  return (
    <div className="container-page max-w-4xl py-8">
      <PageHeader title="Your cart" subtitle={c.line_count ? 'Items from each seller become a separate order with its own delivery.' : null} />
      {error && <div className="mb-4"><Alert tone="error">{error}</Alert></div>}
      {c.line_count === 0 ? (
        <EmptyState title="Your cart is empty" action={<Link to="/search" className="btn-primary">Browse furniture</Link>}>
          Add furniture from any listing, or order an offer you accepted in Support.
        </EmptyState>
      ) : (
        <div className="grid gap-6 lg:grid-cols-[1fr_280px]">
          <div className="space-y-4">
            {c.groups.map((g) => (
              <section key={g.seller.id} className="card px-4 py-2">
                <h2 className="border-b border-stone-100 py-2 text-sm font-semibold text-stone-700">
                  From <Link to={`/sellers/${g.seller.slug}`} className="text-brand-700 hover:underline">{g.seller.store_name}</Link>
                  {g.seller.area && <span className="font-normal text-stone-500"> in {g.seller.area}</span>}
                </h2>
                <ul className="divide-y divide-stone-100">
                  {g.lines.map((l) => <CartLine key={l.id} line={l} onChange={change} />)}
                </ul>
              </section>
            ))}
          </div>
          <aside className="card h-fit space-y-3 p-4">
            <div className="flex justify-between text-sm"><span>Items</span><span className="font-semibold">{formatETB(c.items_total_santim)}</span></div>
            <p className="text-xs text-stone-500">Delivery is calculated at checkout from your address.</p>
            {c.has_problems && <Alert tone="warning">Fix the items marked in red before checking out.</Alert>}
            {c.has_problems
              ? <button type="button" className="btn-primary w-full" disabled>Go to checkout</button>
              : <Link to="/checkout" className="btn-primary w-full">Go to checkout</Link>}
          </aside>
        </div>
      )}
    </div>
  )
}
