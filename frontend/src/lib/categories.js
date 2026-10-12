import { Archive, Briefcase, BedDouble, Hammer, Library, Package, Sofa, UtensilsCrossed } from 'lucide-react'
import { useEffect, useState } from 'react'
import { api } from './api'

const ICONS = {
  'sofas-living-room': Sofa,
  'beds-mattresses': BedDouble,
  'wardrobes-storage': Archive,
  dining: UtensilsCrossed,
  office: Briefcase,
  'cabinets-shelves': Library,
  'custom-made': Hammer,
}

/** Icon for a category; categories added later by admins get a generic one. */
export function categoryIcon(slug) {
  return ICONS[slug] || Package
}

// One request per page load, shared by the header, home page and catalog.
let cached = null
function loadCategories() {
  cached ??= api.get('/categories').then((res) => res.data.data).catch((err) => {
    cached = null
    throw err
  })
  return cached
}

export function useCategories() {
  const [state, setState] = useState({ data: null, error: false })
  useEffect(() => {
    let live = true
    loadCategories()
      .then((data) => live && setState({ data, error: false }))
      .catch(() => live && setState({ data: null, error: true }))
    return () => { live = false }
  }, [])
  return state
}
