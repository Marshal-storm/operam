/**
 * Aritmética de dinero exacta en centavos (`bigint`) para la UI — cero `float` (RNF-1).
 * Entrada/salida siempre como texto decimal `"1234.56"`, igual que el JSON de la API.
 */

const MONEY_PATTERN = /^(-)?(\d+)(?:\.(\d{1,2}))?$/

/** `"1,234.5"` → `123450n`. Valores inválidos se tratan como `0n` (la API valida en servidor). */
export function moneyToCents(value: string | null | undefined): bigint {
  const match = MONEY_PATTERN.exec(String(value ?? '').replace(/[,\s$]/g, ''))
  if (!match) return 0n
  const [, sign, integer, fraction = ''] = match
  const cents = BigInt(integer ?? '0') * 100n + BigInt((fraction + '00').slice(0, 2))
  return sign ? -cents : cents
}

/** `123450n` → `"1234.50"`. */
export function centsToMoney(cents: bigint): string {
  const negative = cents < 0n
  const abs = negative ? -cents : cents
  const integer = abs / 100n
  const fraction = (abs % 100n).toString().padStart(2, '0')
  return `${negative ? '-' : ''}${integer.toString()}.${fraction}`
}

export function sumMoney(values: Array<string | null | undefined>): string {
  return centsToMoney(values.reduce<bigint>((acc, value) => acc + moneyToCents(value), 0n))
}

/** -1 si a < b, 0 si son iguales, 1 si a > b. */
export function compareMoney(a: string, b: string): -1 | 0 | 1 {
  const diff = moneyToCents(a) - moneyToCents(b)
  if (diff === 0n) return 0
  return diff < 0n ? -1 : 1
}

export function minMoney(a: string, b: string): string {
  return compareMoney(a, b) <= 0 ? centsToMoney(moneyToCents(a)) : centsToMoney(moneyToCents(b))
}
