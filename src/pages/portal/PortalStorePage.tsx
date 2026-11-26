import { CreditCard, Gift, Landmark, Package, Sparkles, Store } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { PortalSpeiModal } from '@/components/portal/PortalSpeiModal'
import {
  PortalEmptyState,
  PortalErrorState,
  PortalScreenSkeleton,
  PortalSkeletonBlock,
} from '@/components/portal/PortalStates'
import {
  PORTAL_CARD_CLASS,
  PORTAL_HERO_DUE_CLASS,
  PORTAL_OPTION_SELECTED_CLASS,
  PORTAL_TOUCH_TARGET_CLASS,
} from '@/components/portal/portalTokens'
import { AppBadge } from '@/components/ui/AppBadge'
import { AppButton } from '@/components/ui/AppButton'
import { AppCard, AppCardTitle } from '@/components/ui/AppCard'
import { AppInput } from '@/components/ui/AppInput'
import { AppSelect } from '@/components/ui/AppSelect'
import { useToast } from '@/context/ToastContext'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { compareMoney } from '@/lib/moneyCents'
import { usePortalCheckout, usePortalOverview, usePortalStore, usePortalStoreQuote } from '@/hooks/usePortalData'
import {
  portalMoney,
  portalPercent,
  portalUnits,
  serviceUnitLabel,
} from '@/lib/portalLabels'
import { cn } from '@/lib/utils'
import { portalStoreQuantitySchema } from '@/lib/validationSchemas'
import type {
  PortalCheckoutResult,
  PortalStoreItem,
  PortalStorePaymentMethod,
  PortalStoreQuoteQuery,
} from '@/types/portal'

/** Cantidades sugeridas: bolsas de consumo (folios/timbres) vs. compras únicas. */
const CONSUMPTION_CHIPS = [100, 500, 1000, 2500]
const SINGLE_CHIPS = [1, 5, 10, 25]

const FIELD_LABEL =
  'text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500 dark:text-zinc-400'

function chipsFor(item: PortalStoreItem | null): number[] {
  return item?.service_type === 'single' ? SINGLE_CHIPS : CONSUMPTION_CHIPS
}

/** Mejor promoción alcanzable de un producto (mayor % de unidades extra). */
function bestPromotion(item: PortalStoreItem) {
  return [...item.promotions].sort((a, b) => compareMoney(b.extra_units_pct, a.extra_units_pct))[0] ?? null
}

/**
 * Tienda del portal (RF-2.8): bolsas de consumo y servicios únicos con promociones de
 * unidades extra. El precio, IVA y regalo SIEMPRE los cotiza el servidor; el pago se hace con
 * tarjeta (IntegroPAY) o por transferencia SPEI con la factura emitida al instante.
 */
