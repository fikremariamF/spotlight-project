import { Link, useSearchParams } from 'react-router-dom'
import { Alert, ErrorState, PageHeader, Spinner, Stats } from '../../../components/ui'
import { useConfig } from '../../../context/ConfigContext'
import { toQuery } from '../../../lib/format'
import { useFetch } from '../../../lib/useFetch'
import { CsvButton, PeriodPicker, money, usePeriod } from './shared'

export const GROUPS = [['day', 'Days'], ['week', 'Weeks'], ['month', 'Months']]

/** "2026-10-17", "Week of 2026-10-12" or "Oct 2026" for a bucket of the finance summary. */
export function bucketLabel(b, group) {
  if (group === 'month') return new Date(`${b.key}-01T00:00:00`).toLocaleDateString('en-GB', { month: 'short', year: 'numeric' })
  if (group === 'week') return b.from === b.to ? b.from : `${b.from} to ${b.to}`
  return b.from
}

/** Day, week or month buckets, kept in the address bar with the period. */
export function useGroup() {
  const [params, setParams] = useSearchParams()
  const group = params.get('group') || ''
  const set = (g) => setParams(toQuery({ ...Object.fromEntries(params), group: g }), { replace: true })
  return [group, set]
}

export function GroupPicker({ value, onChange, current }) {
  return (
    <div role="group" aria-label="Show by" className="inline-flex rounded-xl border border-stone-300 bg-white p-0.5">
      {GROUPS.map(([key, label]) => (
        <button key={key} type="button" aria-pressed={(value || current) === key} onClick={() => onChange(key)}
          className={`rounded-lg px-3 py-1.5 text-sm ${(value || current) === key ? 'bg-brand-700 font-semibold text-white' : 'text-stone-700 hover:bg-stone-100'}`}>
          {label}
        </button>
      ))}
    </div>
  )
}

/** A link to another ERP page for the same dates, written out so that page's own picker shows them. */
const sameDates = (range) => `period=custom&from=${range.from}&to=${range.to}`

export default function ErpFinance() {
  const { name } = useConfig()
  const period = usePeriod('month')
  const [group, setGroup] = useGroup()
  const query = `${period.query}${group ? `&group=${group}` : ''}`
  const res = useFetch(`/seller/erp/finance?${query}`)
  const d = res.data?.data

  return (
    <>
      <PageHeader title="Finance & profit" subtitle="Worked out from your sales, purchases, expenses and marketplace account. An estimate to run the business, not tax accounts."
        actions={d && <CsvButton url={`/seller/erp/finance.csv?${query}`} filename={`finance-${d.range.from}-to-${d.range.to}.csv`} />} />
      <div className="mb-4 flex flex-wrap items-end gap-2">
        <PeriodPicker value={period} long />
        <GroupPicker value={group} onChange={setGroup} current={d?.group} />
      </div>

      {res.loading && !d ? <Spinner /> : res.error ? <ErrorState error={res.error} onRetry={res.reload} /> : (
        <Body d={d} brand={name} />
      )}
    </>
  )
}

function Body({ d, brand }) {
  const t = d.total
  const noCost = t.lines_without_cost > 0
  return (
    <div className="space-y-5">
      {noCost && (
        <Alert tone="warning">
          {money(t.net_items_without_cost_santim)} of item sales ({t.lines_without_cost} {t.lines_without_cost === 1 ? 'line' : 'lines'}) had no cost price, so their profit is left out
          while their commission and your expenses still count. Profit is lower than it really is. <Link to="/seller/erp/inventory" className="font-semibold underline">Add cost prices</Link>.
        </Alert>
      )}

      <Stats items={[
        { label: 'Net sales', value: money(t.net_sales_santim), hint: `${t.sales} ${t.sales === 1 ? 'sale' : 'sales'}, items and delivery, less returns` },
        { label: 'Gross profit (estimate)', value: money(t.gross_profit_santim), hint: 'item sales minus their cost', tone: t.gross_profit_santim < 0 ? 'bad' : undefined },
        { label: 'Expenses', value: money(t.expenses_santim), to: `/seller/erp/expenses?${sameDates(d.range)}` },
        { label: 'Estimated net profit', value: money(t.net_profit_santim), hint: noCost ? 'leaves out items without a cost' : 'after commission and expenses', tone: t.net_profit_santim < 0 ? 'bad' : noCost ? 'warn' : undefined },
      ]} />

      <div className="grid gap-5 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <Statement t={t} brand={brand} range={d.range} />
        <div className="space-y-5">
          <Owed owed={d.owed} />
          <Account m={d.marketplace} brand={brand} />
        </div>
      </div>

      <ByPeriod d={d} />
      <MoneyFlow t={t} brand={brand} />
      {d.expenses_by_category.length > 0 && <Categories cats={d.expenses_by_category} total={t.expenses_santim} range={d.range} />}
    </div>
  )
}

