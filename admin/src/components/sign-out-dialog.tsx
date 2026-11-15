import { useNavigate } from '@tanstack/react-router'
import { useAuthStore } from '@/stores/auth-store'
import { ConfirmDialog } from '@/components/confirm-dialog'

interface SignOutDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function SignOutDialog({ open, onOpenChange }: SignOutDialogProps) {
  const navigate = useNavigate()
  const signOut = useAuthStore((s) => s.signOut)

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={onOpenChange}
      title='Sign out'
      desc='Sign out of the Kulmis Market admin?'
      confirmText='Sign out'
      destructive
      handleConfirm={async () => {
        await signOut()
        navigate({ to: '/sign-in', replace: true })
      }}
      className='sm:max-w-sm'
    />
  )
}
