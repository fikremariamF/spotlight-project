import { ArrowLeft, Download, ExternalLink } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ErrorState, PageHeader, Spinner, Stats } from '../../../components/ui'
import { api, parseError } from '../../../lib/api'
import { useFetch } from '../../../lib/useFetch'
import { RECEIPT_TYPES, receiptProblem } from './ErpExpenses'
import { Badge, Err, PAY_METHODS, ReasonForm, money, useReadOnly, useSubmit } from './shared'

const STATUS = { recorded: ['Recorded', 'bg-emerald-100 text-emerald-800'], voided: ['Voided', 'bg-stone-200 text-stone-700'] }

export default function ErpExpense() {
  const { id } = useParams()
  const readOnly = useReadOnly()
  const res = useFetch(`/seller/erp/expenses/${id}`)
  const e = res.data?.data
  const [panel, setPanel] = useState(null)
  const { busy, errors, submit } = useSubmit((body) => { res.setData(body); setPanel(null) })

  if (res.loading && !e) return <Spinner />
  if (res.error) return <ErrorState error={res.error} onRetry={res.reload} />

  const voided = e.status === 'voided'
  const editable = !readOnly && !voided

  return (
    <>
      <Link to="/seller/erp/expenses" className="mb-3 inline-flex items-center gap-1 text-sm text-stone-600 hover:underline"><ArrowLeft className="size-4" aria-hidden="true" />Expenses</Link>
      <PageHeader title={e.description}
        subtitle={<span className="inline-flex flex-wrap items-center gap-2"><Badge map={STATUS} value={e.status} /><span>{e.number}, {e.spent_on}</span></span>}
        actions={editable && !panel && <>
          <button type="button" className="btn-secondary" onClick={() => setPanel('edit')}>Change details</button>
          <button type="button" className="btn-ghost" onClick={() => setPanel('void')}>Void expense</button>
        </>} />

      {panel === 'void' && (
        <div className="mb-4">
          <ReasonForm label="Why is this expense wrong?" button="Void expense" busy={busy} errors={errors} onBack={() => setPanel(null)}
            hint="A voided expense stays in your history, crossed out, and stops counting in your totals. Its receipt stays attached."
            onConfirm={(reason) => submit(() => api.post(`/seller/erp/expenses/${e.id}/void`, { reason }), 'Expense voided')} />
        </div>
      )}
      {panel === 'edit' && <EditForm expense={e} onDone={(body) => { res.setData(body); setPanel(null) }} onCancel={() => setPanel(null)} />}
      {voided && <p className="mb-4 rounded-lg bg-stone-100 px-4 py-3 text-sm text-stone-700">Voided: {e.void_reason}</p>}

      <Stats items={[
        { label: 'Amount', value: money(e.amount_santim), hint: voided ? 'not counted' : undefined },
        { label: 'Date', value: e.spent_on },
      ]} />

      <section className="card mt-5 p-4">
        <h2 className="mb-2 font-semibold text-stone-900">Details</h2>
        <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
          <div><dt className="text-xs text-stone-500">Category</dt><dd>{e.category?.name || 'None'}</dd></div>
          <div><dt className="text-xs text-stone-500">Paid by</dt><dd>{PAY_METHODS[e.payment_method] || e.payment_method}</dd></div>
          <div><dt className="text-xs text-stone-500">Paid to</dt><dd>{e.paid_to || 'Not set'}</dd></div>
          <div><dt className="text-xs text-stone-500">Reference</dt><dd>{e.reference || 'None'}</dd></div>
          {e.by && <div><dt className="text-xs text-stone-500">Recorded by</dt><dd>{e.by}</dd></div>}
          {e.created_at && <div><dt className="text-xs text-stone-500">Recorded on</dt><dd>{new Date(e.created_at).toLocaleString('en-GB', { timeZone: 'Africa/Addis_Ababa', dateStyle: 'medium', timeStyle: 'short' })}</dd></div>}
        </dl>
      </section>

      <section className="card mt-5 p-4">
        <h2 className="mb-2 font-semibold text-stone-900">Receipt</h2>
        {e.attachment ? <Receipt expense={e} />
          : editable ? <AttachForm expense={e} onDone={(body) => res.setData(body)} />
            : <p className="text-sm text-stone-500">No receipt attached.</p>}
      </section>
      {!['void'].includes(panel) && <Err errors={errors} />}
    </>
  )
}

