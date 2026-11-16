import { useNavigate } from '@tanstack/react-router'
import { toast } from 'sonner'
import { useAuthStore } from '@/stores/auth-store'
import { ConfirmDialog } from '@/components/confirm-dialog'

interface SignOutDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

/** Asks first; after signing out the sign-in page says "You are signed out". */
export function SignOutDialog({ open, onOpenChange }: SignOutDialogProps) {
  const navigate = useNavigate()
  const signOut = useAuthStore((s) => s.signOut)

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={onOpenChange}
      title='Do you want to sign out?'
      desc='You can sign in again any time.'
      confirmText='Sign out'
      cancelBtnText='Cancel'
      destructive
      handleConfirm={async () => {
        await signOut()
        onOpenChange(false)
        navigate({ to: '/sign-in', replace: true })
        toast.success('You are signed out')
      }}
      className='sm:max-w-sm'
    />
  )
}
