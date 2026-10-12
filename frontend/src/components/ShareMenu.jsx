import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react'
import { Copy, Send, Share2 } from 'lucide-react'
import { toast } from 'sonner'

/** Share a listing: the phone's share sheet when available, Telegram, WhatsApp or a copied link. */
export default function ShareMenu({ title, text }) {
  const url = window.location.href
  const message = `${title}${text ? ` - ${text}` : ''}`

  async function copy() {
    try {
      await navigator.clipboard.writeText(url)
      toast.success('Link copied')
    } catch {
      toast.error('Could not copy. Copy the address from your browser instead.')
    }
  }

  async function nativeShare() {
    try {
      await navigator.share({ title, text: message, url })
    } catch {
      // Closed by the user; nothing to do.
    }
  }

  const item = 'flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm text-stone-700 data-focus:bg-stone-100'
  return (
    <Menu as="div" className="relative">
      <MenuButton className="btn-secondary"><Share2 className="size-4" aria-hidden="true" />Share</MenuButton>
      <MenuItems anchor="bottom start" transition className="z-50 mt-2 w-56 rounded-2xl border border-stone-200 bg-white p-1.5 shadow-lift transition duration-100 focus:outline-none data-closed:scale-95 data-closed:opacity-0">
        {typeof navigator !== 'undefined' && navigator.share && (
          <MenuItem><button type="button" className={item} onClick={nativeShare}><Share2 className="size-4" aria-hidden="true" />Share with an app</button></MenuItem>
        )}
        <MenuItem>
          <a className={item} href={`https://t.me/share/url?url=${encodeURIComponent(url)}&text=${encodeURIComponent(message)}`} target="_blank" rel="noopener noreferrer">
            <Send className="size-4" aria-hidden="true" />Telegram
          </a>
        </MenuItem>
        <MenuItem>
          <a className={item} href={`https://wa.me/?text=${encodeURIComponent(`${message} ${url}`)}`} target="_blank" rel="noopener noreferrer">
            <svg viewBox="0 0 24 24" className="size-4 fill-current" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.2-.4.7-1.4.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.1.7.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3Z" /></svg>
            WhatsApp
          </a>
        </MenuItem>
        <MenuItem><button type="button" className={item} onClick={copy}><Copy className="size-4" aria-hidden="true" />Copy link</button></MenuItem>
      </MenuItems>
    </Menu>
  )
}
