import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { ProductImage } from '../../components/ProductCard'
import { Alert, EmptyState, ErrorState, PageHeader, Pagination, Spinner, StatusBadge } from '../../components/ui'
import { api, parseError } from '../../lib/api'
import { CONDITION_LABELS, formatDimensions, formatETB, toQuery } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'

export default function AdminListings() {
  const [params, setParams] = useSearchParams()
  const status = params.get('status') || 'pending_review'
  const list = useFetch(`/admin/products?${toQuery({ status, page: params.get('page') || '' })}`)
  const [notice, setNotice] = useState(null)
  const [open, setOpen] = useState(null)
  const detail = useFetch(open ? `/admin/products/${open}` : null)

  async function moderate(product, action) {
    let note = null
    if (action === 'reject') {
      note = window.prompt(`Why is "${product.name}" being ${product.status === 'published' ? 'taken down' : 'rejected'}? The seller will see this note.`)
      if (!note?.trim()) return
    }
    try {
      await api.post(`/admin/products/${product.id}/${action}`, { note })
      setNotice({ tone: 'success', text: `"${product.name}" ${action === 'approve' ? 'published' : 'rejected'}.` })
      setOpen(null)
      list.reload()
    } catch (err) {
      const e = parseError(err)
      setNotice({ tone: 'error', text: Object.values(e.fields)[0] || e.message })
    }
  }

  return (
    <>
      <PageHeader title="Listings" subtitle="Oldest submissions first." />
      <select className="input mb-4 sm:w-56" value={status} onChange={(e) => setParams({ status: e.target.value })} aria-label="Status">
        <option value="pending_review">Waiting for review</option>
        <option value="published">Published</option>
        <option value="rejected">Rejected</option>
        <option value="draft">Drafts</option>
        <option value="archived">Archived</option>
      </select>
      {notice && <div className="mb-4"><Alert tone={notice.tone}>{notice.text}</Alert></div>}
      {list.loading && <Spinner />}
      {list.error && <ErrorState error={list.error} onRetry={list.reload} />}
      {list.data && (list.data.data.length ? (
        <>
          <ul className="space-y-3">
            {list.data.data.map((p) => (
              <li key={p.id} className="card p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                  <ProductImage src={p.image} alt={p.name} className="size-20 shrink-0 rounded-lg" />
                  <div className="min-w-0 flex-1 text-sm">
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="font-semibold text-stone-900">{p.name}</p>
                      <StatusBadge status={p.status} />
                    </div>
                    <p className="text-stone-600">{formatETB(p.price_santim)} · {p.category?.name} · {p.seller?.store_name}</p>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    <button type="button" className="btn-ghost" onClick={() => setOpen(open === p.id ? null : p.id)} aria-expanded={open === p.id}>
                      {open === p.id ? 'Hide details' : 'Review details'}
                    </button>
                    {p.status === 'pending_review' && <button type="button" className="btn-primary" onClick={() => moderate(p, 'approve')}>Approve</button>}
                    {['pending_review', 'published'].includes(p.status) && (
                      <button type="button" className="btn-secondary text-red-700" onClick={() => moderate(p, 'reject')}>{p.status === 'published' ? 'Take down' : 'Reject'}</button>
                    )}
                    {p.status === 'published' && <Link to={`/products/${p.slug}`} className="btn-ghost">View</Link>}
                  </div>
                </div>
                {open === p.id && (
                  <div className="mt-4 border-t border-stone-100 pt-4 text-sm">
                    {detail.loading && <Spinner />}
                    {detail.data && (
                      <>
                        <div className="mb-3 flex gap-2 relative overflow-x-auto">
                          {detail.data.data.images.map((img) => <img key={img.id} src={img.url} alt="" className="h-32 rounded-lg object-cover" />)}
                        </div>
                        <p className="whitespace-pre-line text-stone-700">{detail.data.data.description}</p>
                        <p className="mt-2 text-stone-600">
                          {CONDITION_LABELS[detail.data.data.condition]} · {formatDimensions(detail.data.data.dimensions) || 'No dimensions'} · {detail.data.data.material || 'No material'} · {detail.data.data.color || 'No color'}
                        </p>
                      </>
                    )}
                  </div>
                )}
              </li>
            ))}
          </ul>
          <Pagination meta={list.data.meta} onPage={(pg) => setParams({ status, page: String(pg) })} />
        </>
      ) : <EmptyState title="Nothing to review">New listings appear here when sellers submit them.</EmptyState>)}
    </>
  )
}
