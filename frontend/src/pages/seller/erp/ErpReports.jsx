import { ArrowDown, ArrowUp, ChevronsUpDown, Printer } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { ErrorState, PageHeader, Pagination, Spinner } from '../../../components/ui'
import { useConfig } from '../../../context/ConfigContext'
import { api, parseError } from '../../../lib/api'
import { toQuery } from '../../../lib/format'
import { useFetch } from '../../../lib/useFetch'
import { GroupPicker, useGroup } from './ErpFinance'
import { CsvButton, MOVEMENT_LABELS, PO_STATUS, PeriodPicker, money, usePeriod } from './shared'

/** Words for the codes in enum columns; `brand` is the marketplace's name. */
const labels = (brand) => ({
  listing_status: { draft: 'Draft', pending_review: 'Waiting for review', published: 'Published', rejected: 'Rejected', archived: 'Hidden' },
  stock_state: { low: 'Low stock', out: 'Out of stock' },
  movement: MOVEMENT_LABELS,
  po_status: Object.fromEntries(Object.entries(PO_STATUS).map(([k, [label]]) => [k, label])),
  return_kind: { shop_return: 'Shop sale return', marketplace_return: 'Marketplace return', cancelled_order: 'Cancelled order' },
  refund_by: { store: 'You', marketplace: brand },
  return_status: {
    recorded: 'Recorded', refund_due: `Refund due from ${brand}`, refund_refunded: `Refunded by ${brand}`,
    cancelled_by_customer: 'Cancelled by the customer', cancelled_by_seller: 'Cancelled by you', cancelled_by_admin: `Cancelled by ${brand}`, cancelled_by_system: 'Cancelled automatically',
  },
})

/** Where to look further for a report, for the same dates where it has them. */
const MORE = {
  sales: ['Sales & invoices', (r) => `/seller/erp/sales?${r}`],
  profit: ['Finance & profit', (r) => `/seller/erp/finance?${r}`],
  valuation: ['Inventory', () => '/seller/erp/inventory'],
  low_stock: ['Inventory', () => '/seller/erp/inventory?stock=low'],
  movements: ['Stock history', (_, range) => `/seller/erp/movements?from=${range.from}&to=${range.to}`],
  purchases: ['Purchases', () => '/seller/erp/purchases'],
  suppliers: ['Suppliers', () => '/seller/erp/suppliers'],
  expenses: ['Expenses', (r) => `/seller/erp/expenses?${r}`],
}

function cell(col, v, words) {
  if (v === null || v === undefined || v === '') return col.type === 'money' ? (col.key.includes('profit') || col.key.startsWith('cost') ? 'No cost price' : '') : ''
  switch (col.type) {
    case 'money': return money(v)
    case 'int': return Number(v).toLocaleString('en-US')
    case 'percent': return `${v}%`
    case 'enum': return words[col.labels]?.[v] ?? v
    default: return v
  }
}

const numeric = (col) => ['money', 'int', 'percent'].includes(col.type)

