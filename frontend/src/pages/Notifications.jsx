import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { Alert, EmptyState, ErrorState, Field, PageHeader, Pagination, Spinner } from '../components/ui'
import { useAlerts } from '../context/AlertsContext'
import { useAuth } from '../context/AuthContext'
import { api, parseError } from '../lib/api'
import { formatDateTime, timeAgo, toQuery } from '../lib/format'
import { useFetch } from '../lib/useFetch'

const FILTERS = [['all', 'All'], ['unread', 'Unread']]

export function Notifications() {
  const [params, setParams] = useSearchParams()
  const filter = params.get('filter') === 'unread' ? 'unread' : 'all'
  const page = params.get('page') || ''
  const list = useFetch(`/notifications?${toQuery({ filter, page })}`)
  const { refreshAlerts } = useAlerts()
  const navigate = useNavigate()
  const [error, setError] = useState(null)
  const unread = list.data?.meta.unread ?? 0

  // Keep the header bell in step with what this page shows.
  useEffect(() => {
    if (list.data) refreshAlerts()
  }, [list.data, refreshAlerts])

  async function open(item) {
    if (!item.read_at) {
      try {
        await api.post(`/notifications/${item.id}/read`)
        refreshAlerts()
      } catch {
        // Opening the link matters more than the read mark.
      }
    }
    if (item.url) navigate(item.url)
    else list.reload()
  }

  async function markAll() {
    setError(null)
    try {
      await api.post('/notifications/read-all')
      list.reload()
    } catch (err) {
      setError(parseError(err).message)
    }
  }

  return (
    <div className="container-page max-w-3xl py-8">
      <PageHeader
        title="Alerts"
        subtitle="Order updates, quotes and store news. Messages have their own inbox."
        actions={(
          <>
            <button type="button" className="btn-secondary" onClick={markAll} disabled={unread === 0}>Mark all as read</button>
            <Link to="/account/alerts" className="btn-secondary">Alert settings</Link>
          </>
        )}
      />
      {error && <div className="mb-4"><Alert tone="error">{error}</Alert></div>}

      <div role="tablist" aria-label="Show" className="mb-4 flex gap-1">
        {FILTERS.map(([value, label]) => (
          <button key={value} type="button" role="tab" aria-selected={filter === value}
            className={`rounded-full px-3 py-1.5 text-sm font-medium ${filter === value ? 'bg-brand-700 text-white' : 'bg-stone-100 text-stone-700 hover:bg-stone-200'}`}
            onClick={() => setParams(toQuery({ filter: value === 'all' ? '' : value }))}>
            {label}{value === 'unread' && unread > 0 ? ` (${unread})` : ''}
          </button>
        ))}
      </div>

      {list.loading && !list.data ? <Spinner /> : list.error ? <ErrorState error={list.error} onRetry={list.reload} /> : list.data.data.length === 0 ? (
        <EmptyState title={filter === 'unread' ? 'You are all caught up' : 'No alerts yet'}>
          {filter === 'unread' ? 'There are no unread alerts.' : 'When something happens with your orders, quotes or store, it shows up here.'}
        </EmptyState>
      ) : (
        <>
          <ul className="card divide-y divide-stone-100">
            {list.data.data.map((item) => (
              <li key={item.id}>
                <button type="button" onClick={() => open(item)}
                  className={`flex w-full min-w-0 items-start gap-3 px-4 py-3 text-left hover:bg-stone-50 ${item.read_at ? '' : 'bg-brand-50/60'}`}>
                  <span aria-hidden="true" className={`mt-1.5 size-2.5 shrink-0 rounded-full ${item.read_at ? 'bg-transparent' : 'bg-brand-600'}`} />
                  <span className="min-w-0 flex-1">
                    <span className={`block break-words text-sm ${item.read_at ? 'text-stone-800' : 'font-semibold text-stone-900'}`}>
                      {item.title}
                      {!item.read_at && <span className="sr-only"> (unread)</span>}
                    </span>
                    {item.body && <span className="mt-0.5 block break-words text-sm text-stone-600">{item.body}</span>}
                  </span>
                  <time dateTime={item.created_at} title={formatDateTime(item.created_at)} className="shrink-0 text-xs text-stone-500">{timeAgo(item.created_at)}</time>
                </button>
              </li>
            ))}
          </ul>
          <Pagination meta={list.data.meta} onPage={(p) => setParams(toQuery({ filter: filter === 'all' ? '' : filter, page: p }))} />
        </>
      )}
    </div>
  )
}

