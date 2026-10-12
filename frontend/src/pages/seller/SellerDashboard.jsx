import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { ProductImage } from '../../components/ProductCard'
import { Alert, EmptyState, ErrorState, PageHeader, Pagination, Spinner, StatusBadge } from '../../components/ui'
import { api, parseError } from '../../lib/api'
import { formatETB, toQuery } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'

const TABS = [
  ['', 'All'],
  ['published', 'Published'],
  ['pending_review', 'In review'],
  ['draft', 'Drafts'],
  ['rejected', 'Rejected'],
  ['archived', 'Archived'],
]

function StoreStatus({ profile }) {
  if (profile.status === 'approved') return null
  const copy = {
    pending: ['warning', 'Your store is waiting for approval. You can prepare drafts now and submit them once approved.'],
    rejected: ['error', `Your application was not approved.${profile.status_reason ? ` Reason: ${profile.status_reason}` : ''} Contact support for help.`],
    suspended: ['error', `Your store is suspended and your listings are hidden.${profile.status_reason ? ` Reason: ${profile.status_reason}` : ''}`],
  }[profile.status]
  return <div className="mb-6"><Alert tone={copy[0]}>{copy[1]}</Alert></div>
}

export default function SellerDashboard() {
  const [params, setParams] = useSearchParams()
  const status = params.get('status') || ''
  const profile = useFetch('/seller/profile')
  const products = useFetch(`/seller/products?${toQuery({ status, page: params.get('page') || '' })}`)
  const orders = useFetch('/seller/orders/summary')
  const [notice, setNotice] = useState(null)
  const waiting = orders.data?.data.counts.pending_confirmation || 0
  const overLimit = orders.data?.data.balance.over_limit

  async function act(product, action) {
    if (action === 'delete' && !window.confirm(`Delete "${product.name}"? This cannot be undone.`)) return
    setNotice(null)
    try {
      if (action === 'delete') await api.delete(`/seller/products/${product.id}`)
      else await api.post(`/seller/products/${product.id}/${action}`)
      setNotice({ tone: 'success', text: { submit: 'Submitted for review.', archive: 'Listing hidden from the marketplace.', delete: 'Listing deleted.' }[action] })
      products.reload()
    } catch (err) {
      const e = parseError(err)
      setNotice({ tone: 'error', text: Object.values(e.fields)[0] || e.message })
    }
  }

  if (profile.loading) return <Spinner />
  if (profile.error) return <div className="container-page py-10"><ErrorState error={profile.error} onRetry={profile.reload} /></div>

  const p = profile.data.data
  const canWrite = ['pending', 'approved'].includes(p.status)

  return (
    <div className="container-page py-8">
      <PageHeader
        title={p.store_name}
        subtitle={p.status === 'approved' ? <>Your storefront: <Link className="text-brand-700 hover:underline" to={`/sellers/${p.slug}`}>view public page</Link></> : 'Seller dashboard'}
        actions={<>
          <Link to="/seller/orders" className="btn-secondary">Orders</Link>
          <Link to="/seller/balance" className="btn-secondary">Balance</Link>
          <Link to="/seller/reviews" className="btn-secondary">Reviews</Link>
          <Link to="/seller/profile" className="btn-secondary">Store profile</Link>
          {canWrite && <Link to="/seller/products/new" className="btn-primary">Add listing</Link>}
        </>}
      />
      <StoreStatus profile={p} />
      {overLimit && (
        <div className="mb-6"><Alert tone="error">
          Your unpaid commission is over the limit, so new cash orders cannot be confirmed. <Link to="/seller/balance" className="font-semibold underline">See how to pay</Link>
        </Alert></div>
      )}
      {waiting > 0 && (
        <div className="mb-6"><Alert tone="warning">
          {waiting === 1 ? '1 order is' : `${waiting} orders are`} waiting for your confirmation. <Link to="/seller/orders?status=pending_confirmation" className="font-semibold underline">Review orders</Link>
        </Alert></div>
      )}
      {notice && <div className="mb-4"><Alert tone={notice.tone}>{notice.text}</Alert></div>}

      <div className="mb-4 flex gap-1 relative overflow-x-auto border-b border-stone-200" role="tablist">
        {TABS.map(([value, label]) => (
          <button key={value} role="tab" aria-selected={status === value} type="button" onClick={() => setParams(value ? { status: value } : {})}
            className={`shrink-0 border-b-2 px-3 py-2 text-sm font-medium ${status === value ? 'border-brand-700 text-brand-800' : 'border-transparent text-stone-600 hover:text-stone-900'}`}>
            {label}
          </button>
        ))}
      </div>

      {products.loading && <Spinner />}
      {products.error && <ErrorState error={products.error} onRetry={products.reload} />}
      {products.data && (products.data.data.length ? (
        <>
          <ul className="card divide-y divide-stone-100">
            {products.data.data.map((item) => (
              <li key={item.id} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
                <ProductImage src={item.image} alt={item.name} className="size-20 shrink-0 rounded-lg" />
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="truncate font-semibold text-stone-900">{item.name}</p>
                    <StatusBadge status={item.status} />
                  </div>
                  <p className="text-sm text-stone-600">
                    {formatETB(item.price_santim)} · {item.stock_type === 'made_to_order' ? `Made to order (${item.production_days} days)` : `${item.stock_quantity} in stock`} · {item.category?.name}
                  </p>
                  {item.status === 'rejected' && item.moderation_note && <p className="mt-1 text-sm text-red-700">Reviewer note: {item.moderation_note}</p>}
                </div>
                <div className="flex flex-wrap gap-2">
                  {item.status !== 'archived' && canWrite && <Link to={`/seller/products/${item.id}/edit`} className="btn-secondary">Edit</Link>}
                  {['draft', 'rejected', 'archived'].includes(item.status) && p.status === 'approved' && (
                    <button type="button" className="btn-primary" onClick={() => act(item, 'submit')}>Submit for review</button>
                  )}
                  {['published', 'pending_review'].includes(item.status) && (
                    <button type="button" className="btn-ghost" onClick={() => act(item, 'archive')}>Hide</button>
                  )}
                  <button type="button" className="btn-ghost text-red-700" onClick={() => act(item, 'delete')}>Delete</button>
                </div>
              </li>
            ))}
          </ul>
          <Pagination meta={products.data.meta} onPage={(pg) => setParams(toQuery({ status, page: pg }))} />
        </>
      ) : (
        <EmptyState title={status ? 'Nothing here' : 'No listings yet'} action={canWrite && !status && <Link to="/seller/products/new" className="btn-primary">Add your first listing</Link>}>
          {status ? 'No listings with this status.' : 'Add photos, dimensions, materials and delivery terms so customers can compare with confidence.'}
        </EmptyState>
      ))}
    </div>
  )
}
