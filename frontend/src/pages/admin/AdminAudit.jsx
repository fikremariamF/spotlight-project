import { useSearchParams } from 'react-router-dom'
import { EmptyState, ErrorState, PageHeader, Pagination, Spinner } from '../../components/ui'
import { toQuery } from '../../lib/format'
import { useFetch } from '../../lib/useFetch'

export default function AdminAudit() {
  const [params, setParams] = useSearchParams()
  const { data, error, loading, reload } = useFetch(`/admin/audit-logs?${toQuery({ action: params.get('action') || '', page: params.get('page') || '' })}`)

  return (
    <>
      <PageHeader title="Audit log" subtitle="Sensitive actions: approvals, moderation and listing changes." />
      <select className="input mb-4 sm:w-56" value={params.get('action') || ''} onChange={(e) => setParams(toQuery({ action: e.target.value }))} aria-label="Filter">
        <option value="">All actions</option>
        <option value="seller.">Seller actions</option>
        <option value="product.">Listing actions</option>
        <option value="category.">Category actions</option>
      </select>
      {loading && <Spinner />}
      {error && <ErrorState error={error} onRetry={reload} />}
      {data && (data.data.length ? (
        <>
          <div className="card relative overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-stone-200 text-xs text-stone-500 uppercase">
                <tr><th className="px-4 py-3">When</th><th className="px-4 py-3">Who</th><th className="px-4 py-3">Action</th><th className="px-4 py-3">Subject</th><th className="px-4 py-3">Details</th></tr>
              </thead>
              <tbody className="divide-y divide-stone-100">
                {data.data.map((log) => (
                  <tr key={log.id}>
                    <td className="px-4 py-2.5 whitespace-nowrap text-stone-600">{new Date(log.created_at).toLocaleString()}</td>
                    <td className="px-4 py-2.5">{log.user?.email || 'System'}</td>
                    <td className="px-4 py-2.5 font-medium">{log.action}</td>
                    <td className="px-4 py-2.5 text-stone-600">{log.subject_type ? `${log.subject_type.split('\\').pop()} #${log.subject_id}` : ''}</td>
                    <td className="max-w-xs truncate px-4 py-2.5 text-stone-600" title={JSON.stringify(log.meta)}>{log.meta ? JSON.stringify(log.meta) : ''}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination meta={{ current_page: data.current_page, last_page: data.last_page }} onPage={(p) => setParams(toQuery({ action: params.get('action') || '', page: p }))} />
        </>
      ) : <EmptyState title="No activity yet" />)}
    </>
  )
}
