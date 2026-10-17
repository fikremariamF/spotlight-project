import { Link, useParams } from 'react-router-dom'
import { NotFound } from '../components/ui'
import { useConfig } from '../context/ConfigContext'

// Draft copy. The business must review these policies before launch.
const PAGES = {
  delivery: {
    title: 'Delivery information',
    body: [
      'We currently serve Addis Ababa. Each listing shows whether the seller delivers, offers pickup, or both.',
      'When a delivery fee is shown on the listing, that is the fee for delivery within Addis Ababa. When it says the fee is confirmed by the seller, the seller will quote it after confirming your address and before you pay.',
      'Made-to-order furniture shows an estimated production time. Delivery is arranged once the piece is finished.',
      'We do not offer live driver tracking. Sellers update you as your order moves.',
    ],
  },
  returns: {
    title: 'Returns and refunds',
    body: [
      'Inspect furniture on delivery and report damage or a mismatch with the listing to Betoch Support within 48 hours. Use "Get help with this order" on the order page.',
      'Custom-made furniture built to your specifications is generally not returnable unless it does not match the agreed specification.',
      'Refund handling will be described in full when online payment launches.',
    ],
  },
  policies: {
    title: 'Marketplace policies',
    body: [
      'Only furniture may be listed. Every seller is reviewed before their store goes live, and listings are moderated.',
      'Listings must show the real item, accurate dimensions and materials, and a price in Ethiopian birr.',
      'Sellers may only manage their own listings, orders and records. We may suspend sellers who mislead customers.',
      'Customers and stores do not message each other. Questions go to Betoch Support, which checks with the store and answers. Stores see a customer\'s name, phone and address only while they prepare and deliver an order.',
    ],
  },
  selling: {
    title: 'How selling works',
    body: [
      'Apply with your store details. Our team verifies your store, usually within two business days.',
      'Once approved, add listings with photos, dimensions, materials, stock or production time, and delivery terms. Each listing is reviewed before it is published.',
      'You keep control of price and stock. Editing the name, description, category, material or condition of a published listing sends it back for a quick review.',
      'Commission is charged only on completed marketplace orders. Rates will be published before ordering opens.',
    ],
  },
}

export default function Help() {
  const { topic } = useParams()
  const { name } = useConfig()
  const page = PAGES[topic]
  if (!page) return <NotFound />

  return (
    <div className="container-page max-w-3xl py-10">
      <p className="text-sm text-stone-500"><Link to="/" className="hover:underline">{name}</Link> / Help</p>
      <h1 className="mt-2 text-3xl font-bold tracking-tight text-stone-900">{page.title}</h1>
      <div className="mt-6 space-y-4 text-stone-700">
        {page.body.map((p) => <p key={p}>{p}</p>)}
      </div>
    </div>
  )
}
