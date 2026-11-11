/**
 * Referencia de tipografía Operam (Tailwind v4 usa @theme en src/index.css).
 * Este archivo documenta la familia oficial para módulos futuros.
 */
/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Ubuntu', 'DM Sans', 'system-ui', 'sans-serif'],
        heading: ['Ubuntu', 'DM Sans', 'system-ui', 'sans-serif'],
        mono: ['DM Mono', 'ui-monospace', 'monospace'],
      },
    },
  },
  plugins: [],
}
