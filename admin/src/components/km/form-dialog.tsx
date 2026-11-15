import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'

/** Small dialog with a form body and a save button. */
export function FormDialog({ title, trigger, onSave, children, saveText = 'Save changes' }: {
  title: string; trigger: React.ReactNode; onSave: () => Promise<unknown>; children: React.ReactNode; saveText?: string
}) {
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent className='sm:max-w-lg'>
        <DialogHeader><DialogTitle>{title}</DialogTitle></DialogHeader>
        <div className='grid gap-3'>{children}</div>
        <DialogFooter>
          <Button disabled={busy} onClick={async () => {
            setBusy(true)
            try { await onSave(); setOpen(false) } catch { /* toast shown by useMutate */ } finally { setBusy(false) }
          }}>{saveText}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