export default function ErpReports() {
  const { name } = useConfig()
  const words = labels(name)
  const [params, setParams] = useSearchParams()
  const key = params.get('r') || 'sales'
  const period = usePeriod('30d')
  const [group] = useGroup()
  const sort = params.get('sort') || ''
  const dir = params.get('dir') || ''
  const page = params.get('page') || ''
  const list = useFetch('/seller/erp/reports')
  const reports = list.data?.data || []
  const set = (next) => setParams(toQuery({ ...Object.fromEntries(params), page: '', ...next }), { replace: true })
  const query = toQuery({ ...Object.fromEntries(new URLSearchParams(period.query)), group, sort, dir })
  const res = useFetch(`/seller/erp/reports/${key}?${query}${page ? `&page=${page}` : ''}`)
  const data = res.data
  const def = data?.report ?? reports.find((r) => r.key === key)

  const [printing, setPrinting] = useState(null)
  const [printError, setPrintError] = useState(null)
  async function print() {
    setPrintError(null)
    try {
      const r = await api.get(`/seller/erp/reports/${key}?${query}&all=1`)
      setPrinting({ ...r.data, printedAt: new Date() })
    } catch (err) {
      setPrintError(parseError(err).message)
    }
  }
  // Print once the full table is on the page, then go back to the paged view.
  useEffect(() => {
    if (!printing) return
    const done = () => setPrinting(null)
    window.addEventListener('afterprint', done, { once: true })
    const t = setTimeout(() => window.print(), 50)
    return () => {
      clearTimeout(t)
      window.removeEventListener('afterprint', done)
    }
  }, [printing])

  const shown = printing ?? data
  const rangeQuery = data?.range ? `period=custom&from=${data.range.from}&to=${data.range.to}` : ''
  const more = MORE[key]
  const filename = `${key.replace('_', '-')}${data?.range ? `-${data.range.from}-to-${data.range.to}` : ''}.csv`

  return (
    <>
      <div className="print:hidden">
        <PageHeader title="Reports" subtitle="Tables worked out from your records, to sort, print or download. Amounts are in birr." />
        <label className="mb-3 block text-sm sm:hidden">Report
          <select className="input mt-1" value={key} onChange={(e) => set({ r: e.target.value, sort: '', dir: '' })}>
            {reports.map((r) => <option key={r.key} value={r.key}>{r.title}</option>)}
          </select>
        </label>
        <div role="group" aria-label="Report" className="mb-4 hidden flex-wrap gap-1.5 sm:flex">
          {reports.map((r) => (
            <button key={r.key} type="button" aria-pressed={r.key === key} onClick={() => set({ r: r.key, sort: '', dir: '' })}
              className={`rounded-full border px-3 py-1 text-sm ${r.key === key ? 'border-brand-700 bg-brand-700 font-semibold text-white' : 'border-stone-300 bg-white text-stone-700 hover:bg-stone-100'}`}>
              {r.title}
            </button>
          ))}
        </div>
      </div>

      {def && (
        <section className="card p-4 print:border-0 print:p-0 print:shadow-none" aria-labelledby="report-h">
          <div className="mb-3 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div className="min-w-0">
              <h2 id="report-h" className="text-lg font-semibold text-stone-900">{def.title}</h2>
              {data?.range && <p className="text-sm text-stone-600">{data.range.from === data.range.to ? data.range.from : `${data.range.from} to ${data.range.to}`}</p>}
              {def.period === false && <p className="text-sm text-stone-600">As of now</p>}
              <p className="mt-1 max-w-3xl text-xs text-stone-500">{def.about}</p>
              {printing && <p className="hidden text-xs text-stone-500 print:block">{name}. Printed {printing.printedAt.toLocaleString('en-GB', { timeZone: 'Africa/Addis_Ababa', dateStyle: 'medium', timeStyle: 'short' })}.</p>}
            </div>
            <div className="flex shrink-0 flex-wrap gap-2 print:hidden">
              <CsvButton url={`/seller/erp/reports/${key}.csv?${query}`} filename={filename} />
              <span className="inline-flex flex-col">
                <button type="button" className="btn-secondary" onClick={print} disabled={!data || !!printing}><Printer className="size-4" aria-hidden="true" />{printing ? 'Preparing...' : 'Print'}</button>
                {printError && <span role="alert" className="mt-1 text-xs text-red-700">{printError}</span>}
              </span>
            </div>
          </div>
          {(def.period || def.group) && (
            <div className="mb-3 flex flex-wrap items-end gap-2 print:hidden">
              {def.period && <PeriodPicker value={period} />}
              {def.group && <GroupPicker value={group} onChange={(g) => set({ group: g })} current={data?.report?.group} />}
            </div>
          )}

          {res.loading && !data ? <Spinner /> : res.error ? <ErrorState error={res.error} onRetry={res.reload} /> : shown && (
            <>
              <Table data={shown} words={words} onSort={(k) => set({ sort: k, dir: shown.sort.key === k && shown.sort.dir === 'asc' ? 'desc' : 'asc' })} />
              {printing && printing.meta.total > printing.data.length && (
                <p className="mt-2 text-xs text-stone-500">Showing the first {printing.data.length.toLocaleString('en-US')} of {printing.meta.total.toLocaleString('en-US')} rows. The CSV has them all.</p>
              )}
              {more && <p className="mt-3 text-sm print:hidden"><Link className="text-brand-800 underline" to={more[1](rangeQuery, data.range || {})}>Open {more[0]}</Link></p>}
            </>
          )}
        </section>
      )}
      {!printing && <div className="print:hidden"><Pagination meta={data?.meta} onPage={(p) => setParams(toQuery({ ...Object.fromEntries(params), page: p }), { replace: true })} /></div>}
    </>
  )
}

