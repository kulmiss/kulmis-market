import { useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ImagePlus, Trash2, TriangleAlert, Upload, Video } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { mediaQuery } from './queries'
import type { Media } from './types'


/** The picked picture (or video) with "Change" and "Remove". */
export function MediaField({ kind, value, onChange, label, videoUpload, onPoster }: {
  kind: 'image' | 'video'; value: number | null; onChange: (id: number | null) => void; label: string; videoUpload?: boolean; onPoster?: (id: number) => void
}) {
  const [open, setOpen] = useState(false)
  const media = useQuery(mediaQuery)
  const m = media.data?.find((x) => x.id === value)
  return (
    <div className='grid gap-1.5 text-sm font-medium'>
      <span>{label}</span>
      <div className='flex items-center gap-3'>
        <button type='button' onClick={() => setOpen(true)} className='flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted' aria-label={m ? `Change ${label}` : `Choose ${label}`}>
          {m?.thumb ? <img src={m.thumb} alt='' className='size-full object-cover' /> : kind === 'video' ? <Video className='size-6 text-muted-foreground' /> : <ImagePlus className='size-6 text-muted-foreground' />}
        </button>
        <div className='grid min-w-0 gap-1'>
          {m ? <span className='truncate text-xs font-normal text-muted-foreground'>{m.alt || <span className='text-destructive'>No description yet</span>}</span>
            : value ? <span className='text-xs text-destructive'>This one was deleted. Pick another.</span> : null}
          <div className='flex gap-2'>
            <Button type='button' size='sm' variant='outline' onClick={() => setOpen(true)}>{m ? 'Change' : 'Choose'}</Button>
            {value !== null && <Button type='button' size='sm' variant='ghost' onClick={() => onChange(null)}>Remove</Button>}
          </div>
        </div>
      </div>
      <MediaLibrary open={open} onOpenChange={setOpen} kind={kind} videoUpload={videoUpload} onPick={(id, poster) => { onChange(id); if (poster && onPoster) onPoster(poster); setOpen(false) }} />
    </div>
  )
}

export function MediaLibrary({ open, onOpenChange, kind, onPick, videoUpload }: {
  open: boolean; onOpenChange: (o: boolean) => void; kind: 'image' | 'video'; onPick: (id: number, poster?: number) => void; videoUpload?: boolean
}) {
  const qc = useQueryClient()
  const media = useQuery({ ...mediaQuery, enabled: open })
  const file = useRef<HTMLInputElement>(null)
  const [alt, setAlt] = useState('')
  const [busy, setBusy] = useState(false)
  const items = (media.data ?? []).filter((m) => m.kind === kind)
  const canUpload = kind === 'image' || videoUpload

  async function upload() {
    const f = file.current?.files?.[0]
    if (!f) return toast.error(kind === 'image' ? 'Choose a picture first.' : 'Choose a video first.')
    if (alt.trim().length < 3) return toast.error(kind === 'image' ? 'Write a short description of the picture (for people who cannot see it).' : 'Write what the video shows.')
    const body = new FormData()
    body.append('kind', kind)
    body.append('alt', alt.trim())
    body.append('file', f)
    setBusy(true)
    try {
      const r = (await api.post<{ media: Media; poster: Media | null }>('/home-page/media', body)).data
      await qc.invalidateQueries({ queryKey: ['home-media'] })
      toast.success(kind === 'image' ? 'Picture added (made smaller, hidden data removed)' : 'Video added (re-encoded, hidden data removed)')
      setAlt('')
      if (file.current) file.current.value = ''
      onPick(r.media.id, r.poster?.id)
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  async function saveAlt(m: Media, value: string) {
    if (value.trim() === (m.alt ?? '') || value.trim().length < 3) return
    try {
      await api.put(`/home-page/media/${m.id}`, { alt: value.trim() })
      await qc.invalidateQueries({ queryKey: ['home-media'] })
    } catch (e) { toast.error(errorMessage(e)) }
  }

  async function remove(m: Media) {
    if (!confirm('Delete this for good?')) return
    try {
      await api.delete(`/home-page/media/${m.id}`)
      await qc.invalidateQueries({ queryKey: ['home-media'] })
    } catch (e) { toast.error(errorMessage(e)) }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className='max-h-[90vh] overflow-y-auto sm:max-w-3xl'>
        <DialogHeader>
          <DialogTitle>{kind === 'image' ? 'Pictures' : 'Videos'}</DialogTitle>
          <DialogDescription>{kind === 'image' ? 'Uploads are made smaller (AVIF, WebP and JPEG in 3 sizes) and camera data like the place is removed.' : 'Up to 2 minutes and 15 MB. It is re-encoded and gets a poster picture.'}</DialogDescription>
        </DialogHeader>
        {canUpload ? (
          <div className='grid gap-2 rounded-md border p-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end'>
            <label className='grid gap-1.5 text-sm font-medium'>{kind === 'image' ? 'Picture' : 'Video file'}
              <Input ref={file} type='file' accept={kind === 'image' ? 'image/jpeg,image/png,image/webp,image/avif' : 'video/mp4,video/webm,video/quicktime'} />
            </label>
            <label className='grid gap-1.5 text-sm font-medium'>Description (alt text)
              <Input value={alt} onChange={(e) => setAlt(e.target.value)} maxLength={200} placeholder={kind === 'image' ? 'e.g. Red woven basket on a table' : 'e.g. A weaver at her loom'} />
            </label>
            <Button type='button' onClick={upload} disabled={busy}><Upload /> {busy ? 'Uploading…' : 'Upload'}</Button>
          </div>
        ) : <p className='rounded-md border p-3 text-sm text-muted-foreground'>Video files need ffmpeg on the server. Use a YouTube or Vimeo link instead.</p>}
        {media.isLoading ? <p className='text-sm text-muted-foreground'>Loading…</p> : items.length === 0 ? <p className='text-sm text-muted-foreground'>Nothing uploaded yet.</p> : (
          <ul className='grid grid-cols-2 gap-3 sm:grid-cols-4'>
            {items.map((m) => (
              <li key={m.id} className='grid gap-1.5 rounded-md border p-2'>
                <button type='button' className='aspect-square overflow-hidden rounded bg-muted' onClick={() => onPick(m.id)} aria-label={`Use ${m.alt ?? 'this'}`}>
                  {m.thumb ? <img src={m.thumb} alt='' className='size-full object-cover' loading='lazy' /> : <Video className='m-auto size-8 text-muted-foreground' />}
                </button>
                <Input defaultValue={m.alt ?? ''} aria-label='Description' className='h-8 text-xs' onBlur={(e) => saveAlt(m, e.target.value)} />
                {!m.alt && <span className='flex items-center gap-1 text-xs text-destructive'><TriangleAlert className='size-3' /> Add a description</span>}
                <div className='flex justify-between'>
                  <Button type='button' size='sm' onClick={() => onPick(m.id)}>Use</Button>
                  <Button type='button' size='icon' variant='ghost' aria-label='Delete' onClick={() => remove(m)}><Trash2 /></Button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </DialogContent>
    </Dialog>
  )
}
