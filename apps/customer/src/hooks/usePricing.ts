import { useEffect, useState } from 'react'
import { calculatePricing, type OrderPayload } from '../services/api'
import type { PriceQuote } from '../types'

export function usePricing(payload: OrderPayload | null, delay = 450) {
  const [quote, setQuote] = useState<PriceQuote | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    if (!payload) {
      return
    }

    let disposed = false
    const timer = window.setTimeout(async () => {
      setLoading(true)
      setError('')
      try {
        const result = await calculatePricing(payload)
        if (!disposed) setQuote(result)
      } catch {
        if (!disposed) setError('Harga belum bisa dihitung. Coba cek koneksi atau alamat.')
      } finally {
        if (!disposed) setLoading(false)
      }
    }, delay)

    return () => {
      disposed = true
      window.clearTimeout(timer)
    }
  }, [payload, delay])

  return {
    quote: payload ? quote : null,
    loading: payload ? loading : false,
    error: payload ? error : '',
  }
}
