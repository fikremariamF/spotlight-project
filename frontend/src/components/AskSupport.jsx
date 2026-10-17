import { useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { useInbox } from '../context/InboxContext'
import { api, parseError } from '../lib/api'
import { SUPPORT_TOPICS } from '../lib/format'
import { Alert, Field } from './ui'

const PLACEHOLDERS = {
  product: 'Ask about size, material, finish or anything else.',
  availability: 'When could I get this? Is it available in another color?',
  price: 'What price are you hoping for? Tell us how many you need.',
  custom: 'Describe what you need: size in cm, material, color, quantity and when you need it.',
  delivery: 'Where should it go, and by when? Mention the floor and whether there is a lift.',
  order: 'What would you like to know about this order?',
  return: 'What is wrong with the item, and when was it delivered?',
  refund: 'Tell us what happened and how you paid.',
  other: 'How can we help?',
}

/** Topics that fit where the customer asks from. */
function topicsFor({ product, order, seller }) {
  if (product) {
    return ['product', 'availability', 'price', ...(product.custom_order_available ? ['custom'] : []), 'delivery', 'other']
  }
  if (order) return ['order', 'delivery', 'return', 'refund', 'other']
  if (seller) return ['product', ...(seller.offers_custom_orders ? ['custom'] : []), 'delivery', 'other']
  return Object.keys(SUPPORT_TOPICS)
}

/**
 * Ask Betoch Support. The listing, order or store the customer asks from is attached by reference,
 * so the support team sees what it is about. Customers never write to a store directly.
 */
export default function AskSupport({ product, order, seller, topic: initialTopic, onCancel, title }) {
  const { user } = useAuth()
  const { refreshUnread } = useInbox()
  const navigate = useNavigate()
  const location = useLocation()
  const topics = topicsFor({ product, order, seller })

  const [topic, setTopic] = useState(topics.includes(initialTopic) ? initialTopic : topics[0])
  const [body, setBody] = useState('')
  const [subject, setSubject] = useState('')
  const [errors, setErrors] = useState({})
  const [error, setError] = useState(null)
  const [sending, setSending] = useState(false)

  if (!user) {
    return (
      <Alert>
        <Link to="/login" state={{ from: location.pathname + location.search }} className="font-semibold underline">Sign in</Link> or{' '}
        <Link to="/register" className="font-semibold underline">create a free account</Link> to ask Betoch Support.
      </Alert>
    )
  }
  if (user.role !== 'customer') {
    return <Alert>Questions to Betoch Support need a customer account. Store and admin accounts cannot open inquiries.</Alert>
  }

  async function submit(e) {
    e.preventDefault()
    setErrors({})
    setError(null)
    if (body.trim().length < 2) {
      setErrors({ body: 'Write your question first.' })
      return
    }
    setSending(true)
    try {
      const res = await api.post('/support/tickets', {
        topic,
        subject: subject.trim() || null,
        body,
        product_slug: product?.slug ?? null,
        order_id: !product && order ? order.id : null,
        seller_slug: !product && !order && seller ? seller.slug : null,
      })
      refreshUnread()
      navigate(`/support/${res.data.data.id}`)
    } catch (err) {
      const parsed = parseError(err)
      setErrors(parsed.fields)
      setError(Object.values(parsed.fields)[0] || parsed.message)
      setSending(false)
    }
  }

  const about = product ? product.name : order ? `Order ${order.number}` : seller ? seller.store_name : null

  return (
    <form onSubmit={submit} className="card space-y-3 p-4" noValidate>
      <div>
        <p className="font-semibold text-stone-900">{title || 'Ask Betoch Support'}</p>
        <p className="text-xs text-stone-500">
          {about ? <>About <span className="font-medium text-stone-700">{about}</span>. </> : null}
          Our team checks with the store for you and answers here.
        </p>
      </div>
      {error && <Alert tone="error">{error}</Alert>}
      {topics.length > 1 && (
        <fieldset>
          <legend className="label">What is it about?</legend>
          <div className="flex flex-wrap gap-2">
            {topics.map((t) => (
              <label key={t} className={`cursor-pointer rounded-full border px-3 py-1.5 text-sm has-focus-visible:ring-2 has-focus-visible:ring-brand-500 ${topic === t ? 'border-brand-700 bg-brand-50 font-semibold text-brand-800' : 'border-stone-300 text-stone-700'}`}>
                <input type="radio" name="support-topic" value={t} checked={topic === t} onChange={() => setTopic(t)} className="sr-only" />
                {SUPPORT_TOPICS[t]}
              </label>
            ))}
          </div>
        </fieldset>
      )}
      {!about && (
        <Field label="Subject (optional)" name="support-subject" error={errors.subject}>
          <input id="support-subject" className="input" maxLength={160} value={subject} onChange={(e) => setSubject(e.target.value)} />
        </Field>
      )}
      <Field label="Your question" name="support-body" error={errors.body} hint="Please do not share card numbers or passwords. Betoch never asks for them.">
        <textarea id="support-body" className="input min-h-28" maxLength={3000} value={body} placeholder={PLACEHOLDERS[topic]}
          aria-invalid={Boolean(errors.body)} aria-describedby={errors.body ? 'support-body-error' : undefined}
          onChange={(e) => setBody(e.target.value)} />
      </Field>
      <div className="flex flex-wrap gap-2">
        <button type="submit" className="btn-primary" disabled={sending}>{sending ? 'Sending...' : 'Send question'}</button>
        {onCancel && <button type="button" className="btn-ghost" onClick={onCancel}>Cancel</button>}
      </div>
    </form>
  )
}
