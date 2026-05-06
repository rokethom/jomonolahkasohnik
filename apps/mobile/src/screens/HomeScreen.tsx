import { useEffect, useMemo, useState } from 'react'
import { Alert, FlatList, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native'
import MapView, { Marker, PROVIDER_GOOGLE } from 'react-native-maps'
import * as Location from 'expo-location'
import type { BottomTabScreenProps } from '@react-navigation/bottom-tabs'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { Field } from '@/components/Field'
import { GradientScreen } from '@/components/GradientScreen'
import { OfflineBanner } from '@/components/OfflineBanner'
import { quoteOrder, searchAddress } from '@/services/api'
import { useAppStore } from '@/store/useAppStore'
import { colors } from '@/theme/colors'
import { formatKm, formatRupiah } from '@/theme/format'
import type { Address, MainTabParamList, ServiceType } from '@/types'

type Props = BottomTabScreenProps<MainTabParamList, 'Home'>

const services: Array<{ id: ServiceType; name: string; icon: string }> = [
  { id: 'ojek', name: 'Ojek', icon: 'OJ' },
  { id: 'delivery', name: 'Delivery', icon: 'DL' },
  { id: 'kurir', name: 'Kurir', icon: 'KR' },
  { id: 'travel', name: 'Travel', icon: 'TR' },
  { id: 'gift', name: 'Gift', icon: 'GF' },
  { id: 'belanja', name: 'Belanja', icon: 'BL' },
]

function distanceKm(from?: Address | null, to?: Address | null) {
  if (!from || !to) return 0
  const radius = 6371
  const dLat = ((to.latitude - from.latitude) * Math.PI) / 180
  const dLng = ((to.longitude - from.longitude) * Math.PI) / 180
  const lat1 = (from.latitude * Math.PI) / 180
  const lat2 = (to.latitude * Math.PI) / 180
  const angle = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2
  return radius * 2 * Math.atan2(Math.sqrt(angle), Math.sqrt(1 - angle))
}

export function HomeScreen({ navigation }: Props) {
  const store = useAppStore()
  const [pickupQuery, setPickupQuery] = useState('')
  const [destinationQuery, setDestinationQuery] = useState('')
  const [results, setResults] = useState<Address[]>([])
  const distance = useMemo(() => distanceKm(store.pickup, store.destination), [store.pickup, store.destination])

  useEffect(() => {
    if (!store.pickup || !store.destination) return
    const timer = setTimeout(async () => {
      try {
        const quote = await quoteOrder({
          service_type: store.service,
          pickup_address: store.pickup?.label ?? '',
          pickup_lat: store.pickup?.latitude ?? 0,
          pickup_lng: store.pickup?.longitude ?? 0,
          destination_address: store.destination?.label ?? '',
          destination_lat: store.destination?.latitude ?? 0,
          destination_lng: store.destination?.longitude ?? 0,
          stops: Math.max(1, store.stops.length + 1),
          notes: store.notes,
        })
        store.setQuote(quote)
      } catch {
        store.setQuote({
          distance_km: distance,
          price: Math.ceil(distance * 3500),
          service_fee: 1000,
          keyword_charge: 0,
          service_charge: 1000,
          total_price: Math.ceil(Math.max(10000, distance * 3500 + 1000) / 1000) * 1000,
        })
      }
    }, 500)

    return () => clearTimeout(timer)
  }, [distance, store.pickup, store.destination, store.service, store.stops.length, store.notes])

  const useCurrentLocation = async () => {
    const permission = await Location.requestForegroundPermissionsAsync()
    if (permission.status !== 'granted') {
      Alert.alert('Jojo App', 'Izin lokasi dibutuhkan untuk pickup.')
      return
    }
    const position = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High })
    store.setPickup({
      label: 'Lokasi saya',
      latitude: position.coords.latitude,
      longitude: position.coords.longitude,
    })
  }

  const doSearch = async (query: string, target: 'pickup' | 'destination') => {
    if (target === 'pickup') setPickupQuery(query)
    if (target === 'destination') setDestinationQuery(query)
    setResults(await searchAddress(query))
  }

  const center = store.pickup ?? {
    label: 'Bandung',
    latitude: -6.9219,
    longitude: 107.6071,
  }

  return (
    <GradientScreen>
      <OfflineBanner />
      <ScrollView showsVerticalScrollIndicator={false} contentContainerStyle={styles.content}>
        <View style={styles.header}>
          <Text style={styles.hello}>Halo, {store.user?.name ?? 'Customer'}</Text>
          <Text style={styles.title}>Mau jalan atau kirim apa hari ini?</Text>
        </View>

        <Card padded={false}>
          <MapView
            provider={PROVIDER_GOOGLE}
            style={styles.map}
            region={{
              latitude: center.latitude,
              longitude: center.longitude,
              latitudeDelta: 0.03,
              longitudeDelta: 0.03,
            }}
            showsUserLocation
          >
            {store.pickup && <Marker coordinate={store.pickup} title="Pickup" pinColor={colors.green} />}
            {store.destination && <Marker coordinate={store.destination} title="Destination" pinColor={colors.blue} />}
          </MapView>
        </Card>

        <Card>
          <View style={styles.form}>
            <Field label="Pickup" value={pickupQuery || store.pickup?.label || ''} onChangeText={(value) => doSearch(value, 'pickup')} placeholder="Cari pickup" />
            <Field
              label="Destination"
              value={destinationQuery || store.destination?.label || ''}
              onChangeText={(value) => doSearch(value, 'destination')}
              placeholder="Cari tujuan"
            />
            {results.length > 0 && (
              <FlatList
                data={results}
                keyExtractor={(item) => `${item.latitude}-${item.longitude}-${item.label}`}
                renderItem={({ item }) => (
                  <Pressable
                    style={styles.result}
                    onPress={() => {
                      if (pickupQuery && !store.pickup) store.setPickup(item)
                      else store.setDestination(item)
                      setResults([])
                    }}
                  >
                    <Text style={styles.resultText}>{item.label}</Text>
                  </Pressable>
                )}
              />
            )}
            <Button variant="ghost" onPress={useCurrentLocation}>
              Gunakan lokasi saya
            </Button>
          </View>
        </Card>

        <View style={styles.serviceGrid}>
          {services.map((service) => (
            <Pressable
              key={service.id}
              onPress={() => store.setService(service.id)}
              style={({ pressed }) => [styles.service, store.service === service.id && styles.serviceActive, pressed && styles.pressed]}
            >
              <Text style={styles.serviceIcon}>{service.icon}</Text>
              <Text style={styles.serviceName}>{service.name}</Text>
            </Pressable>
          ))}
        </View>

        <Card>
          <View style={styles.quoteRow}>
            <Text style={styles.muted}>Jarak</Text>
            <Text style={styles.value}>{formatKm(store.quote?.distance_km ?? distance)}</Text>
          </View>
          <View style={styles.quoteRow}>
            <Text style={styles.muted}>Total</Text>
            <Text style={styles.total}>{formatRupiah(store.quote?.total_price)}</Text>
          </View>
          <Button disabled={!store.pickup || !store.destination} onPress={() => navigation.navigate('Order')}>
            Lanjut order
          </Button>
        </Card>
      </ScrollView>
    </GradientScreen>
  )
}

