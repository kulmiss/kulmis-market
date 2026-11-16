import { useState } from 'react'
import { Link } from '@tanstack/react-router'
import { LogOut, MonitorX, Palette } from 'lucide-react'
import { toast } from 'sonner'
import { api, errorMessage } from '@/lib/api'
import useDialogState from '@/hooks/use-dialog-state'
import { useAuthStore } from '@/stores/auth-store'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { SignOutDialog } from '@/components/sign-out-dialog'

export function ProfileDropdown() {
  const [open, setOpen] = useDialogState()
  const [others, setOthers] = useState(false)
  const user = useAuthStore((s) => s.user)
  const initials = (user?.name ?? 'KM').split(' ').map((p) => p[0]).join('').slice(0, 2).toUpperCase()

  return (
    <>
      <DropdownMenu modal={false}>
        <DropdownMenuTrigger asChild>
          <Button variant='ghost' className='relative h-8 w-8 rounded-full'>
            <Avatar className='h-8 w-8'><AvatarFallback>{initials}</AvatarFallback></Avatar>
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent className='w-56' align='end' forceMount>
          <DropdownMenuLabel className='font-normal'>
            <div className='flex flex-col gap-1.5'>
              <p className='text-sm leading-none font-medium'>{user?.name}</p>
              <p className='text-xs leading-none text-muted-foreground'>{user?.role_label}</p>
            </div>
          </DropdownMenuLabel>
          <DropdownMenuSeparator />
          <DropdownMenuItem asChild><Link to='/settings'><Palette /> Appearance</Link></DropdownMenuItem>
          <DropdownMenuItem onClick={() => setOthers(true)}><MonitorX /> Sign out other devices</DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem variant='destructive' onClick={() => setOpen(true)}><LogOut /> Sign out</DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
      <SignOutDialog open={!!open} onOpenChange={setOpen} />
      <ConfirmDialog
        open={others}
        onOpenChange={setOthers}
        title='Sign out on your other devices?'
        desc='Every other phone and computer is signed out. You stay signed in here.'
        confirmText='Sign them out'
        cancelBtnText='Cancel'
        handleConfirm={() =>
          api.post<{ ended: number }>('/auth/sign-out-others')
            .then((r) => toast.success(r.data.ended === 1 ? 'Signed out 1 other device.' : `Signed out ${r.data.ended} other devices.`))
            .catch((e) => toast.error(errorMessage(e)))
            .finally(() => setOthers(false))
        }
        className='sm:max-w-sm'
      />
    </>
  )
}
