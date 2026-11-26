/**
 * Fondo ambiental del portal: `#F5F7FB` con resplandor radial (claro) y `#000000` con halo azul
 * sutil (OLED). Va dentro de un contenedor con fondo propio; no intercepta eventos.
 */
export function PortalAmbientBackground() {
  return (
    <div aria-hidden className="pointer-events-none fixed inset-0 overflow-hidden">
      <div className="absolute -top-44 left-1/2 h-[540px] w-[860px] -translate-x-1/2 rounded-full bg-[radial-gradient(closest-side,rgba(0,102,255,0.16),transparent)] blur-2xl dark:bg-[radial-gradient(closest-side,rgba(10,132,255,0.18),transparent)]" />
      <div className="absolute -bottom-52 -right-32 h-[440px] w-[440px] rounded-full bg-[radial-gradient(closest-side,rgba(122,63,196,0.11),transparent)] blur-2xl dark:bg-[radial-gradient(closest-side,rgba(122,63,196,0.14),transparent)]" />
    </div>
  )
}
