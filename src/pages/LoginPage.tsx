import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Navigate } from 'react-router-dom'
import { z } from 'zod'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { AppInput } from '@/components/ui/AppInput'
import { ThemeToggleSwitch } from '@/components/ui/ThemeToggleSwitch'
import { useAuth } from '@/context/AuthContext'
import { useLoadingLock } from '@/context/LoadingLockContext'
import { useToast } from '@/context/ToastContext'
import { AuthError, DEV_LOGIN_CREDENTIALS } from '@/services/authService'
import { cn } from '@/lib/utils'

const loginSchema = z.object({
  email: z.string().email('Correo inválido'),
  password: z.string().min(8, 'La contraseña debe tener al menos 8 caracteres'),
})

type LoginFormValues = z.infer<typeof loginSchema>

function OperamEmblem({ className }: { className?: string }) {
  return (
    <div className={cn('relative h-16 w-16', className)}>
      <span className="absolute inset-0 rounded-2xl bg-gradient-to-br from-[#0A84FF] via-[#6366F1] to-[#22D3EE] opacity-90 shadow-[0_0_40px_rgba(10,132,255,0.45)]" />
      <span className="absolute inset-[3px] rounded-[0.9rem] bg-black/20 backdrop-blur-sm dark:bg-black/40" />
      <span className="absolute inset-0 flex items-center justify-center text-xl font-black tracking-tight text-white">
        O
      </span>
    </div>
  )
}

/**
 * Login Liquid Glass — siempre contra API real (LoginService / MySQL / JWT HS256).
 */
export function LoginPage() {
  const { isAuthenticated, isLoading, login } = useAuth()
  const { withScreenLock } = useLoadingLock()
  const toast = useToast()
  const [formError, setFormError] = useState<string | null>(null)
  const [blocked, setBlocked] = useState(false)

  const form = useForm<LoginFormValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: '', password: '' },
  })

  if (!isLoading && isAuthenticated) {
    return <Navigate to="/dashboard" replace />
  }

  async function onSubmit(values: LoginFormValues) {
    setFormError(null)
    setBlocked(false)
    try {
      await withScreenLock(async () => {
        await login(values)
      }, 'Autenticando sesión empresarial...')
      toast.success('Sesión iniciada', 'Bienvenido al ERP Operam.')
    } catch (error) {
      const authError = error as AuthError
      if (authError.status === 429) {
        setBlocked(true)
        setFormError(authError.message)
        toast.warning('Acceso bloqueado temporalmente', authError.message)
        return
      }
      const message = authError.message || 'No se pudo iniciar sesión.'
      setFormError(message)
      toast.error('Credenciales incorrectas', message)
    }
  }

  const submitting = form.formState.isSubmitting

  return (
    <div className="relative flex min-h-svh overflow-hidden bg-[#F8FAFC] text-[#090D16] dark:bg-[#000000] dark:text-white">
      <div className="absolute right-4 top-4 z-20 md:right-6 md:top-6">
        <ThemeToggleSwitch />
      </div>

      {/* Columna marca */}
      <section
        className={cn(
          'relative hidden w-1/2 flex-col items-center justify-center px-10 lg:flex',
          'bg-[radial-gradient(circle_at_25%_40%,rgba(56,189,248,0.18),transparent_65%)]',
          'dark:bg-[radial-gradient(circle_at_25%_40%,rgba(29,78,216,0.22),transparent_65%)]',
        )}
      >
        <OperamEmblem className="mb-6" />
        <h1 className="font-heading text-5xl font-bold text-[#090D16] dark:text-white">
          Operam
        </h1>
        <p className="mt-3 text-xs font-semibold uppercase tracking-[0.2em] text-[#0066FF] dark:text-blue-400">
          Contratos y suscripciones
        </p>
      </section>

      {/* Columna formulario */}
      <section className="relative flex w-full flex-1 items-center justify-center border-slate-200/70 p-6 dark:border-white/[0.07] md:p-10 lg:w-1/2 lg:border-l">
        <AppCard
          className={cn(
            'w-full max-w-md rounded-3xl p-7 backdrop-blur-2xl md:p-8',
            'border-white bg-white/90 shadow-2xl',
            'dark:border-white/[0.09] dark:bg-[#111113]/75 dark:shadow-[inset_0_1px_0_0_rgba(255,255,255,0.12),0_20px_80px_rgba(0,0,0,0.65)]',
          )}
        >
          <div className="mb-6 flex flex-col items-center lg:hidden">
            <OperamEmblem className="mb-3 h-12 w-12" />
            <p className="text-2xl font-black">Operam</p>
          </div>

          <div className="text-center">
            <h2 className="font-heading text-2xl font-bold">Bienvenido de vuelta</h2>
            <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
              Ingresa tus credenciales para acceder al ERP
            </p>
          </div>

          <form className="mt-8 space-y-4" onSubmit={form.handleSubmit(onSubmit)}>
            <div>
              <label
                className="mb-2 block text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400"
                htmlFor="email"
              >
                Correo electrónico <span className="text-red-500">*</span>
              </label>
              <AppInput
                id="email"
                type="email"
                autoComplete="username"
                error={Boolean(form.formState.errors.email)}
                className="rounded-2xl px-4 py-3"
                {...form.register('email')}
              />
              {form.formState.errors.email && (
                <p className="mt-1 text-xs text-red-500">
                  {form.formState.errors.email.message}
                </p>
              )}
            </div>

            <div>
              <label
                className="mb-2 block text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400"
                htmlFor="password"
              >
                Contraseña <span className="text-red-500">*</span>
              </label>
              <AppInput
                id="password"
                type="password"
                autoComplete="current-password"
                error={Boolean(form.formState.errors.password)}
                className="rounded-2xl px-4 py-3"
                {...form.register('password')}
              />
              {form.formState.errors.password && (
                <p className="mt-1 text-xs text-red-500">
                  {form.formState.errors.password.message}
                </p>
              )}
            </div>

            {formError && (
              <div className="rounded-2xl border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-500">
                {formError}
              </div>
            )}

            <AppButton
              type="submit"
              className="w-full rounded-2xl bg-gradient-to-r from-blue-600 to-blue-500 py-3 font-semibold shadow-[0_0_25px_rgba(37,99,235,0.45)] hover:from-blue-500 hover:to-blue-400"
              disabled={blocked}
              isLoading={submitting}
            >
              {submitting ? 'Validando…' : 'Iniciar Sesión'}
            </AppButton>
          </form>

          <div className="mt-6 space-y-3 border-t border-slate-200/80 pt-4 dark:border-white/[0.07]">
            <AppButton
              type="button"
              variant="ghost"
              className="w-full text-xs text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-white/5"
              onClick={() => {
                form.setValue('email', DEV_LOGIN_CREDENTIALS.email)
                form.setValue('password', DEV_LOGIN_CREDENTIALS.password)
                setFormError(null)
                setBlocked(false)
              }}
            >
              Autocompletar credenciales de prueba
            </AppButton>
          </div>
        </AppCard>
      </section>
    </div>
  )
}