const styles = StyleSheet.create({
  content: {
    gap: 16,
    paddingBottom: 24,
  },
  header: {
    gap: 5,
  },
  hello: {
    color: colors.muted,
    fontWeight: '800',
  },
  title: {
    color: colors.text,
    fontSize: 30,
    lineHeight: 36,
    fontWeight: '900',
  },
  map: {
    height: 300,
  },
  form: {
    gap: 12,
  },
  result: {
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
    paddingVertical: 10,
  },
  resultText: {
    color: colors.text,
  },
  serviceGrid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 10,
  },
  service: {
    width: '31.8%',
    minHeight: 96,
    justifyContent: 'space-between',
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 22,
    padding: 12,
    backgroundColor: colors.card,
  },
  serviceActive: {
    borderColor: colors.green,
    backgroundColor: 'rgba(56,255,179,0.16)',
  },
  pressed: {
    transform: [{ scale: 0.97 }],
  },
  serviceIcon: {
    color: '#06110D',
    alignSelf: 'flex-start',
    overflow: 'hidden',
    borderRadius: 14,
    paddingHorizontal: 9,
    paddingVertical: 8,
    backgroundColor: colors.green,
    fontWeight: '900',
  },
  serviceName: {
    color: colors.text,
    fontWeight: '900',
  },
  quoteRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginBottom: 12,
  },
  muted: {
    color: colors.muted,
  },
  value: {
    color: colors.text,
    fontWeight: '900',
  },
  total: {
    color: colors.green,
    fontSize: 22,
    fontWeight: '900',
  },
})
