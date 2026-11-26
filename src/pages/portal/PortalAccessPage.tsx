import { zodResolver } from '@hookform/resolvers/zod'
import { FlaskConical, MailCheck, ShieldCheck } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useForm } from 'react-hook-form'
import { Navigate, useNavigate, useSearchParams } from 'react-router-dom'
import { PortalAmbientBackground } from '@/components/portal/PortalAmbientBackground'
import { PORTAL_CARD_CLASS } from '@/components/portal/portalTokens'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard } from '@/components/ui/AppCard'
import { AppInput } from '@/components/ui/AppInput'
import { useLoadingLock } from '@/context/LoadingLockContext'
import { usePortalAuth } from '@/context/PortalAuthContext'
import { useToast } from '@/context/ToastContext'
import { cn } from '@/lib/utils'
import { portalAccessSchema, type PortalAccessValues } from '@/lib/validationSchemas'
import { requestPortalLink } from '@/services/portalService'
import type { PortalRequestLinkResult } from '@/types/portal'

/**
 * Acceso sin contraseña al Portal Cliente (`/portal/acceso` y `/portal/login`).
 * - `?t=` presente → canje automático de la liga mágica (un solo uso).
 * - Sin token → formulario de correo ("Recibir liga de acceso").
 */
export function PortalAccessPage() {
  const [searchParams] = useSearchParams()
  const linkToken = searchParams.get('t')
  const navigate = useNavigate()
  const toast = useToast()
  const { withScreenLock } = useLoadingLock()
  const { status, exchange } = usePortalAuth()

  const [linkError, setLinkError] = useState<string | null>(null)
  const [sent, setSent] = useState<PortalRequestLinkResult | null>(null)
  const [isSending, setIsSending] = useState(false)
  const [isExchangingToken, setIsExchangingToken] = useState(false)
  const exchangedRef = useRef<string | null>(null)

  const form = useForm<PortalAccessValues>({
    resolver: zodResolver(portalAccessSchema),
    defaultValues: { email: '' },
  })

  // Canje automático de la liga: se borra `?t=` de la barra de direcciones de inmediato
  // para no dejarlo en el historial del navegador ni filtrarlo como Referer.
  useEffect(() => {
    if (!linkToken || exchangedRef.current === linkToken) return
    exchangedRef.current = linkToken
    window.history.replaceState({}, '', '/portal/acceso')
    setLinkError(null)
    setIsExchangingToken(true)
    void withScreenLock(() => exchange(linkToken), 'Verificando acceso...')
      .then((profile) => {
        toast.success('Bienvenido', profile.contact_name || profile.client_name)
        navigate('/portal', { replace: true })
      })
      .catch((error: unknown) => {
        setIsExchangingToken(false)
        setLinkError(
          error instanceof Error
            ? error.message
            : 'La liga de acceso es inválida, ya fue usada o expiró. Solicita una nueva.',
        )
        navigate('/portal/acceso', { replace: true })
      })
  }, [linkToken, exchange, navigate, toast, withScreenLock])

  async function onSubmit(values: PortalAccessValues) {
    setIsSending(true)
    try {
      const result = await requestPortalLink(values.email.trim().toLowerCase())
      setSent(result)
      setLinkError(null)
    } catch (error) {
      toast.apiError(error)
    } finally {
      setIsSending(false)
    }
  }

  // Sandbox local: abre la liga de prueba dentro de la misma app (canje automático).
  function openSandboxLink(url: string) {
    try {
      const token = new URL(url).searchParams.get('t')
      if (token) navigate(`/portal/acceso?t=${encodeURIComponent(token)}`)
    } catch {
      toast.error('Liga de prueba inválida', 'Solicita una nueva liga de acceso.')
    }
  }

  if (status === 'authenticated' && !linkToken && !isExchangingToken) {
    return <Navigate to="/portal" replace />
  }

  const isExchanging = (isExchangingToken || Boolean(linkToken)) && !linkError

  return (
    <div className="relative flex min-h-screen items-center justify-center bg-[#F5F7FB] px-4 py-10 font-sans text-[#090D16] dark:bg-black dark:text-zinc-50">
      <PortalAmbientBackground />

      <div className="relative z-10 w-full max-w-md space-y-6">
        <div className="space-y-3 text-center">
          <span className="mx-auto flex h-14 w-14 items-center justify-center rounded-[20px] bg-[linear-gradient(135deg,#0066FF,#7A3FC4)] text-white shadow-[0_10px_28px_rgba(0,102,255,0.4)]">
            <ShieldCheck className="h-7 w-7" aria-hidden />
          </span>
          <div className="space-y-1">
            <h1 className="font-heading text-2xl font-bold tracking-[-0.4px]">Mi suscripción</h1>
            <p className="text-sm text-slate-500 dark:text-zinc-400">
              Entra a tu portal Operam sin contraseña.
            </p>
          </div>
        </div>

        <AppCard className={cn(PORTAL_CARD_CLASS, 'space-y-5 p-6')}>
          {isExchanging ? (
            <div className="space-y-2 py-6 text-center" role="status" aria-live="polite">
              <p className="font-heading text-base font-semibold">Verificando tu acceso…</p>
              <p className="text-sm text-slate-500 dark:text-zinc-400">
                Un momento, estamos validando tu liga segura.
              </p>
            </div>
          ) : sent ? (
            <div className="space-y-4 text-center" role="status" aria-live="polite">
              <span className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-500/12 text-emerald-500">
                <MailCheck className="h-6 w-6" aria-hidden />
              </span>
              <div className="space-y-1">
                <p className="font-heading text-base font-semibold">Revisa tu correo</p>
                <p className="text-sm text-slate-500 dark:text-zinc-400">{sent.message}</p>
              </div>
              {sent.sandbox_preview_url ? (
                <AppButton
                  type="button"
                  variant="outline"
                  className="h-11 w-full rounded-2xl"
                  onClick={() => openSandboxLink(sent.sandbox_preview_url as string)}
                >
                  <FlaskConical className="h-4 w-4" /> Abrir liga de prueba (Sandbox)
                </AppButton>
              ) : null}
              <AppButton
                type="button"
                variant="ghost"
                className="h-11 w-full rounded-2xl"
                onClick={() => setSent(null)}
              >
                Usar otro correo
              </AppButton>
            </div>
          ) : (
            <form className="space-y-4" onSubmit={form.handleSubmit(onSubmit)} noValidate>
              {linkError ? (
                <p
                  role="alert"
                  className="rounded-2xl border border-red-500/25 bg-red-500/10 px-3.5 py-3 text-sm text-red-600 dark:text-red-400"
                >
                  {linkError}
                </p>
              ) : null}

              <label className="block text-sm font-medium">
                Correo electrónico
                <AppInput
                  type="email"
                  autoComplete="email"
                  inputMode="email"
                  placeholder="tu@empresa.com"
                  withLabelSpacing
                  error={Boolean(form.formState.errors.email)}
                  className="h-12 rounded-2xl text-base"
                  {...form.register('email')}
                />
                {form.formState.errors.email ? (
                  <span className="mt-1.5 block text-xs text-red-500">
                    {form.formState.errors.email.message}
                  </span>
                ) : null}
              </label>

              <AppButton
                type="submit"
                isLoading={isSending}
                className="h-12 w-full rounded-2xl text-base"
              >
                Recibir liga de acceso
              </AppButton>
              <p className="text-center text-xs text-slate-500 dark:text-zinc-500">
                Te enviaremos una liga de un solo uso, válida por 15 minutos.
              </p>
            </form>
          )}
        </AppCard>
      </div>
    </div>
  )
}
