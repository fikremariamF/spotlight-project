import { Link, useParams, useSearchParams } from 'react-router-dom'
import OfferCard from '../components/OfferCard'
import { ProductImage } from '../components/ProductCard'
import { Alert, EmptyState, ErrorState, PageHeader, Pagination, Spinner } from '../components/ui'
import { INQUIRY_TYPES, formatETB, timeAgo, toQuery } from '../lib/format'
import { useFetch } from '../lib/useFetch'

/**
 * Chats customers had with stores before questions went through Betoch Support. Read-only:
 * nobody can write in them, but a quote the store sent can still be answered and ordered until it expires.
 */
export function Inbox() {
  const [params, setParams] = useSearchParams()
  const list = useFetch(`/conversations?${toQuery({ page: params.get('page') || '' })}`)

  return (
    <div className="container-page py-8">
      <Link to="/support" className="text-sm text-brand-700 hover:underline">Back to support</Link>
      <PageHeader title="Earlier chats with stores"
        subtitle="Kept for your records. For anything new, ask Betoch Support: our team checks with the store for you." />

      {list.loading && <Spinner />}
      {list.error && <ErrorState error={list.error} onRetry={list.reload} />}
      {list.data && (list.data.data.length ? (
        <>
          <ul className="card divide-y divide-stone-100">
            {list.data.data.map((c) => (
              <li key={c.id}>
                <Link to={`/messages/${c.id}`} className="flex gap-3 p-4 hover:bg-stone-50">
                  <ProductImage src={c.product?.image} alt="" className="size-14 shrink-0 rounded-lg" />
                  <div className="min-w-0 flex-1">
                    <div className="flex items-baseline justify-between gap-2">
                      <p className={`truncate ${c.unread ? 'font-bold text-stone-900' : 'font-medium text-stone-800'}`}>{c.seller?.store_name}</p>
                      <span className="shrink-0 text-xs text-stone-500">{timeAgo(c.last_message_at)}</span>
                    </div>
                    <p className="truncate text-sm text-stone-700">{c.subject}</p>
                    <p className="truncate text-sm text-stone-500">{c.last_message?.is_mine && 'You: '}{c.last_message?.body}</p>
                    <span className="mt-1 inline-block rounded bg-stone-100 px-1.5 py-0.5 text-xs text-stone-600">{INQUIRY_TYPES[c.type]}</span>
                  </div>
                </Link>
              </li>
            ))}
          </ul>
          <Pagination meta={list.data.meta} onPage={(p) => setParams({ page: String(p) })} />
        </>
      ) : (
        <EmptyState title="No earlier chats" action={<Link to="/support" className="btn-primary">Go to support</Link>}>
          Questions now go to Betoch Support.
        </EmptyState>
      ))}
    </div>
  )
}

export function ConversationView() {
  const { id } = useParams()
  const { data, error, loading, reload } = useFetch(`/conversations/${id}`)
  const c = data?.data

  if (loading && !c) return <Spinner />
  if (error) return <div className="container-page py-12"><ErrorState error={error} onRetry={reload} /></div>
  if (!c) return null

  const quotesById = Object.fromEntries(c.quotes.map((q) => [q.id, q]))
  const shown = new Set()
  const askLink = c.product ? `/products/${c.product.slug}#buy` : '/support/new'

  return (
    <div className="container-page max-w-3xl py-8">
      <Link to="/messages" className="text-sm text-brand-700 hover:underline">Back to earlier chats</Link>
      <header className="card mt-3 flex gap-3 p-4">
        {c.product && <ProductImage src={c.product.image} alt="" className="size-16 shrink-0 rounded-lg" />}
        <div className="min-w-0 flex-1">
          <h1 className="truncate text-lg font-bold text-stone-900">{c.subject}</h1>
          <p className="text-sm text-stone-600">Store: {c.seller.store_name} · {INQUIRY_TYPES[c.type]}</p>
          {c.product && (
            <p className="text-sm text-stone-600">
              <Link to={`/products/${c.product.slug}`} className="hover:underline">{c.product.name}</Link> · listed at {formatETB(c.product.price_santim)}
            </p>
          )}
        </div>
      </header>

      <div className="mt-4">
        <Alert>This chat is read-only. <Link to={askLink} className="font-semibold underline">Ask Betoch Support</Link> if you need anything else.</Alert>
      </div>

      <ol className="mt-4 space-y-3" aria-label="Messages">
        {c.messages.map((m) => {
          const quote = m.quote_id && quotesById[m.quote_id]
          const showQuote = quote && !shown.has(quote.id)
          if (showQuote) shown.add(quote.id)
          return (
            <li key={m.id} className={`flex flex-col ${m.is_mine ? 'items-end' : 'items-start'}`}>
              {showQuote ? (
                <div className="w-full max-w-lg"><OfferCard quote={quote} noun="quote" onChanged={reload} /></div>
              ) : (
                <div className={`max-w-[85%] rounded-2xl px-4 py-2 text-sm break-words whitespace-pre-line ${m.is_mine ? 'bg-brand-700 text-white' : 'bg-white text-stone-800 ring-1 ring-stone-200'}`}>
                  {m.body}
                </div>
              )}
              <span className="mt-1 text-xs text-stone-400">{new Date(m.created_at).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</span>
            </li>
          )
        })}
      </ol>
    </div>
  )
}
