import { Paperclip, Search } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { EmptyState, ErrorState, PageHeader, Pagination, Spinner, Stats } from '../../../components/ui'
import { api } from '../../../lib/api'
import { newIdempotencyKey, toQuery } from '../../../lib/format'
import { useFetch } from '../../../lib/useFetch'
import { today } from './SaleParts'
import { Badge, CsvButton, Err, PAY_METHODS, PeriodPicker, cleanBirr, money, toSantim, usePeriod, useReadOnly, useSubmit } from './shared'

const STATUS = { recorded: ['Recorded', 'bg-emerald-100 text-emerald-800'], voided: ['Voided', 'bg-stone-200 text-stone-700'] }
const RECEIPTS = [['', 'Any'], ['without', 'No receipt'], ['with', 'With receipt']]
const STATUSES = [['', 'All'], ['recorded', 'Recorded'], ['voided', 'Voided']]

/** Receipt files the server takes: photos or PDFs up to 5 MB. */
export const RECEIPT_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']
export const RECEIPT_MAX = 5 * 1024 * 1024
export function receiptProblem(file) {
  if (!file) return null
  if (!RECEIPT_TYPES.includes(file.type)) return 'Attach a photo (JPG, PNG or WebP) or a PDF.'
  if (file.size > RECEIPT_MAX) return 'The receipt file can be up to 5 MB.'
  return null
}