/** Category, description, who was paid and the reference. The amount and date stay as recorded. */
function EditForm({ expense, onDone, onCancel }) {
  const cats = useFetch('/seller/erp/expense-categories')
  const [v, setV] = useState({ expense_category_id: String(expense.category?.id ?? ''), description: expense.description, paid_to: expense.paid_to || '', reference: expense.reference || '' })
  const { busy, errors, submit } = useSubmit(onDone)
  const set = (k) => (ev) => setV({ ...v, [k]: ev.target.value })
  // A hidden category stays offered when it is the one already set.
  const options = (cats.data?.data || []).filter((c) => c.is_active || String(c.id) === v.expense_category_id)

  return (
    <form noValidate className="card mb-5 space-y-3 p-4" aria-label="Change details" onSubmit={(ev) => {
      ev.preventDefault()
      submit(() => api.put(`/seller/erp/expenses/${expense.id}`, {
        expense_category_id: Number(v.expense_category_id), description: v.description.trim(), paid_to: v.paid_to.trim() || null, reference: v.reference.trim() || null,
      }), 'Saved')
    }}>
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="text-sm text-stone-700" htmlFor="exe-cat">Category
          <select id="exe-cat" className="input mt-1" value={v.expense_category_id} onChange={set('expense_category_id')} disabled={!cats.data}>
            {options.map((c) => <option key={c.id} value={c.id}>{c.name}{c.is_active ? '' : ' (hidden)'}</option>)}
          </select>
        </label>
        <label className="text-sm text-stone-700" htmlFor="exe-desc">What for
          <input id="exe-desc" className="input mt-1" maxLength={255} value={v.description} onChange={set('description')} />
        </label>
        <label className="text-sm text-stone-700" htmlFor="exe-to">Paid to
          <input id="exe-to" className="input mt-1" maxLength={120} value={v.paid_to} onChange={set('paid_to')} />
        </label>
        <label className="text-sm text-stone-700" htmlFor="exe-ref">Reference
          <input id="exe-ref" className="input mt-1" maxLength={100} value={v.reference} onChange={set('reference')} />
        </label>
      </div>
      <p className="text-xs text-stone-500">The amount, date and payment method cannot be changed. If one is wrong, void this expense and record it again.</p>
      <Err errors={errors} />
      <div className="flex justify-end gap-2">
        <button type="button" className="btn-ghost" onClick={onCancel} disabled={busy}>Cancel</button>
        <button type="submit" className="btn-primary" disabled={busy || v.description.trim().length < 2 || !v.expense_category_id}>{busy ? 'Saving...' : 'Save'}</button>
      </div>
    </form>
  )
}

/**
 * The receipt is fetched with the store's sign-in and shown from memory: its address alone opens
 * nothing, so it cannot be passed around.
 */
function Receipt({ expense }) {
  const [file, setFile] = useState({ url: null, error: null })
  const image = expense.attachment.mime.startsWith('image/')
  useEffect(() => {
    let url = null
    let live = true
    api.get(`/seller/erp/expenses/${expense.id}/attachment`, { responseType: 'blob' })
      .then((r) => {
        url = URL.createObjectURL(r.data)
        if (live) setFile({ url, error: null })
        else URL.revokeObjectURL(url)
      })
      .catch((err) => live && setFile({ url: null, error: err?.response?.status === 404 ? 'The receipt file could not be found.' : parseError(err).message }))
    return () => {
      live = false
      if (url) URL.revokeObjectURL(url)
    }
  }, [expense.id])

  const size = expense.attachment.size >= 1024 * 1024 ? `${(expense.attachment.size / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(expense.attachment.size / 1024))} KB`
  if (file.error) return <p role="alert" className="text-sm text-red-700">{file.error}</p>
  if (!file.url) return <p className="text-sm text-stone-500">Loading the receipt...</p>
  return (
    <div className="space-y-3">
      {image && <img src={file.url} alt={`Receipt for ${expense.description}`} className="max-h-[32rem] max-w-full rounded-lg border border-stone-200 object-contain" />}
      <p className="flex flex-wrap items-center gap-2 text-sm text-stone-600">
        <span className="min-w-0 break-all">{expense.attachment.name} ({size})</span>
        {!image && <a href={file.url} target="_blank" rel="noopener" className="btn-secondary"><ExternalLink className="size-4" aria-hidden="true" />Open PDF</a>}
        <a href={file.url} download={expense.attachment.name} className="btn-ghost"><Download className="size-4" aria-hidden="true" />Download</a>
      </p>
    </div>
  )
}

/** Add the receipt later, once. */
function AttachForm({ expense, onDone }) {
  const [file, setFile] = useState(null)
  const { busy, errors, submit } = useSubmit()
  const problem = receiptProblem(file)
  return (
    <form noValidate className="space-y-2" onSubmit={async (ev) => {
      ev.preventDefault()
      const form = new FormData()
      form.append('attachment', file)
      const body = await submit(() => api.post(`/seller/erp/expenses/${expense.id}/attachment`, form), 'Receipt attached')
      if (body) onDone(body)
    }}>
      <p className="text-sm text-stone-600">No receipt yet. Add a photo or PDF of it (up to 5 MB). Once attached it cannot be swapped.</p>
      <label htmlFor="exa-file" className="sr-only">Receipt file</label>
      <input id="exa-file" type="file" className="block w-full text-sm" accept={RECEIPT_TYPES.join(',')} onChange={(ev) => setFile(ev.target.files?.[0] || null)} />
      {(problem || errors.attachment || errors.form) && <p role="alert" className="text-xs font-medium text-red-700">{problem || errors.attachment || errors.form}</p>}
      <button type="submit" className="btn-primary" disabled={busy || !file || !!problem}>{busy ? 'Uploading...' : 'Attach receipt'}</button>
    </form>
  )
}