export function AlertSettings() {
  const settings = useFetch('/account/alerts')

  if (settings.loading && !settings.data) return <div className="container-page py-8"><Spinner /></div>
  if (settings.error) return <div className="container-page py-8"><ErrorState error={settings.error} onRetry={settings.reload} /></div>

  return <AlertSettingsForm settings={settings} />
}

function toForm(d) {
  return { phone: d.phone || '', alert_sms: d.alert_sms, alert_email: d.alert_email }
}

function AlertSettingsForm({ settings }) {
  const { user } = useAuth()
  const [form, setForm] = useState(() => toForm(settings.data.data))
  const [errors, setErrors] = useState({})
  const [notice, setNotice] = useState(null)
  const [saving, setSaving] = useState(false)
  const info = settings.data.data
  const isSeller = user?.role === 'seller'

  async function save(e) {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    setNotice(null)
    try {
      const res = await api.put('/account/alerts', form)
      settings.setData(res.data)
      setForm(toForm(res.data.data))
      setNotice({ tone: 'success', text: 'Alert settings saved.' })
    } catch (err) {
      const p = parseError(err)
      setErrors(p.fields)
      setNotice({ tone: 'error', text: Object.values(p.fields)[0] || p.message })
    } finally {
      setSaving(false)
    }
  }

  const smsWhere = info.sms_to
    ? info.sms_to_source === 'store'
      ? <>Text messages go to your store phone, <strong>{info.sms_to}</strong>. Change it in <Link to="/seller/profile" className="underline">Store profile</Link>.</>
      : <>Text messages go to <strong>{info.sms_to}</strong>.</>
    : isSeller
      ? 'Your store phone is not an Ethiopian mobile number, so we cannot text you. Add a mobile number below or in your store profile.'
      : 'Add an Ethiopian mobile number (starting 09 or 07) to get text messages. Until then, order texts go to the phone on your delivery address.'

  return (
    <div className="container-page max-w-2xl py-8">
      <PageHeader title="Alert settings" subtitle="You always see alerts in the app. Choose whether they also come by text message and email." />
      {notice && <div className="mb-4"><Alert tone={notice.tone}>{notice.text}</Alert></div>}
      {info.sms_paused && <div className="mb-4"><Alert tone="warning">The marketplace has paused text messages for now. You still get alerts in the app and by email.</Alert></div>}

      <form onSubmit={save} className="card space-y-5 p-4" noValidate>
        <Field label="Mobile number" name="alert-phone" error={errors.phone} hint={isSeller ? 'Used only if your store phone is not a mobile number.' : 'For example 0911 234 567.'}>
          <input id="alert-phone" className="input" inputMode="tel" autoComplete="tel" maxLength={16} value={form.phone}
            onChange={(e) => setForm({ ...form, phone: e.target.value })} aria-invalid={Boolean(errors.phone)} aria-describedby={errors.phone ? 'alert-phone-error' : undefined} />
        </Field>

        <div className="flex items-start gap-2 text-sm">
          <input id="alert-sms" type="checkbox" className="mt-1" checked={form.alert_sms} aria-describedby="alert-sms-help"
            onChange={(e) => setForm({ ...form, alert_sms: e.target.checked })} />
          <div>
            <label htmlFor="alert-sms" className="font-medium">Text messages (SMS)</label>
            <p id="alert-sms-help">
              <span className="block text-stone-600">{isSeller ? 'New orders, cancellations, new inquiries and store approval.' : 'Order confirmed, on the way, ready for pickup and cancelled.'}</span>
              <span className="block text-stone-500">{smsWhere}</span>
            </p>
          </div>
        </div>

        <div className="flex items-start gap-2 text-sm">
          <input id="alert-email" type="checkbox" className="mt-1" checked={form.alert_email} aria-describedby="alert-email-help"
            onChange={(e) => setForm({ ...form, alert_email: e.target.checked })} />
          <div>
            <label htmlFor="alert-email" className="font-medium">Email</label>
            <p id="alert-email-help" className="text-stone-600">
              Order updates, new messages and quotes, sent to <strong className="break-all">{info.email}</strong>. At most one email per conversation every 30 minutes.
            </p>
          </div>
        </div>

        <button type="submit" className="btn-primary" disabled={saving}>{saving ? 'Saving...' : 'Save settings'}</button>
      </form>
    </div>
  )
}