/**
 * One line of the statement: a label with how it is worked out, and the amount. A subtracted line
 * shows "- ETB 500", or "+ ETB 500" when it is a credit (below zero).
 */
function Line({ label, hint, value, sign, strong = false, to }) {
  const shown = sign !== '-' ? money(value) : value > 0 ? `- ${money(value)}` : value < 0 ? `+ ${money(-value)}` : money(0)
  return (
    <tr className={strong ? 'border-t border-stone-300 font-semibold text-stone-900' : 'text-stone-700'}>
      <th scope="row" className={`py-2 pr-3 text-left align-top ${strong ? 'font-semibold' : 'font-normal'}`}>
        {to ? <Link to={to} className="hover:underline">{label}</Link> : label}
        {hint && <span className="block text-xs font-normal text-stone-500">{hint}</span>}
      </th>
      <td className={`py-2 text-right align-top whitespace-nowrap tabular-nums ${sign !== '-' && value < 0 ? 'text-red-700' : ''}`}>{shown}</td>
    </tr>
  )
}

function Statement({ t, brand, range }) {
  return (
    <section className="card p-4" aria-labelledby="statement-h">
      <h2 id="statement-h" className="font-semibold text-stone-900">Profit for {range.from === range.to ? range.from : `${range.from} to ${range.to}`}</h2>
      <p className="mb-2 text-xs text-stone-500">Marketplace orders count on the day they were delivered, shop sales on their sale date, returns on the day the goods came back.</p>
      <table className="w-full text-sm">
        <tbody>
          <Line label="Gross sales" hint={`Items: ${money(t.marketplace_items_santim)} marketplace, ${money(t.offline_gross_santim)} shop, before discounts`} value={t.gross_sales_santim} to={`/seller/erp/sales?${sameDates(range)}`} />
          {t.discounts_santim > 0 && <Line label="Discounts" hint="on shop sales" value={t.discounts_santim} sign="-" />}
          {t.returns_goods_santim > 0 && <Line label="Returns" hint={`${t.returns} ${t.returns === 1 ? 'return' : 'returns'}, goods only`} value={t.returns_goods_santim} sign="-" />}
          <Line label="Net item sales" value={t.net_items_santim} strong />
          {t.net_items_without_cost_santim !== 0 && <Line label="Item sales without a cost price" hint="left out of profit" value={t.net_items_without_cost_santim} sign="-" />}
          <Line label="Cost of goods sold" hint="cost prices of the items sold, less units back in stock" value={t.cost_of_goods_santim} sign="-" />
          <Line label="Gross profit (estimate)" value={t.gross_profit_santim} strong />
          <Line label="Delivery charged" hint={t.returns_delivery_santim ? 'less delivery given back on returns' : 'to customers'} value={t.delivery_santim} />
          <Line label={`${brand} commission`} hint={`on items of ${t.marketplace_orders} delivered ${t.marketplace_orders === 1 ? 'order' : 'orders'}`} value={t.commission_santim} sign="-" />
          {t.adjustments_santim !== 0 && <Line label={`${brand} adjustments`} hint={t.adjustments_santim > 0 ? 'charged to your account' : 'credited to your account'} value={t.adjustments_santim} sign="-" />}
          <Line label="Operating expenses" hint="stock bought from suppliers is in the cost of goods, not here" value={t.expenses_santim} sign="-" to={`/seller/erp/expenses?${sameDates(range)}`} />
          <Line label="Estimated net operating profit" value={t.net_profit_santim} strong />
        </tbody>
      </table>
      <p className="mt-2 text-xs text-stone-500">Not a tax or audited statement. Sales are what customers were charged, not money received; see money in and out below.</p>
    </section>
  )
}