/** What it cost to run the store: rent, transport, packaging and so on, each with its receipt. */
export default function ErpExpenses() {
  const readOnly = useReadOnly()
  const navigate = useNavigate()
  const period = usePeriod()
  const [params, setParams] = useSearchParams()
  const filters = { category_id: params.get('category_id') || '', method: params.get('method') || '', status: params.get('status') || '', receipt: params.get('receipt') || '', q: params.get('q') || '', page: params.get('page') || '' }
  const [q, setQ] = useState(filters.q)
  const [panel, setPanel] = useState(null)
  const list = useFetch(`/seller/erp/expenses?${period.query}&${toQuery(filters)}`)
  const categories = useFetch('/seller/erp/expense-categories')
  const set = (next) => setParams(toQuery({ ...Object.fromEntries(params), page: '', ...next }), { replace: true })
  const meta = list.data?.meta
  const rows = list.data?.data || []
  const cats = categories.data?.data || []
  const filtered = filters.category_id || filters.method || filters.status || filters.receipt || filters.q
  const csvQuery = toQuery({ category_id: filters.category_id, method: filters.method, receipt: filters.receipt, q: filters.q })

  return (
    <>
      <PageHeader title="Expenses"
        subtitle="Money spent to run the store. Stock you buy from suppliers belongs under Purchases, so it is not counted twice."
        actions={<>
          {!readOnly && panel !== 'new' && <button type="button" className="btn-primary" onClick={() => setPanel('new')}>Record expense</button>}
          {!readOnly && <button type="button" className="btn-ghost" onClick={() => setPanel(panel === 'categories' ? null : 'categories')} aria-expanded={panel === 'categories'}>Categories</button>}
          <CsvButton url={`/seller/erp/expenses.csv?${period.query}${csvQuery ? `&${csvQuery}` : ''}`} filename={meta ? `expenses-${meta.range.from}-to-${meta.range.to}.csv` : 'expenses.csv'} />
        </>} />

      {panel === 'new' && (
        <div className="mb-5">
          {categories.error ? <ErrorState error={categories.error} onRetry={categories.reload} /> : !categories.data ? <Spinner /> : (
            <ExpenseForm categories={cats.filter((c) => c.is_active)} onCancel={() => setPanel(null)}
              onDone={(body) => navigate(`/seller/erp/expenses/${body.data.id}`)} />
          )}
        </div>
      )}
      {panel === 'categories' && <Categories res={categories} onClose={() => setPanel(null)} onChange={list.reload} />}

      <div className="mb-4"><PeriodPicker value={period} /></div>

      {meta && (
        <Stats items={[
          { label: 'Spent', value: money(meta.total_santim), hint: `${meta.count} expense${meta.count === 1 ? '' : 's'}, voided left out` },
          { label: 'Without a receipt', value: meta.without_receipt, tone: meta.without_receipt ? 'warn' : undefined, hint: meta.without_receipt ? 'attach one when you can' : 'all have one', to: meta.without_receipt ? `/seller/erp/expenses?${toQuery({ ...Object.fromEntries(params), receipt: 'without', status: 'recorded', page: '' })}` : undefined },
        ]} />
      )}

      {meta?.by_category?.length > 0 && (
        <section className="card mt-5 p-4">
          <h2 className="mb-3 font-semibold text-stone-900">By category</h2>
          <ul className="space-y-2 text-sm">
            {meta.by_category.map((c) => (
              <li key={c.id}>
                <button type="button" className="block w-full text-left" onClick={() => set({ category_id: String(c.id) })} aria-pressed={filters.category_id === String(c.id)}>
                  <span className="flex justify-between gap-3"><span className="truncate font-medium text-stone-800">{c.name}</span><span className="shrink-0 tabular-nums">{money(c.total_santim)} ({c.count})</span></span>
                  <span className="mt-1 block h-2 rounded-full bg-stone-100" aria-hidden="true">
                    <span className="block h-2 rounded-full bg-brand-600" style={{ width: `${meta.total_santim ? Math.max(2, Math.round((c.total_santim / meta.total_santim) * 100)) : 0}%` }} />
                  </span>
                </button>
              </li>
            ))}
          </ul>
        </section>
      )}

      <div className="mt-5 mb-4 space-y-3">
        <div className="flex flex-wrap gap-2">
          {STATUSES.map(([key, label]) => (
            <button key={key || 'all'} type="button" aria-pressed={filters.status === key} onClick={() => set({ status: key })}
              className={`chip ${filters.status === key ? 'border-brand-600 bg-brand-50 font-semibold text-brand-800' : ''}`}>{label}</button>
          ))}
          <span className="mx-1 hidden w-px bg-stone-200 sm:block" aria-hidden="true" />
          {RECEIPTS.map(([key, label]) => (
            <button key={key || 'any'} type="button" aria-pressed={filters.receipt === key} onClick={() => set({ receipt: key })}
              className={`chip ${filters.receipt === key ? 'border-brand-600 bg-brand-50 font-semibold text-brand-800' : ''}`}>{label}</button>
          ))}
        </div>
        <div className="grid gap-2 sm:grid-cols-2">
          <label className="text-sm text-stone-700" htmlFor="ex-cat">Category
            <select id="ex-cat" className="input mt-1" value={filters.category_id} onChange={(e) => set({ category_id: e.target.value })}>
              <option value="">All categories</option>
              {cats.map((c) => <option key={c.id} value={c.id}>{c.name}{c.is_active ? '' : ' (hidden)'}</option>)}
            </select>
          </label>
          <label className="text-sm text-stone-700" htmlFor="ex-method">Paid by
            <select id="ex-method" className="input mt-1" value={filters.method} onChange={(e) => set({ method: e.target.value })}>
              <option value="">Any way</option>
              {Object.entries(PAY_METHODS).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
            </select>
          </label>
        </div>
        <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); set({ q: q.trim() }) }}>
          <label htmlFor="ex-q" className="sr-only">Search expenses</label>
          <span className="relative block min-w-0 flex-1">
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" aria-hidden="true" />
            <input id="ex-q" type="search" className="input pl-9" placeholder="Description, paid to, number or reference" value={q} onChange={(e) => setQ(e.target.value)} maxLength={100} />
          </span>
          <button type="submit" className="btn-secondary">Search</button>
          {filtered && <button type="button" className="btn-ghost" onClick={() => { setQ(''); set({ category_id: '', method: '', status: '', receipt: '', q: '' }) }}>Clear</button>}
        </form>
      </div>

      {list.loading && !list.data ? <Spinner /> : list.error ? <ErrorState error={list.error} onRetry={list.reload} /> : rows.length === 0 ? (
        <EmptyState title={filtered ? 'Nothing matches' : 'No expenses in this period'}
          action={!filtered && !readOnly && panel !== 'new' && <button type="button" className="btn-primary" onClick={() => setPanel('new')}>Record expense</button>}>
          {filtered ? 'Try another filter or a longer period.' : 'Record rent, transport, packaging, repairs and other running costs here, with a photo of the receipt.'}
        </EmptyState>
      ) : (
        <div className="card relative overflow-x-auto">
          <table className="rtable w-full text-left text-sm">
            <thead className="bg-stone-50 text-xs text-stone-500 uppercase">
              <tr><th className="px-4 py-2">Expense</th><th className="px-4 py-2">Date</th><th className="px-4 py-2">Category</th><th className="px-4 py-2">Paid by</th><th className="px-4 py-2 text-right">Amount</th></tr>
            </thead>
            <tbody className="divide-y divide-stone-100">
              {rows.map((e) => {
                const voided = e.status === 'voided'
                return (
                  <tr key={e.id} className={voided ? 'text-stone-400' : ''}>
                    <td className="rtable-title px-4 py-2">
                      <Link to={`/seller/erp/expenses/${e.id}`} className="font-semibold hover:underline">{e.description}</Link>
                      <span className="block text-xs font-normal text-stone-500">
                        {e.number}{e.paid_to ? `, to ${e.paid_to}` : ''}
                        {e.attachment ? <Paperclip className="ml-1 inline size-3" aria-label="Receipt attached" /> : !voided && <span className="ml-1 text-amber-800">no receipt</span>}
                      </span>
                      {voided && <Badge map={STATUS} value="voided" />}
                    </td>
                    <td data-label="Date" className="px-4 py-2 whitespace-nowrap">{e.spent_on}</td>
                    <td data-label="Category" className="px-4 py-2">{e.category?.name}</td>
                    <td data-label="Paid by" className="px-4 py-2">{PAY_METHODS[e.payment_method] || e.payment_method}</td>
                    <td data-label="Amount" className="px-4 py-2 text-right font-semibold tabular-nums"><span className={voided ? 'line-through' : ''}>{money(e.amount_santim)}</span></td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
      <Pagination meta={meta} onPage={(page) => set({ page })} />
    </>
  )
}

/** Record an expense, with an optional receipt file. A repeated submit is saved once. */
export function ExpenseForm({ categories, onDone, onCancel }) {
  const [v, setV] = useState({ expense_category_id: String(categories[0]?.id ?? ''), spent_on: today(), amount: '', description: '', paid_to: '', payment_method: 'cash', reference: '' })
  const [file, setFile] = useState(null)
  const [requestId] = useState(newIdempotencyKey)
  const { busy, errors, submit } = useSubmit()
  const set = (k) => (e) => setV({ ...v, [k]: e.target.value })
  const amount = toSantim(v.amount)
  const fileProblem = receiptProblem(file)
  const fieldError = (k) => errors[k] && <span className="mt-1 block text-xs font-medium text-red-700">{errors[k]}</span>

  async function save(e) {
    e.preventDefault()
    const form = new FormData()
    for (const [k, value] of Object.entries({ ...v, amount: cleanBirr(v.amount), description: v.description.trim(), paid_to: v.paid_to.trim(), reference: v.reference.trim(), request_id: requestId })) {
      if (value !== '') form.append(k, value)
    }
    if (file) form.append('attachment', file)
    const body = await submit(() => api.post('/seller/erp/expenses', form), 'Expense recorded')
    if (body) onDone(body)
  }

  if (categories.length === 0) {
    return <p className="card p-4 text-sm text-stone-600">Every category is hidden. Show one under Categories first.</p>
  }
  return (
    <form noValidate onSubmit={save} className="card space-y-3 p-4" aria-label="Record an expense">
      <h2 className="font-semibold text-stone-900">Record an expense</h2>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <label className="text-sm text-stone-700" htmlFor="exf-cat">Category
          <select id="exf-cat" className="input mt-1" value={v.expense_category_id} onChange={set('expense_category_id')}>
            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
          {fieldError('expense_category_id')}
        </label>
        <label className="text-sm text-stone-700" htmlFor="exf-amount">Amount (ETB)
          <input id="exf-amount" className="input mt-1" inputMode="decimal" value={v.amount} onChange={set('amount')} aria-invalid={errors.amount || amount === null ? true : undefined} />
          {fieldError('amount')}
        </label>
        <label className="text-sm text-stone-700" htmlFor="exf-date">Date
          <input id="exf-date" type="date" className="input mt-1" max={today()} value={v.spent_on} onChange={set('spent_on')} />
          {fieldError('spent_on')}
        </label>
        <label className="text-sm text-stone-700 sm:col-span-2" htmlFor="exf-desc">What for
          <input id="exf-desc" className="input mt-1" maxLength={255} value={v.description} onChange={set('description')} placeholder="For example: truck to Bole, October shop rent" aria-invalid={errors.description ? true : undefined} />
          {fieldError('description')}
        </label>
        <label className="text-sm text-stone-700" htmlFor="exf-to">Paid to
          <input id="exf-to" className="input mt-1" maxLength={120} value={v.paid_to} onChange={set('paid_to')} placeholder="Optional" />
        </label>
        <label className="text-sm text-stone-700" htmlFor="exf-method">Paid by
          <select id="exf-method" className="input mt-1" value={v.payment_method} onChange={set('payment_method')}>
            {Object.entries(PAY_METHODS).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
          </select>
        </label>
        <label className="text-sm text-stone-700" htmlFor="exf-ref">Reference
          <input id="exf-ref" className="input mt-1" maxLength={100} value={v.reference} onChange={set('reference')} placeholder="Receipt or transaction number" />
        </label>
        <label className="text-sm text-stone-700" htmlFor="exf-file">Receipt (photo or PDF, up to 5 MB)
          <input id="exf-file" type="file" className="mt-1 block w-full text-sm" accept={RECEIPT_TYPES.join(',')} onChange={(e) => setFile(e.target.files?.[0] || null)} />
          {(fileProblem || errors.attachment) && <span role="alert" className="mt-1 block text-xs font-medium text-red-700">{fileProblem || errors.attachment}</span>}
        </label>
      </div>
      <p className="text-xs text-stone-500">The amount and date cannot be changed later. If one is wrong, void the expense and record it again.</p>
      {errors.form && <p role="alert" className="text-sm font-medium text-red-700">{errors.form}</p>}
      <div className="flex justify-end gap-2">
        <button type="button" className="btn-ghost" onClick={onCancel} disabled={busy}>Cancel</button>
        <button type="submit" className="btn-primary" disabled={busy || !amount || v.description.trim().length < 2 || !!fileProblem}>{busy ? 'Saving...' : 'Record expense'}</button>
      </div>
    </form>
  )
}

/** Add, rename, hide or show categories. Hidden ones keep their expenses but are not offered for new ones. */
function Categories({ res, onClose, onChange }) {
  const [name, setName] = useState('')
  const { busy, errors, submit } = useSubmit(() => { res.reload(); onChange() })
  const rows = res.data?.data || []

  return (
    <section className="card mb-5 p-4" aria-label="Expense categories">
      <div className="mb-3 flex items-center justify-between gap-2">
        <h2 className="font-semibold text-stone-900">Categories</h2>
        <button type="button" className="btn-ghost px-2 py-1 text-sm" onClick={onClose}>Close</button>
      </div>
      {res.error ? <ErrorState error={res.error} onRetry={res.reload} /> : !res.data ? <Spinner /> : (
        <ul className="divide-y divide-stone-100 text-sm">
          {rows.map((c) => <CategoryRow key={c.id} row={c} onChange={() => { res.reload(); onChange() }} />)}
        </ul>
      )}
      <form noValidate className="mt-3 flex flex-wrap gap-2" onSubmit={async (e) => {
        e.preventDefault()
        const body = await submit(() => api.post('/seller/erp/expense-categories', { name: name.trim() }), 'Category added')
        if (body) setName('')
      }}>
        <label htmlFor="cat-new" className="sr-only">New category name</label>
        <input id="cat-new" className="input min-w-0 flex-1" maxLength={60} value={name} onChange={(e) => setName(e.target.value)} placeholder="New category, for example Fuel" />
        <button type="submit" className="btn-secondary" disabled={busy || name.trim().length < 2}>{busy ? 'Adding...' : 'Add'}</button>
      </form>
      <Err errors={errors} />
    </section>
  )
}

function CategoryRow({ row, onChange }) {
  const [editing, setEditing] = useState(false)
  const [name, setName] = useState(row.name)
  const { busy, errors, submit } = useSubmit(() => { setEditing(false); onChange() })
  const save = (data, message) => submit(() => api.put(`/seller/erp/expense-categories/${row.id}`, data), message)

  return (
    <li className="py-2">
      {editing ? (
        <form noValidate className="flex flex-wrap gap-2" onSubmit={(e) => { e.preventDefault(); save({ name: name.trim() }, 'Category renamed') }}>
          <label htmlFor={`cat-${row.id}`} className="sr-only">Category name</label>
          <input id={`cat-${row.id}`} className="input min-w-0 flex-1" maxLength={60} value={name} onChange={(e) => setName(e.target.value)} autoFocus />
          <button type="button" className="btn-ghost" onClick={() => { setName(row.name); setEditing(false) }} disabled={busy}>Cancel</button>
          <button type="submit" className="btn-primary" disabled={busy || name.trim().length < 2}>Save</button>
        </form>
      ) : (
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span className={row.is_active ? 'text-stone-900' : 'text-stone-400'}>{row.name}{!row.is_active && ' (hidden)'}<span className="ml-2 text-xs text-stone-500">{row.expenses} expense{row.expenses === 1 ? '' : 's'}</span></span>
          <span className="flex gap-1">
            <button type="button" className="btn-ghost px-2 py-1 text-xs" onClick={() => setEditing(true)}>Rename</button>
            <button type="button" className="btn-ghost px-2 py-1 text-xs" disabled={busy} onClick={() => save({ is_active: !row.is_active }, row.is_active ? 'Category hidden' : 'Category shown')}>{row.is_active ? 'Hide' : 'Show'}</button>
          </span>
        </div>
      )}
      <Err errors={errors} />
    </li>
  )
}