function Table({ data, words, onSort }) {
  const { columns } = data.report
  const rows = data.data
  const titleKey = columns.find((c) => c.type === 'text' || c.type === 'date')?.key
  const hasTotals = Object.keys(data.totals).length > 0 && data.meta.total > 1
  if (rows.length === 0) return <p className="py-8 text-center text-sm text-stone-500">{data.report.period ? 'Nothing in this period.' : 'Nothing to show.'}</p>
  return (
    <div className="-mx-4 overflow-x-auto sm:mx-0 print:mx-0 print:overflow-visible">
      <table className="rtable w-full text-sm print:text-xs">
        <thead className="border-b border-stone-200 text-left text-xs text-stone-500">
          <tr>
            {columns.map((c) => {
              const on = data.sort.key === c.key
              const Icon = on ? (data.sort.dir === 'asc' ? ArrowUp : ArrowDown) : ChevronsUpDown
              return (
                <th key={c.key} scope="col" aria-sort={on ? (data.sort.dir === 'asc' ? 'ascending' : 'descending') : undefined}
                  className={`px-2 py-2 align-bottom font-medium first:pl-4 last:pr-4 sm:first:pl-2 sm:last:pr-2 ${numeric(c) ? 'text-right' : ''}`}>
                  <button type="button" onClick={() => onSort(c.key)} className={`inline-flex items-center gap-1 hover:text-stone-900 print:pointer-events-none ${numeric(c) ? 'flex-row-reverse' : ''} ${on ? 'text-stone-900' : ''}`}>
                    <span>{c.label}</span><Icon className="size-3 shrink-0 print:hidden" aria-hidden="true" />
                  </button>
                </th>
              )
            })}
          </tr>
        </thead>
        <tbody className="divide-y divide-stone-100">
          {rows.map((r, i) => (
            <tr key={i}>
              {columns.map((c) => {
                const v = r[c.key]
                const text = cell(c, v, words)
                return (
                  <td key={c.key} data-label={c.key === titleKey ? undefined : c.label}
                    className={`px-2 py-2 align-top first:pl-4 last:pr-4 sm:first:pl-2 sm:last:pr-2 ${c.key === titleKey ? 'rtable-title' : ''} ${text === '' ? 'max-sm:hidden' : ''} ${numeric(c) ? 'text-right whitespace-nowrap tabular-nums' : 'min-w-0 break-words'} ${numeric(c) && v < 0 ? 'text-red-700' : ''} ${v === null && c.type === 'money' ? 'text-xs text-amber-800' : ''}`}>
                    {text}
                  </td>
                )
              })}
            </tr>
          ))}
        </tbody>
        {hasTotals && (
          <tfoot className="border-t-2 border-stone-300 font-semibold text-stone-900">
            <tr>
              {columns.map((c, i) => (
                <td key={c.key} data-label={i === 0 || !(c.key in data.totals) ? undefined : c.label}
                  className={`px-2 py-2 first:pl-4 last:pr-4 sm:first:pl-2 sm:last:pr-2 ${i === 0 ? 'rtable-title' : ''} ${numeric(c) ? 'text-right whitespace-nowrap tabular-nums' : ''} ${i !== 0 && !(c.key in data.totals) ? 'max-sm:hidden' : ''}`}>
                  {i === 0 ? `Total (${data.meta.total.toLocaleString('en-US')} rows)` : c.key in data.totals ? cell(c, data.totals[c.key], words) : ''}
                </td>
              ))}
            </tr>
          </tfoot>
        )}
      </table>
    </div>
  )
}
