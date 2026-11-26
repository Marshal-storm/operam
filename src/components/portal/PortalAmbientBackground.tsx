/**
 * Fondo ambiental del portal: `#F5F7FB` con resplandor radial (claro) y `#000000` con halo azul
 * sutil superior (OLED) para que la píldora del Header refracte luz real incluso antes del scroll.
 * Va dentro de un contenedor con fondo propio; no intercepta eventos.
 */
export function PortalAmbientBackground() {
  return (
    <div aria-hidden className="pointer-events-none fixed inset-0 z-0 overflow-hidden">
      {/* Halo ambiental superior sutil para refracción inmediata del Header flotante */}
      <div className="absolute inset-0 bg-[radial-gradient(ellipse_70%_35%_at_50%_-5%,rgba(37,99,235,0.12),transparent_65%)] dark:bg-[radial-gradient(ellipse_70%_35%_at_50%_-5%,rgba(37,99,235,0.22),transparent_65%)]" />
      <div className="absolute -top-44 left-1/2 h-[540px] w-[860px] -translate-x-1/2 rounded-full bg-[radial-gradient(closest-side,rgba(0,102,255,0.12),transparent)] blur-2xl dark:bg-[radial-gradient(closest-side,rgba(10,132,255,0.14),transparent)]" />
      <div className="absolute -bottom-52 -right-32 h-[440px] w-[440px] rounded-full bg-[radial-gradient(closest-side,rgba(122,63,196,0.10),transparent)] blur-2xl dark:bg-[radial-gradient(closest-side,rgba(122,63,196,0.14),transparent)]" />
    </div>
  )
}
