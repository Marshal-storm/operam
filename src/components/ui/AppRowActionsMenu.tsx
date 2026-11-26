import * as DropdownMenu from '@radix-ui/react-dropdown-menu'
import { MoreHorizontal } from 'lucide-react'
import * as React from 'react'
import { cn } from '@/lib/utils'
import { AppButton } from './AppButton'

export type RowActionItem = {
  id?: string
  label: string
  icon?: React.ReactNode
  onClick: () => void
  variant?: 'default' | 'danger'
  disabled?: boolean
  /** Si es true, el ítem no se muestra (p. ej. sin permiso RBAC). */
  hidden?: boolean
  separatorBefore?: boolean
}

export type AppRowActionsMenuProps = {
  actions: RowActionItem[]
  align?: 'start' | 'end' | 'center'
  /** Aria del disparador. */
  'aria-label'?: string
  className?: string
  triggerClassName?: string
  /** Clases opcionales del menú desplegable (ej. z-[60] dentro de un AppDrawer). */
  contentClassName?: string
}

/** Encabezado homologado de la columna ACCIONES (siempre visible, alineado a la derecha). */
export function AppActionsColumnHeader({ className }: { className?: string }) {
  return (
    <span className={cn('block w-full text-right font-medium uppercase tracking-wide', className)}>
      ACCIONES
    </span>
  )
}

const MENU_SURFACE_CLASS =
  'z-40 min-w-[196px] rounded-[20px] p-1.5 ' +
  'bg-white/80 backdrop-blur-2xl backdrop-saturate-150 ' +
  'border border-white/85 ring-1 ring-slate-900/[0.07] ' +
  'shadow-[inset_0_1px_1px_rgba(255,255,255,0.95),0_18px_40px_-10px_rgba(15,23,42,0.18)] ' +
  'dark:bg-[linear-gradient(145deg,rgba(36,36,42,0.86)_0%,rgba(16,16,20,0.92)_100%)] ' +
  'dark:border-white/[0.13] dark:ring-1 dark:ring-white/[0.05] ' +
  'dark:shadow-[inset_0_1px_0_rgba(255,255,255,0.18),0_20px_48px_-10px_rgba(0,0,0,0.85)] ' +
  'animate-in fade-in-0 zoom-in-95 duration-150'

const ITEM_BASE_CLASS =
  'flex cursor-pointer select-none items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-medium outline-none transition-all duration-150 ' +
  'text-slate-700 hover:bg-blue-500/10 hover:text-blue-600 focus:bg-blue-500/10 focus:text-blue-600 ' +
  'data-[highlighted]:bg-blue-500/10 data-[highlighted]:text-blue-600 ' +
  'dark:text-zinc-100 dark:hover:bg-blue-500/15 dark:hover:text-blue-400 ' +
  'dark:data-[highlighted]:bg-blue-500/15 dark:data-[highlighted]:text-blue-400 ' +
  'data-[disabled]:cursor-not-allowed data-[disabled]:opacity-40 data-[disabled]:pointer-events-none'

const ITEM_DANGER_CLASS =
  'text-red-600 hover:bg-red-500/10 hover:text-red-600 focus:bg-red-500/10 ' +
  'data-[highlighted]:bg-red-500/10 data-[highlighted]:text-red-600 ' +
  'dark:text-red-400 dark:hover:bg-red-500/10 dark:hover:text-red-400 ' +
  'dark:data-[highlighted]:bg-red-500/10 dark:data-[highlighted]:text-red-400'

/**
 * Menú de acciones de fila (⋮) — iOS Liquid Glass (§3.3).
 * Cierra el dropdown antes de disparar la acción para no flotar sobre AppModal (z-50).
 */
export function AppRowActionsMenu({
  actions,
  align = 'end',
  'aria-label': ariaLabel = 'Abrir acciones',
  className,
  triggerClassName,
  contentClassName,
}: AppRowActionsMenuProps) {
  const [open, setOpen] = React.useState(false)
  const visible = actions.filter((action) => !action.hidden)

  if (visible.length === 0) {
    return (
      <span className={cn('inline-block text-sm text-slate-400 dark:text-zinc-500', className)}>
        —
      </span>
    )
  }

  function runAction(action: RowActionItem) {
    if (action.disabled) return
    // Cierra primero para liberar foco/portal antes de abrir un AppModal.
    setOpen(false)
    requestAnimationFrame(() => {
      action.onClick()
    })
  }

  return (
    <div className={cn('inline-flex justify-end', className)}>
      <DropdownMenu.Root open={open} onOpenChange={setOpen}>
        <DropdownMenu.Trigger asChild>
          <AppButton
            type="button"
            variant="ghost"
            size="sm"
            className={cn(
              'h-8 w-8 rounded-full p-0 transition-colors',
              'hover:bg-slate-200/70 dark:hover:bg-white/10',
              open && 'bg-slate-200/70 dark:bg-white/10',
              triggerClassName,
            )}
            aria-label={ariaLabel}
            data-state={open ? 'open' : 'closed'}
          >
            <MoreHorizontal className="h-4 w-4" />
          </AppButton>
        </DropdownMenu.Trigger>

        <DropdownMenu.Portal>
          <DropdownMenu.Content
            align={align}
            sideOffset={6}
            collisionPadding={8}
            className={cn(MENU_SURFACE_CLASS, contentClassName)}
            onCloseAutoFocus={(event) => {
              // Evita que el foco regrese al trigger y pelee con el modal recién abierto.
              event.preventDefault()
            }}
          >
            {visible.map((action, index) => (
              <React.Fragment key={action.id ?? `${action.label}-${index}`}>
                {action.separatorBefore ? (
                  <DropdownMenu.Separator className="my-1 h-px bg-slate-200/70 dark:bg-white/[0.08]" />
                ) : null}
                <DropdownMenu.Item
                  disabled={action.disabled}
                  onSelect={(event) => {
                    event.preventDefault()
                    runAction(action)
                  }}
                  className={cn(
                    ITEM_BASE_CLASS,
                    action.variant === 'danger' && ITEM_DANGER_CLASS,
                  )}
                >
                  {action.icon ? (
                    <span className="flex h-4 w-4 shrink-0 items-center justify-center opacity-80">
                      {action.icon}
                    </span>
                  ) : null}
                  <span className="whitespace-nowrap">{action.label}</span>
                </DropdownMenu.Item>
              </React.Fragment>
            ))}
          </DropdownMenu.Content>
        </DropdownMenu.Portal>
      </DropdownMenu.Root>
    </div>
  )
}
