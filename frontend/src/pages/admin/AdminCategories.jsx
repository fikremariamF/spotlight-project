import { useState } from 'react'
import { Alert, ErrorState, Field, PageHeader, Spinner } from '../../components/ui'
import { api, parseError } from '../../lib/api'
import { useFetch } from '../../lib/useFetch'

export default function AdminCategories() {
  const list = useFetch('/admin/categories')
  const [form, setForm] = useState({ name: '', parent_id: '', description: '' })
  const [errors, setErrors] = useState({})
  const [notice, setNotice] = useState(null)

  const all = list.data?.data || []
  const topLevel = all.filter((c) => !c.parent_id)

  async function run(fn, success) {
    setNotice(null)
    setErrors({})
    try {
      await fn()
      setNotice({ tone: 'success', text: success })
      list.reload()
      return true
    } catch (err) {
      const e = parseError(err)
      setErrors(e.fields)
      setNotice({ tone: 'error', text: Object.values(e.fields)[0] || e.message })
      return false
    }
  }

  async function create(e) {
    e.preventDefault()
    const ok = await run(() => api.post('/admin/categories', { ...form, parent_id: form.parent_id || null }), `Category "${form.name}" created.`)
    if (ok) setForm({ name: '', parent_id: '', description: '' })
  }

  function rename(c) {
    const name = window.prompt('New name', c.name)
    if (name?.trim() && name !== c.name) run(() => api.put(`/admin/categories/${c.id}`, { name }), 'Category renamed.')
  }

  return (
    <>
      <PageHeader title="Categories" subtitle="Inactive categories and their listings are hidden from customers." />
      {notice && <div className="mb-4"><Alert tone={notice.tone}>{notice.text}</Alert></div>}
      <form onSubmit={create} className="card mb-6 grid gap-3 p-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end" noValidate>
        <Field label="New category name" name="cat-name" error={errors.name}>
          <input id="cat-name" className="input" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        </Field>
        <Field label="Parent (optional)" name="cat-parent" error={errors.parent_id}>
          <select id="cat-parent" className="input" value={form.parent_id} onChange={(e) => setForm({ ...form, parent_id: e.target.value })}>
            <option value="">None (top level)</option>
            {topLevel.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </Field>
        <button type="submit" className="btn-primary">Add category</button>
      </form>
      {list.loading && <Spinner />}
      {list.error && <ErrorState error={list.error} onRetry={list.reload} />}
      {list.data && (
        <div className="card relative overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-stone-200 text-xs text-stone-500 uppercase">
              <tr><th className="px-4 py-3">Name</th><th className="px-4 py-3">Slug</th><th className="px-4 py-3">Listings</th><th className="px-4 py-3">Status</th><th className="px-4 py-3"><span className="sr-only">Actions</span></th></tr>
            </thead>
            <tbody className="divide-y divide-stone-100">
              {topLevel.flatMap((parent) => [parent, ...all.filter((c) => c.parent_id === parent.id)]).map((c) => (
                <tr key={c.id}>
                  <td className={`px-4 py-3 font-medium ${c.parent_id ? 'pl-10 text-stone-700' : 'text-stone-900'}`}>{c.name}</td>
                  <td className="px-4 py-3 text-stone-500">{c.slug}</td>
                  <td className="px-4 py-3">{c.products_count}</td>
                  <td className="px-4 py-3">{c.is_active ? 'Active' : <span className="text-stone-500">Inactive</span>}</td>
                  <td className="px-4 py-3">
                    <div className="flex justify-end gap-1">
                      <button type="button" className="btn-ghost" onClick={() => rename(c)}>Rename</button>
                      <button type="button" className="btn-ghost" onClick={() => run(() => api.put(`/admin/categories/${c.id}`, { is_active: !c.is_active }), c.is_active ? 'Category deactivated.' : 'Category activated.')}>
                        {c.is_active ? 'Deactivate' : 'Activate'}
                      </button>
                      <button type="button" className="btn-ghost text-red-700" onClick={() => window.confirm(`Delete "${c.name}"?`) && run(() => api.delete(`/admin/categories/${c.id}`), 'Category deleted.')}>Delete</button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  )
}
