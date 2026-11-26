/**
 * Tokens visuales exclusivos del Portal Cliente (identidad iOS / Apple Wallet).
 * Un cambio aquí se propaga a encabezado, dock y tarjetas del portal.
 */

/** Tarjeta de cristal del portal: radio orgánico iOS sobre la base `AppCard`. */
export const PORTAL_CARD_CLASS = 'rounded-[28px]'

/** Superficie flotante de cristal (píldora de escritorio y dock móvil). */
export const PORTAL_GLASS_BAR_CLASS =
  'border border-white/80 bg-white/70 ring-1 ring-slate-900/[0.06] backdrop-blur-2xl backdrop-saturate-150 ' +
  'shadow-[inset_0_1px_0_rgba(255,255,255,0.9),0_14px_36px_-14px_rgba(15,23,42,0.22)] ' +
  'dark:border-white/[0.12] dark:ring-white/[0.04] ' +
  'dark:bg-[linear-gradient(135deg,rgba(36,36,42,0.72)_0%,rgba(16,16,20,0.78)_100%)] ' +
  'dark:shadow-[inset_0_1px_0_rgba(255,255,255,0.14),0_14px_36px_rgba(0,0,0,0.65)]'

/** Tarjeta héroe: degradado azul iOS tipo tarjeta de Wallet (al corriente = verde esmeralda). */
export const PORTAL_HERO_DUE_CLASS =
  'bg-[linear-gradient(145deg,#0A5CFF_0%,#3B7BFF_46%,#7A3FC4_100%)] text-white ' +
  'dark:bg-[linear-gradient(145deg,#0A4FD8_0%,#1F6BFF_46%,#5B2FA8_100%)]'

export const PORTAL_HERO_CURRENT_CLASS =
  'bg-[linear-gradient(145deg,#0E9F6E_0%,#10B981_52%,#2DD4BF_100%)] text-white ' +
  'dark:bg-[linear-gradient(145deg,#0B7A56_0%,#0E9F6E_52%,#14B8A6_100%)]'

/** Objetivo táctil mínimo 44×44 px (UI móvil). */
export const PORTAL_TOUCH_TARGET_CLASS = 'min-h-[44px] min-w-[44px]'

/**
 * Hoja inferior iOS (bottom sheet) sobre `AppModalContent`: anclada abajo en móvil y centrada
 * como tarjeta en escritorio.
 */
export const PORTAL_SHEET_CLASS =
  'bottom-0 top-auto w-full max-w-lg translate-y-0 rounded-b-none rounded-t-[28px] max-h-[92vh] overflow-y-auto ' +
  'pb-[max(1.25rem,env(safe-area-inset-bottom))] ' +
  'sm:bottom-auto sm:top-1/2 sm:w-[calc(100%-2rem)] sm:-translate-y-1/2 sm:rounded-[28px] sm:pb-5'

/** Opción seleccionable tipo celda iOS (≥44 px) para hojas y formularios del portal. */
export const PORTAL_OPTION_CLASS =
  'h-auto min-h-[56px] w-full justify-between gap-3 rounded-2xl px-4 py-3 text-left font-normal text-slate-900 dark:text-zinc-50'

export const PORTAL_OPTION_SELECTED_CLASS =
  'border-[#0066FF] bg-[#0066FF]/[0.06] ring-2 ring-[#0066FF]/25 dark:border-[#0A84FF] dark:bg-[#0A84FF]/[0.12] dark:ring-[#0A84FF]/30'