function Owed({ owed }) {
  const c = owed.customers
  const s = owed.suppliers
  return (
    <section className="card p-4" aria-labelledby="owed-h">
      <h2 id="owed-h" className="mb-2 font-semibold text-stone-900">Owed now</h2>
      <dl className="space-y-2 text-sm">
        <Row label="Customers owe you" value={c.owe_santim} to={c.owing_sales ? '/seller/erp/sales?status=owing' : undefined}
          hint={c.owing_sales ? `${c.owing_sales} shop ${c.owing_sales === 1 ? 'sale' : 'sales'}${c.overdue_sales ? `, ${c.overdue_sales} past the pay-by date` : ''}` : 'nothing on credit'} bad={c.overdue_sales > 0} />
        {c.credit_santim > 0 && <Row label="You hold for customers" value={c.credit_santim} hint="returns credited beyond what you gave back" />}
        <Row label="You owe suppliers" value={s.owed_santim} to={s.owed_santim ? '/seller/erp/suppliers?show=owing&sort=owed' : undefined}
          hint={s.received_not_invoiced_santim ? `includes ${money(s.received_not_invoiced_santim)} of goods received without an invoice yet` : undefined} />
        {s.paid_ahead_santim > 0 && <Row label="Paid ahead to suppliers" value={s.paid_ahead_santim} hint="deposits beyond what they billed" />}
      </dl>
    </section>
  )
}

function Row({ label, value, hint, to, bad = false }) {
  return (
    <div className="flex items-baseline justify-between gap-3">
      <dt className="min-w-0 text-stone-700">
        {to ? <Link to={to} className="hover:underline">{label}</Link> : label}
        {hint && <span className={`block text-xs ${bad ? 'text-red-700' : 'text-stone-500'}`}>{hint}</span>}
      </dt>
      <dd className="shrink-0 font-semibold whitespace-nowrap text-stone-900 tabular-nums">{money(value)}</dd>
    </div>
  )
}

function Account({ m, brand }) {
  const b = m.now.balance_santim
  const p = m.period
  return (
    <section className="card p-4" aria-labelledby="account-h">
      <h2 id="account-h" className="font-semibold text-stone-900">Your account with {brand}</h2>
      <p className={`mt-1 text-lg font-bold tabular-nums ${b > 0 ? (m.now.over_limit ? 'text-red-700' : 'text-stone-900') : 'text-emerald-800'}`}>
        {b > 0 ? `You owe ${brand} ${money(b)}` : b < 0 ? `${brand} owes you ${money(-b)}` : 'Settled'}
      </p>
      <p className="text-xs text-stone-500">
        {b > 0 ? 'Commission on cash orders and any charges, less what you paid.' : b < 0 ? 'Online sales, less commission, not paid out to you yet.' : 'Nothing owed either way right now.'}
        {m.now.over_limit && ' Over your credit limit: new cash orders wait until you pay.'}
      </p>
      {m.online_waiting_orders > 0 && (
        <p className="mt-2 rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-700">
          {money(m.online_waiting_santim)} paid online for {m.online_waiting_orders} {m.online_waiting_orders === 1 ? 'order' : 'orders'} not delivered yet. It is owed to you only once delivered.
        </p>
      )}
      <dl className="mt-3 space-y-1 border-t border-stone-100 pt-2 text-sm">
        <p className="text-xs font-medium text-stone-500">In this period</p>
        <Mini label="Commission charged" value={p.commission_charged_santim} />
        <Mini label="Online sales credited to you" value={p.online_sales_credited_santim} />
        <Mini label={`Paid out to you by ${brand}`} value={p.payouts_received_santim} />
        <Mini label={`You paid ${brand}`} value={p.payments_made_santim} />
        {p.adjustments_santim !== 0 && <Mini label="Adjustments" value={p.adjustments_santim} />}
      </dl>
      <p className="mt-2 text-xs text-stone-500">Money for online orders is yours to spend only after {brand} pays it out. <Link to="/seller/balance" className="underline">Balance and statements</Link></p>
    </section>
  )
}

