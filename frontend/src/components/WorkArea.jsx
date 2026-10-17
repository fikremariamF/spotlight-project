import { ChevronDown } from 'lucide-react'
import { Suspense, useEffect, useRef, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useFetch } from '../lib/useFetch'
import { Spinner } from './ui'

/**
 * Loads a summary for the menu counts and reloads it on every page change, so the numbers
 * stay fresh as someone works through them (useFetch already loads the first time).
 */
export function useAreaSummary(url) {
  const { pathname } = useLocation()
  const summary = useFetch(url)
  const { reload } = summary
  const first = useRef(true)
  useEffect(() => {
    if (first.current) {
      first.current = false
      return
    }
    reload()
  }, [pathname, reload])
  return summary
}

/** `also` lists other paths that belong to the same menu item (the listing form under Listings). */
function isHere(link, pathname) {
  const under = (base) => pathname === base || pathname.startsWith(`${base}/`)
  return (link.end ? pathname === link.to || pathname === `${link.to}/` : under(link.to)) || (link.also || []).some(under)
}

/** Open or closed state of expandable menu groups, remembered on this device when storage allows. */
function useOpenGroups() {
  const [open, setOpen] = useState(() => {
    try {
      return JSON.parse(localStorage.getItem('mp_menu_groups') || '{}')
    } catch {
      return {}
    }
  })
  const toggle = (group, value) => setOpen((o) => {
    const next = { ...o, [group]: value }
    try {
      localStorage.setItem('mp_menu_groups', JSON.stringify(next))
    } catch {
      // Private windows may block storage; the menu still works for this visit.
    }
    return next
  })
  return [open, toggle]
}

function Nav({ label, groups, counts }) {
  const { pathname } = useLocation()
  const [open, toggle] = useOpenGroups()
  return (
    <nav aria-label={label} className="space-y-4">
      {groups.map(([group, links, options = {}]) => {
        const inside = links.some((l) => isHere(l, pathname))
        // An expandable group is always open while one of its pages is showing.
        const expanded = !options.collapsible || inside || (open[group] ?? options.defaultOpen ?? false)
        const listId = `menu-${group.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`
        return (
        <div key={group}>
          {options.collapsible ? (
            <button type="button" aria-expanded={expanded} aria-controls={listId} disabled={inside}
              onClick={() => toggle(group, !expanded)}
              className="mb-1 flex w-full items-center justify-between rounded-lg px-3 py-1 text-xs font-semibold tracking-wide text-stone-500 uppercase hover:bg-stone-100 disabled:cursor-default disabled:hover:bg-transparent">
              <span>{group}</span>
              <ChevronDown className={`size-3.5 transition ${expanded ? 'rotate-180' : ''}`} aria-hidden="true" />
            </button>
          ) : (
            <p className="mb-1 px-3 text-xs font-semibold tracking-wide text-stone-400 uppercase">{group}</p>
          )}
          {expanded && (
          <ul id={listId}>
            {links.map((link) => {
              const { to, label: text, icon: Icon, count } = link
              const n = counts && count ? count(counts) || 0 : 0
              const active = isHere(link, pathname)
              return (
                <li key={to}>
                  <Link to={to} aria-current={active ? 'page' : undefined}
                    className={`flex items-center gap-2.5 rounded-lg px-3 py-1.5 text-sm ${active ? 'bg-brand-50 font-semibold text-brand-800' : 'text-stone-700 hover:bg-stone-100'}`}>
                    <Icon className="size-4 shrink-0" aria-hidden="true" />
                    <span className="flex-1">{text}</span>
                    {n > 0 && <span className="rounded-full bg-amber-100 px-1.5 text-xs font-semibold text-amber-800">{n}<span className="sr-only"> waiting</span></span>}
                  </Link>
                </li>
              )
            })}
          </ul>
          )}
        </div>
        )
      })}
    </nav>
  )
}

/**
 * Shell for the admin and seller areas: a grouped side menu on wide screens, and on phones one
 * button naming the current page that opens the same menu. `count` on a link picks a number from
 * `counts` to show beside it when something is waiting.
 */
export default function WorkArea({ label, groups, counts, children }) {
  const { pathname } = useLocation()
  const [menuOpen, setMenuOpen] = useState(false)
  const links = groups.flatMap(([, l]) => l)
  const here = links.filter((l) => isHere(l, pathname)).sort((a, b) => b.to.length - a.to.length)[0]
  const waiting = counts ? links.reduce((n, l) => n + (l.count ? l.count(counts) || 0 : 0), 0) : 0
  const menuId = `${label.toLowerCase()}-menu`

  useEffect(() => setMenuOpen(false), [pathname])

  return (
    <div className="container-page py-5 lg:grid lg:grid-cols-[12.5rem_minmax(0,1fr)] lg:gap-8 lg:py-6 print:block print:max-w-none print:p-0">
      <aside className="hidden lg:block print:hidden">
        <div className="sticky top-20"><Nav label={label} groups={groups} counts={counts} /></div>
      </aside>

      <div className="mb-4 lg:hidden print:hidden">
        <button type="button" className="btn-secondary w-full justify-between" aria-expanded={menuOpen} aria-controls={menuId} onClick={() => setMenuOpen((o) => !o)}>
          <span className="flex items-center gap-2">
            {here && <here.icon className="size-4" aria-hidden="true" />}
            {here?.label ?? label}
          </span>
          <span className="flex items-center gap-2">
            {waiting > 0 && <span className="rounded-full bg-amber-100 px-2 text-xs font-semibold text-amber-800">{waiting} to do</span>}
            <ChevronDown className={`size-4 transition ${menuOpen ? 'rotate-180' : ''}`} aria-hidden="true" />
          </span>
        </button>
        {menuOpen && <div id={menuId} className="card mt-2 p-2"><Nav label={label} groups={groups} counts={counts} /></div>}
      </div>

      {/* Shared pages (like messages) bring their own page container; inside the shell it adds nothing. */}
      <div className="min-w-0 [&_.container-page]:max-w-none [&_.container-page]:px-0 [&_.container-page]:py-0">
        <Suspense fallback={<Spinner />}>{children}</Suspense>
      </div>
    </div>
  )
}
