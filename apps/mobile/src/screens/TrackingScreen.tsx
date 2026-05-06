import { useEffect, useMemo, useState } from 'react'
import { StyleSheet, Text, View } from 'react-native'
import MapView, { Marker } from 'react-native-maps'
import { Card } from '@/components/Card'
import { GradientScreen } from '@/components/GradientScreen'
import { useAppStore } from '@/store/useAppStore'
import { colors } from '@/theme/colors'

export function TrackingScreen() {
  const activeOrder = useAppStore((state) => state.activeOrder)
  const pickup = useAppStore((state) => state.pickup)
  const destination = useAppStore((state) => state.destination)
  const [driver, setDriver] = useState({
    latitude: pickup?.latitude ? pickup.latitude + 0.004 : -6.919,
    longitude: pickup?.longitude ? pickup.longitude + 0.004 : 107.61,
  })

  useEffect(() => {
    const wsUrl = process.env.EXPO_PUBLIC_WS_URL
    if (!wsUrl) return
    const ws = new WebSocket(wsUrl)
    ws.onmessage = (event) => {
      try {
        const payload = JSON.parse(String(event.data)) as { latitude?: number; longitude?: number }
        if (payload.latitude && payload.longitude) setDriver({ latitude: payload.latitude, longitude: payload.longitude })
      } catch {
        return
      }
    }
    return () => ws.close()
  }, [])

  useEffect(() => {
    if (process.env.EXPO_PUBLIC_WS_URL) return
    const timer = setInterval(() => {
      setDriver((value) => ({
        latitude: value.latitude + 0.00025,
        longitude: value.longitude + 0.00018,
      }))
    }, 2500)
    return () => clearInterval(timer)
  }, [])

  const center = useMemo(() => pickup ?? destination ?? driver, [destination, driver, pickup])

  return (
    <GradientScreen>
      <View style={styles.content}>
        <View>
          <Text style={styles.title}>Tracking driver</Text>
          <Text style={styles.subtitle}>{activeOrder ? activeOrder.order_code ?? `Order #${activeOrder.id}` : 'Belum ada order aktif'}</Text>
        </View>
        <Card padded={false}>
          <MapView
            style={styles.map}
            region={{
              latitude: center.latitude,
              longitude: center.longitude,
              latitudeDelta: 0.04,
              longitudeDelta: 0.04,
            }}
          >
            {pickup && <Marker coordinate={pickup} title="Pickup" pinColor={colors.green} />}
            {destination && <Marker coordinate={destination} title="Destination" pinColor={colors.blue} />}
            <Marker coordinate={driver} title="Driver" pinColor={colors.orange} />
          </MapView>
        </Card>
        <Card>
          <Text style={styles.cardTitle}>Status</Text>
          <Text style={styles.status}>{activeOrder?.status ?? 'Menunggu order aktif'}</Text>
          <Text style={styles.subtitle}>Lokasi driver akan realtime saat WebSocket tersedia.</Text>
        </Card>
      </View>
    </GradientScreen>
  )
}

const styles = StyleSheet.create({
  content: {
    flex: 1,
    gap: 16,
  },
  title: {
    color: colors.text,
    fontSize: 34,
    fontWeight: '900',
  },
  subtitle: {
    color: colors.muted,
  },
  map: {
    height: 460,
  },
  cardTitle: {
    color: colors.text,
    fontSize: 18,
    fontWeight: '900',
  },
  status: {
    marginVertical: 8,
    color: colors.green,
    fontSize: 26,
    fontWeight: '900',
    textTransform: 'uppercase',
  },
})
