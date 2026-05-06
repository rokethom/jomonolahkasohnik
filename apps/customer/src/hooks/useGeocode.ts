import { useEffect, useState } from 'react'
import { geocodeAddress, getApiErrorMessage, type GeocodePayload } from '../services/api'
import type { GeocodeResult } from '../types'

export function useGeocode(payload: GeocodePayload | null, delay = 500) {
  const [result, setResult] = useState<GeocodeResult | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    if (!payload || payload.address.trim().length < 3) {
      setResult(null)
      setError('')
      setLoading(false)
      return
    }

    let disposed = false
    const timer = window.setTimeout(async () => {
      setLoading(true)
      setError('')
      try {
        const next = await geocodeAddress(payload)
        if (!disposed) setResult(next)
      } catch (error) {
        if (!disposed) {
          setResult(null)
          setError(getApiErrorMessage(error, 'Alamat tidak ditemukan'))
        }
      } finally {
        if (!disposed) setLoading(false)
      }
    }, delay)

    return () => {
      disposed = true
      window.clearTimeout(timer)
    }
  }, [payload, delay])

  return { result, loading, error, valid: Boolean(result?.destination) }
}
