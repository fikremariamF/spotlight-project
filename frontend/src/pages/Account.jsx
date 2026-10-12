import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Alert, ErrorState, Field, PageHeader, Spinner } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { api, parseError } from '../lib/api'
import { useFetch } from '../lib/useFetch'

const ROLE_LABEL = { customer: 'Customer account', seller: 'Seller account', admin: 'Administrator' }

const sameEmail = (a, b) => (a || '').trim().toLowerCase() === (b || '').trim().toLowerCase()

function useSubmit() {
  const [errors, setErrors] = useState({})
  const [notice, setNotice] = useState(null)
  const [busy, setBusy] = useState(false)

  async function run(action) {
    setBusy(true)
    setErrors({})
    setNotice(null)
    try {
      await action()
    } catch (err) {
      const p = parseError(err)
      setErrors(p.fields)
      setNotice({ tone: 'error', text: Object.values(p.fields)[0] || p.message })
    } finally {
      setBusy(false)
    }
  }

  return { errors, notice, setNotice, busy, run }
}

function DetailsForm({ profile, onSaved }) {
  const { refresh } = useAuth()
  const [form, setForm] = useState({ name: profile.name, email: profile.email, phone: profile.phone || '', current_password: '' })
  const { errors, notice, setNotice, busy, run } = useSubmit()
  const emailChanging = !sameEmail(form.email, profile.email)
  const set = (key) => (e) => setForm({ ...form, [key]: e.target.value })
  const invalid = (key) => ({ 'aria-invalid': Boolean(errors[key]), 'aria-describedby': errors[key] ? `${key}-error` : undefined })

  function save(e) {
    e.preventDefault()
    run(async () => {
      const payload = { name: form.name, email: form.email, phone: form.phone }
      if (emailChanging) payload.current_password = form.current_password
      const res = await api.put('/account/profile', payload)
      const saved = res.data.data
      onSaved(saved)
      setForm({ name: saved.name, email: saved.email, phone: saved.phone || '', current_password: '' })
      setNotice({ tone: 'success', text: emailChanging ? `Saved. We sent a notice to your old address, ${profile.email}.` : 'Your details are saved.' })
      refresh()
    })
  }

  return (
    <section aria-labelledby="details-title" className="card p-4">
      <h2 id="details-title" className="text-lg font-semibold text-stone-900">Your details</h2>
      <form onSubmit={save} className="mt-4 space-y-4" noValidate>
        {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
        <Field label="Full name" name="name" error={errors.name}>
          <input id="name" className="input" autoComplete="name" maxLength={100} value={form.name} onChange={set('name')} {...invalid('name')} />
        </Field>
        <Field label="Email" name="email" error={errors.email} hint="You sign in with this email, and order emails go here.">
          <input id="email" type="email" className="input" autoComplete="email" maxLength={190} value={form.email} onChange={set('email')} {...invalid('email')} />
        </Field>
        {emailChanging && (
          <Field label="Current password" name="current_password" error={errors.current_password} hint="Needed to change your email.">
            <input id="current_password" type="password" className="input" autoComplete="current-password" value={form.current_password} onChange={set('current_password')} {...invalid('current_password')} />
          </Field>
        )}
        <Field label="Phone (optional)" name="phone" error={errors.phone} hint="For example 0911 234 567. Order texts go to this number if it is a mobile.">
          <input id="phone" className="input" inputMode="tel" autoComplete="tel" maxLength={16} value={form.phone} onChange={set('phone')} {...invalid('phone')} />
        </Field>
        <button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Saving...' : 'Save details'}</button>
      </form>
    </section>
  )
}

function PasswordForm({ role, onSaved }) {
  const empty = { current_password: '', password: '', password_confirmation: '' }
  const [form, setForm] = useState(empty)
  const { errors, notice, setNotice, busy, run } = useSubmit()
  const set = (key) => (e) => setForm({ ...form, [key]: e.target.value })
  const invalid = (key) => ({ 'aria-invalid': Boolean(errors[key]), 'aria-describedby': errors[key] ? `pw-${key}-error` : undefined })
  const rule = role === 'admin'
    ? 'At least 12 characters, with letters, numbers and a symbol.'
    : 'At least 8 characters, with letters and numbers.'

  function save(e) {
    e.preventDefault()
    run(async () => {
      const res = await api.put('/account/password', form)
      onSaved(res.data.data)
      setForm(empty)
      const n = res.data.data.signed_out
      setNotice({ tone: 'success', text: n > 0 ? `Password changed. ${n === 1 ? 'Your other device was' : `Your ${n} other devices were`} signed out.` : 'Password changed.' })
    })
  }

  return (
    <section aria-labelledby="password-title" className="card p-4">
      <h2 id="password-title" className="text-lg font-semibold text-stone-900">Change password</h2>
      <p className="mt-1 text-sm text-stone-600">Changing it signs you out on every other device.</p>
      <form onSubmit={save} className="mt-4 space-y-4" noValidate>
        {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
        <Field label="Current password" name="pw-current_password" error={errors.current_password}>
          <input id="pw-current_password" type="password" className="input" autoComplete="current-password" value={form.current_password} onChange={set('current_password')} {...invalid('current_password')} />
        </Field>
        <Field label="New password" name="pw-password" error={errors.password} hint={rule}>
          <input id="pw-password" type="password" className="input" autoComplete="new-password" maxLength={200} value={form.password} onChange={set('password')} {...invalid('password')} />
        </Field>
        <Field label="Repeat new password" name="pw-password_confirmation" error={errors.password_confirmation}>
          <input id="pw-password_confirmation" type="password" className="input" autoComplete="new-password" maxLength={200} value={form.password_confirmation} onChange={set('password_confirmation')} {...invalid('password_confirmation')} />
        </Field>
        <button type="submit" className="btn-primary" disabled={busy}>{busy ? 'Saving...' : 'Change password'}</button>
      </form>
    </section>
  )
}

function DevicesCard({ profile, onSaved }) {
  const { notice, setNotice, busy, run } = useSubmit()
  const others = profile.other_sessions

  function signOutOthers() {
    run(async () => {
      const res = await api.post('/account/sign-out-others')
      onSaved(res.data.data)
      setNotice({ tone: 'success', text: 'Signed out of your other devices.' })
    })
  }

  return (
    <section aria-labelledby="devices-title" className="card p-4">
      <h2 id="devices-title" className="text-lg font-semibold text-stone-900">Signed-in devices</h2>
      <div className="mt-2 space-y-3">
        {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
        <p className="text-sm text-stone-600">
          {others > 0
            ? `You are also signed in on ${others} other ${others === 1 ? 'device or browser' : 'devices or browsers'}.`
            : 'You are not signed in anywhere else.'}
        </p>
        <button type="button" className="btn-secondary" disabled={busy || others === 0} onClick={signOutOthers}>
          Sign out other devices
        </button>
      </div>
    </section>
  )
}

export default function Account() {
  const res = useFetch('/account/profile')

  if (res.loading && !res.data) return <Spinner />
  if (res.error) return <div className="container-page py-8"><ErrorState error={res.error} onRetry={res.reload} /></div>

  const profile = res.data.data
  const onSaved = (data) => res.setData({ data })
  const since = profile.created_at ? new Date(profile.created_at).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }) : null

  return (
    <div className="container-page max-w-2xl py-8">
      <PageHeader title="My account" subtitle={[ROLE_LABEL[profile.role], since && `member since ${since}`].filter(Boolean).join(', ')} />
      <div className="space-y-6">
        <DetailsForm profile={profile} onSaved={onSaved} />
        <PasswordForm role={profile.role} onSaved={onSaved} />
        <DevicesCard profile={profile} onSaved={onSaved} />
        <nav aria-label="More settings" className="card divide-y divide-stone-100">
          <Link to="/account/alerts" className="block p-4 text-sm hover:bg-stone-50">
            <span className="font-medium text-stone-900">Alert settings</span>
            <span className="block text-stone-600">Text messages and email for orders and messages.</span>
          </Link>
          {profile.role === 'customer' && (
            <Link to="/account/addresses" className="block p-4 text-sm hover:bg-stone-50">
              <span className="font-medium text-stone-900">Delivery addresses</span>
              <span className="block text-stone-600">Saved addresses for checkout.</span>
            </Link>
          )}
          {profile.role === 'seller' && (
            <Link to="/seller/profile" className="block p-4 text-sm hover:bg-stone-50">
              <span className="font-medium text-stone-900">Store profile</span>
              <span className="block text-stone-600">Store name, phone, location and description shown to customers.</span>
            </Link>
          )}
        </nav>
      </div>
    </div>
  )
}