export function PortalStorePage() {
  const toast = useToast()
  const navigate = useNavigate()
  const overview = usePortalOverview()
  const [contractId, setContractId] = useState<number | null>(null)
  const store = usePortalStore(contractId)
  const checkout = usePortalCheckout()

  const [serviceId, setServiceId] = useState<number | null>(null)
  const [quantityText, setQuantityText] = useState('1000')
  const [speiResult, setSpeiResult] = useState<PortalCheckoutResult | null>(null)

  const catalog = store.data
  const activeContractId = contractId ?? catalog?.contract_id ?? null
  const item = catalog?.items.find((entry) => entry.id === serviceId) ?? catalog?.items[0] ?? null
  const contract = overview.data?.contracts.find((entry) => entry.id === activeContractId) ?? null

  const parsedQuantity = portalStoreQuantitySchema.safeParse(quantityText)
  const quantityError = quantityText !== '' && !parsedQuantity.success
    ? parsedQuantity.error.issues[0]?.message
    : undefined

  const quoteInput = useMemo<PortalStoreQuoteQuery | null>(
    () =>
      activeContractId && item && parsedQuantity.success
        ? { contract_id: activeContractId, service_id: item.id, quantity: Number(parsedQuantity.data) }
        : null,
    [activeContractId, item, parsedQuantity.success, parsedQuantity.data],
  )
  const debouncedQuote = useDebouncedValue(quoteInput, 350)
  const quote = usePortalStoreQuote(debouncedQuote)
  const quoteIsFresh =
    quote.data !== undefined &&
    quoteInput !== null &&
    quote.data.service_id === quoteInput.service_id &&
    quote.data.quantity === quoteInput.quantity &&
    quote.data.contract_id === quoteInput.contract_id

  function selectItem(next: PortalStoreItem) {
    setServiceId(next.id)
    if (next.service_type !== item?.service_type) {
      setQuantityText(String(chipsFor(next)[next.service_type === 'single' ? 0 : 2]))
    }
  }

  async function onCheckout(paymentMethod: PortalStorePaymentMethod) {
    if (!quoteInput) return
    try {
      const result = await checkout.mutateAsync({ ...quoteInput, payment_method: paymentMethod })
      if (paymentMethod === 'spei') {
        toast.success('Compra registrada', 'Realiza tu transferencia SPEI con la referencia indicada.')
        setSpeiResult(result)
        return
      }
      if (!result.payment_link) return
      toast.success(
        'Liga de pago generada',
        result.payment_link.is_sandbox
          ? 'Modo sandbox: simula el pago con tarjeta en un toque.'
          : 'Te llevamos a la pasarela segura de IntegroPAY.',
      )
      if (result.payment_link.is_sandbox) {
        navigate(`/portal/sandbox-pay/${encodeURIComponent(result.payment_link.external_link_id)}`)
      } else {
        window.location.assign(result.payment_link.url)
      }
    } catch (error) {
      toast.apiError(error)
    }
  }

  if (store.isLoading) return <PortalScreenSkeleton rows={4} />
  if (store.isError || !catalog) {
    return (
      <PortalErrorState
        message={store.error instanceof Error ? store.error.message : 'Intenta de nuevo en unos segundos.'}
        onRetry={() => void store.refetch()}
      />
    )
  }

  const headlinePromotion = catalog.active_promotions[0] ?? null
  const itemPromotion = item ? bestPromotion(item) : null
  const promoShortfall =
    itemPromotion?.min_quantity && quoteInput && quoteInput.quantity < itemPromotion.min_quantity
      ? itemPromotion.min_quantity - quoteInput.quantity
      : null
  const canCheckout = quoteIsFresh && !quote.isError && activeContractId !== null
  const speiReady = contract?.spei.is_configured ?? false

  return (
    <div className="space-y-5">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="font-heading text-2xl font-bold tracking-[-0.4px] sm:text-3xl">Tienda</h1>
          <p className="mt-0.5 text-sm text-slate-500 dark:text-zinc-400">
            Recarga folios y suma servicios a tu suscripción al instante.
          </p>
        </div>
        {catalog.contracts.length > 1 ? (
          <div className="w-full sm:w-72">
            <AppSelect
              aria-label="Contrato para la compra"
              value={activeContractId ? String(activeContractId) : undefined}
              onValueChange={(value) => setContractId(Number(value))}
              triggerClassName="h-11 rounded-full"
              options={catalog.contracts.map((entry) => ({ value: String(entry.id), label: entry.folio }))}
            />
          </div>
        ) : null}
      </header>

      {headlinePromotion ? (
        <AppCard className={cn(PORTAL_CARD_CLASS, PORTAL_HERO_DUE_CLASS, 'flex items-center gap-4 border-0 p-5')}>
          <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-white/20">
            <Gift className="h-6 w-6" aria-hidden />
          </span>
          <div className="min-w-0">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-white/80">Promoción activa</p>
            <p className="font-heading text-base font-semibold sm:text-lg">
              +{portalPercent(headlinePromotion.extra_units_pct)}% {headlinePromotion.service_name.toLowerCase()} gratis
              {headlinePromotion.min_quantity
                ? ` al comprar desde ${portalUnits(headlinePromotion.min_quantity)} unidades`
                : ' en cada compra'}
            </p>
          </div>
        </AppCard>
      ) : null}

      {catalog.items.length === 0 || activeContractId === null ? (
        <PortalEmptyState
          icon={Store}
          title={activeContractId === null ? 'Necesitas un contrato activo' : 'Aún no hay productos en la tienda'}
          description={
            activeContractId === null
              ? 'Cuando tu suscripción esté activa podrás comprar desde aquí.'
              : 'Tu ejecutivo de cuenta puede ayudarte a ampliar tu plan mientras publicamos el catálogo.'
          }
        />
      ) : (
        <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
          <div className="grid gap-4 sm:grid-cols-2">
            {catalog.items.map((entry) => {
              const selected = entry.id === item?.id
              const promotion = bestPromotion(entry)
              return (
                <AppButton
                  key={entry.id}
                  type="button"
                  variant="outline"
                  aria-pressed={selected}
                  onClick={() => selectItem(entry)}
                  className={cn(
                    PORTAL_CARD_CLASS,
                    'h-auto min-h-[160px] flex-col items-stretch justify-start gap-3 whitespace-normal bg-white/80 p-5 text-left font-normal dark:bg-white/[0.03]',
                    selected && PORTAL_OPTION_SELECTED_CLASS,
                  )}
                >
                  <span className="flex items-start justify-between gap-3">
                    <span className="flex h-11 w-11 items-center justify-center rounded-full bg-[#0066FF]/10 text-[#0066FF] dark:bg-[#0A84FF]/15 dark:text-[#0A84FF]">
                      {entry.service_type === 'consumption' ? (
                        <Package className="h-5 w-5" aria-hidden />
                      ) : (
                        <Sparkles className="h-5 w-5" aria-hidden />
                      )}
                    </span>
                    {promotion ? (
                      <AppBadge
                        tone="success"
                        className="whitespace-nowrap shrink-0"
                        label={`+${portalPercent(promotion.extra_units_pct)}% gratis`}
                      />
                    ) : null}
                  </span>
                  <span className="min-w-0 space-y-1">
                    <span className="block font-heading text-base font-semibold">{entry.name}</span>
                    {entry.description ? (
                      <span className="line-clamp-2 block text-sm text-slate-500 dark:text-zinc-400">
                        {entry.description}
                      </span>
                    ) : null}
                  </span>
                  <span className="mt-auto flex flex-wrap items-center justify-between gap-2">
                    <AppBadge label={serviceUnitLabel(entry.unit_of_measure)} tone="info" className="whitespace-nowrap shrink-0" />
                    <span className="text-xs text-slate-500 dark:text-zinc-400">
                      Desde <span className="font-mono tabular-nums">{portalMoney(entry.from_price)}</span>
                    </span>
                  </span>
                </AppButton>
              )
            })}
          </div>

          {item ? (
            <AppCard className={cn(PORTAL_CARD_CLASS, 'space-y-5 p-5 lg:sticky lg:top-24')}>
              <div>
                <AppCardTitle>{item.name}</AppCardTitle>
                <p className="mt-0.5 text-sm text-slate-500 dark:text-zinc-400">Elige cuántas unidades necesitas.</p>
              </div>

              <div className="space-y-2.5">
                <p className={FIELD_LABEL}>Cantidad</p>
                <div className="grid grid-cols-4 gap-2">
                  {chipsFor(item).map((chip) => {
                    const selected = quantityText === String(chip)
                    return (
                      <AppButton
                        key={chip}
                        type="button"
                        variant={selected ? 'primary' : 'outline'}
                        aria-pressed={selected}
                        onClick={() => setQuantityText(String(chip))}
                        className={cn(PORTAL_TOUCH_TARGET_CLASS, 'h-11 rounded-full px-2 font-mono tabular-nums')}
                      >
                        {portalUnits(chip)}
                      </AppButton>
                    )
                  })}
                </div>
                <AppInput
                  aria-label="Otra cantidad"
                  mono
                  numericMode="integer"
                  autoComplete="off"
                  placeholder="Otra cantidad"
                  value={quantityText}
                  onChange={(event) => setQuantityText(event.target.value)}
                  error={Boolean(quantityError)}
                  className="h-12 rounded-2xl text-lg"
                />
                {quantityError ? <p className="text-xs text-red-500">{quantityError}</p> : null}
                {promoShortfall && itemPromotion ? (
                  <p className="text-xs text-slate-500 dark:text-zinc-400">
                    Agrega <span className="font-mono tabular-nums">{portalUnits(promoShortfall)}</span> más y
                    recibe +{portalPercent(itemPromotion.extra_units_pct)}% gratis.
                  </p>
                ) : null}
              </div>

              <div className="space-y-2 rounded-2xl border border-slate-200/80 bg-slate-50/70 p-4 dark:border-white/[0.08] dark:bg-white/[0.04]">
                {quote.isError ? (
                  <p role="alert" className="text-sm text-red-500">
                    {quote.error instanceof Error ? quote.error.message : 'No pudimos cotizar esta cantidad.'}
                  </p>
                ) : quote.data && quoteInput ? (
                  <div className={cn('space-y-2 transition-opacity', !quoteIsFresh && 'opacity-50')}>
                    <QuoteRow label="Precio unitario" value={portalMoney(quote.data.unit_price)} />
                    <QuoteRow label="Subtotal" value={portalMoney(quote.data.subtotal)} />
                    <QuoteRow label="IVA 16%" value={portalMoney(quote.data.tax_amount)} />
                    <div className="flex items-center justify-between border-t border-slate-200/80 pt-2 dark:border-white/[0.08]">
                      <span className="text-sm font-semibold">Total</span>
                      <span className="font-mono text-2xl font-semibold tabular-nums">{portalMoney(quote.data.total)}</span>
                    </div>
                    {quote.data.extra_units > 0 ? (
                      <p className="flex flex-wrap items-center gap-1.5 rounded-full bg-emerald-500/10 px-3 py-1.5 text-sm font-medium text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300">
                        <Gift className="h-4 w-4 shrink-0" aria-hidden />
                        <span className="font-mono tabular-nums">+{portalUnits(quote.data.extra_units)}</span> gratis ={' '}
                        <span className="font-mono tabular-nums">{portalUnits(quote.data.total_units_to_receive)}</span>{' '}
                        unidades totales
                      </p>
                    ) : null}
                  </div>
                ) : (
                  <div className="space-y-2">
                    <PortalSkeletonBlock className="h-4 w-full rounded-lg" />
                    <PortalSkeletonBlock className="h-4 w-3/4 rounded-lg" />
                    <PortalSkeletonBlock className="h-7 w-1/2 rounded-lg" />
                  </div>
                )}
              </div>

              <div className="space-y-2.5">
                <AppButton
                  type="button"
                  className="h-12 w-full rounded-2xl text-base font-semibold"
                  disabled={!canCheckout}
                  isLoading={checkout.isPending && checkout.variables?.payment_method === 'card_integropay'}
                  onClick={() => void onCheckout('card_integropay')}
                >
                  <CreditCard className="h-5 w-5" aria-hidden /> Pagar con tarjeta (IntegroPAY)
                </AppButton>
                <AppButton
                  type="button"
                  variant="outline"
                  className="h-12 w-full rounded-2xl text-base"
                  disabled={!canCheckout || !speiReady}
                  isLoading={checkout.isPending && checkout.variables?.payment_method === 'spei'}
                  onClick={() => void onCheckout('spei')}
                >
                  <Landmark className="h-5 w-5" aria-hidden /> Pagar por transferencia SPEI
                </AppButton>
                {!speiReady && contract ? (
                  <p className="text-center text-xs text-slate-500 dark:text-zinc-400">
                    Tu proveedor aún no publica datos SPEI; usa pago con tarjeta.
                  </p>
                ) : null}
              </div>
            </AppCard>
          ) : null}
        </div>
      )}

      {speiResult?.spei && speiResult.invoice ? (
        <PortalSpeiModal
          open
          onOpenChange={(open) => {
            if (!open) setSpeiResult(null)
          }}
          spei={speiResult.spei}
          contractFolio={speiResult.invoice.folio}
          amount={speiResult.invoice.total}
          reference={speiResult.invoice.folio}
          description={`Emitimos la factura ${speiResult.invoice.folio}. Tus unidades se acreditan en cuanto se concilie tu transferencia.`}
        />
      ) : null}
    </div>
  )
}

function QuoteRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between text-sm">
      <span className="text-slate-500 dark:text-zinc-400">{label}</span>
      <span className="font-mono tabular-nums">{value}</span>
    </div>
  )
}