function Mini({ label, value }) {
  return <div className="flex justify-between gap-3"><dt className="text-stone-600">{label}</dt><dd className="tabular-nums">{money(value)}</dd></div>
}

/** Net sales and net profit per bucket, as bars (profit can be below zero) and a table. */
function ByPeriod({ d }) {
  const rows = d.buckets
  const max = Math.max(1, ...rows.map((b) => Math.abs(b.net_profit_santim)), ...rows.map((b) => b.net_sales_santim))
  const unit = d.group === 'day' ? 'day' : d.group
  return (
    <section className="card p-4" aria-labelledby="by-h">
      <h2 id="by-h" className="mb-3 font-semibold text-stone-900">By {unit}</h2>
      {rows.length > 1 && (
        <div className="mb-4 flex h-40 items-stretch gap-px" aria-hidden="true">
          {rows.map((b) => {
            const sales = (50 * b.net_sales_santim) / max
            const profit = (50 * Math.abs(b.net_profit_santim)) / max
            return (
              <div key={b.key} className="group relative flex flex-1 flex-col" title={`${bucketLabel(b, d.group)}: sales ${money(b.net_sales_santim)}, net profit ${money(b.net_profit_santim)}`}>
                <div className="flex h-1/2 items-end gap-px">
                  <div className="flex-1 rounded-t bg-stone-300" style={{ height: `${Math.max(b.net_sales_santim ? 2 : 0, sales * 2)}%` }} />
                  <div className="flex-1 rounded-t bg-brand-600" style={{ height: b.net_profit_santim > 0 ? `${Math.max(2, profit * 2)}%` : 0 }} />
                </div>
                <div className="flex h-1/2 items-start gap-px border-t border-stone-300">
                  <div className="flex-1" />
                  <div className="flex-1 rounded-b bg-red-500" style={{ height: b.net_profit_santim < 0 ? `${Math.max(2, profit * 2)}%` : 0 }} />
                </div>
              </div>
            )
          })}
        </div>
      )}
      {rows.length > 1 && (
        <p className="-mt-2 mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-stone-500" aria-hidden="true">
          <span className="inline-flex items-center gap-1"><span className="size-2.5 rounded-sm bg-stone-300" />Net sales</span>
          <span className="inline-flex items-center gap-1"><span className="size-2.5 rounded-sm bg-brand-600" />Net profit</span>
          <span className="inline-flex items-center gap-1"><span className="size-2.5 rounded-sm bg-red-500" />Loss</span>
        </p>
      )}
      <div className="overflow-x-auto">
        <table className="rtable w-full text-sm">
          <thead className="text-left text-xs text-stone-500">
            <tr><th className="py-2 pr-3">{unit === 'day' ? 'Day' : unit === 'week' ? 'Week' : 'Month'}</th><th className="py-2 pr-3 text-right">Net sales</th><th className="py-2 pr-3 text-right">Gross profit</th><th className="py-2 pr-3 text-right">Commission</th><th className="py-2 pr-3 text-right">Expenses</th><th className="py-2 text-right">Net profit</th></tr>
          </thead>
          <tbody className="divide-y divide-stone-100">
            {rows.filter((b) => d.group !== 'day' || b.sales || b.expenses_santim || b.returns || b.adjustments_santim).map((b) => (
              <tr key={b.key}>
                <td className="rtable-title py-2 pr-3 whitespace-nowrap">{bucketLabel(b, d.group)}</td>
                <td data-label="Net sales" className="py-2 pr-3 text-right tabular-nums">{money(b.net_sales_santim)}</td>
                <td data-label="Gross profit" className="py-2 pr-3 text-right tabular-nums">{money(b.gross_profit_santim)}</td>
                <td data-label="Commission" className="py-2 pr-3 text-right tabular-nums">{money(b.commission_santim)}</td>
                <td data-label="Expenses" className="py-2 pr-3 text-right tabular-nums">{money(b.expenses_santim)}</td>
                <td data-label="Net profit" className={`py-2 text-right font-semibold tabular-nums ${b.net_profit_santim < 0 ? 'text-red-700' : ''}`}>{money(b.net_profit_santim)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {d.group === 'day' && rows.some((b) => !(b.sales || b.expenses_santim || b.returns || b.adjustments_santim)) && <p className="mt-2 text-xs text-stone-500">Days with nothing recorded are left out of the table.</p>}
    </section>
  )
}

function MoneyFlow({ t, brand }) {
  return (
    <section className="card p-4" aria-labelledby="flow-h">
      <h2 id="flow-h" className="font-semibold text-stone-900">Money in and out</h2>
      <p className="mb-3 text-xs text-stone-500">What was recorded as received or paid in this period. Not a bank or cash balance: money you did not record here is not in it.</p>
      <div className="grid gap-4 sm:grid-cols-2">
        <dl className="space-y-1 text-sm">
          <p className="text-xs font-medium text-stone-500">In</p>
          <Mini label="Cash collected on delivery" value={t.in_cash_on_delivery_santim} />
          <Mini label="Payments for shop sales" value={t.in_shop_payments_santim} />
          <Mini label={`Paid out to you by ${brand}`} value={t.in_payouts_santim} />
          <div className="flex justify-between gap-3 border-t border-stone-200 pt-1 font-semibold"><dt>Total in</dt><dd className="tabular-nums">{money(t.money_in_santim)}</dd></div>
        </dl>
        <dl className="space-y-1 text-sm">
          <p className="text-xs font-medium text-stone-500">Out</p>
          <Mini label="Given back on returns" value={t.out_refunds_santim} />
          <Mini label="Paid to suppliers" value={t.out_suppliers_santim} />
          <Mini label="Expenses" value={t.out_expenses_santim} />
          <Mini label={`Paid to ${brand}`} value={t.out_marketplace_santim} />
          <div className="flex justify-between gap-3 border-t border-stone-200 pt-1 font-semibold"><dt>Total out</dt><dd className="tabular-nums">{money(t.money_out_santim)}</dd></div>
        </dl>
      </div>
      <p className={`mt-3 text-sm font-semibold ${t.money_net_santim < 0 ? 'text-red-700' : 'text-stone-900'}`}>Difference: {money(t.money_net_santim)}</p>
    </section>
  )
}

function Categories({ cats, total, range }) {
  return (
    <section className="card p-4" aria-labelledby="cats-h">
      <h2 id="cats-h" className="mb-3 font-semibold text-stone-900">Expenses by category</h2>
      <ul className="space-y-2 text-sm">
        {cats.map((c) => (
          <li key={c.id}>
            <Link to={`/seller/erp/expenses?${sameDates(range)}&category_id=${c.id}`} className="block hover:underline">
              <span className="flex justify-between gap-3"><span className="min-w-0 truncate font-medium text-stone-800">{c.name}</span><span className="shrink-0 tabular-nums">{money(c.total_santim)} ({c.count})</span></span>
              <span className="mt-1 block h-2 rounded-full bg-stone-100" aria-hidden="true">
                <span className="block h-2 rounded-full bg-brand-600" style={{ width: `${total ? Math.max(2, Math.round((c.total_santim / total) * 100)) : 0}%` }} />
              </span>
            </Link>
          </li>
        ))}
      </ul>
    </section>
  )
}
