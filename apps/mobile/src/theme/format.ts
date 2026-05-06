export function formatRupiah(value?: number) {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(value ?? 0)
}

export function formatKm(value?: number) {
  return `${(value ?? 0).toFixed(2)} km`
}
