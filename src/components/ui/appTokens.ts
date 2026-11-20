/**
 * Tokens visuales del Kit Atómico Operam (Single Source of Truth).
 * Cambiar borde/fondo/focus/sombra aquí actualiza todos los módulos.
 */

/** Campo de formulario Liquid Glass (input / trigger de select). */
export const APP_FIELD_CLASS =
  'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 outline-none transition ' +
  'placeholder:text-slate-400 focus:ring-2 focus:ring-[#0066FF]/30 ' +
  'disabled:cursor-not-allowed disabled:opacity-50 ' +
  'dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-zinc-100 dark:placeholder:text-zinc-500 dark:focus:ring-[#0A84FF]/35'

/** Variante con margen superior típico bajo un label. */
export const APP_FIELD_MT_CLASS = `mt-1 ${APP_FIELD_CLASS}`

/** Estado de error en campo. */
export const APP_FIELD_ERROR_CLASS =
  'border-red-400 focus:ring-red-400/30 dark:border-red-500/50 dark:focus:ring-red-500/30'

/** Contenedor tarjeta Liquid Glass. */
export const APP_CARD_CLASS =
  'rounded-2xl border border-slate-200/80 bg-white ' +
  'shadow-[0_8px_24px_rgba(15,23,42,0.06)] ' +
  'dark:border-white/[0.09] dark:bg-[#111113]/75 dark:backdrop-blur-2xl ' +
  'dark:shadow-[inset_0_1px_0_0_rgba(255,255,255,0.12),0_12px_32px_rgba(0,0,0,0.6)]'

/** Menú flotante (Select / popover) Liquid Glass. */
export const APP_POPOVER_CLASS =
  'z-[80] overflow-hidden rounded-xl border border-slate-200/80 bg-white/95 shadow-2xl backdrop-blur-2xl ' +
  'dark:border-white/[0.09] dark:bg-[#111113]/95 ' +
  'dark:shadow-[inset_0_1px_0_0_rgba(255,255,255,0.12),0_16px_40px_rgba(0,0,0,0.65)]'
