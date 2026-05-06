import { useState } from 'react'
import { Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native'
import MapView, { Marker, type MapPressEvent } from 'react-native-maps'
import type { BottomTabScreenProps } from '@react-navigation/bottom-tabs'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { Field } from '@/components/Field'
import { GradientScreen } from '@/components/GradientScreen'
import { createOrder, getApiErrorMessage } from '@/services/api'
import { useAppStore } from '@/store/useAppStore'
import { colors } from '@/theme/colors'
import { formatRupiah } from '@/theme/format'
import type { MainTabParamList } from '@/types'

type Props = BottomTabScreenProps<MainTabParamList, 'Order'>

export function OrderScreen({ navigation }: Props) {
  const store = useAppStore()
  const [step, setStep] = useState(1)
  const [mapTarget, setMapTarget] = useState<'pickup' | 'destination' | 'stop'>('pickup')
  const [loading, setLoading] = useState(false)

  const onMapPress = (event: MapPressEvent) => {
    const address = {
      label: `Pin ${event.nativeEvent.coordinate.latitude.toFixed(5)}, ${event.nativeEvent.coordinate.longitude.toFixed(5)}`,
      latitude: event.nativeEvent.coordinate.latitude,
      longitude: event.nativeEvent.coordinate.longitude,
    }
    if (mapTarget === 'pickup') store.setPickup(address)
    if (mapTarget === 'destination') store.setDestination(address)
    if (mapTarget === 'stop') store.addStop(address)
  }

  const submit = async () => {
    if (!store.pickup || !store.destination) {
      Alert.alert('Jojo App', 'Lengkapi pickup dan destination.')
      return
    }

    setLoading(true)
    try {
      const order = await createOrder({
        service_type: store.service,
        pickup_address: store.pickup.label,
        pickup_lat: store.pickup.latitude,
        pickup_lng: store.pickup.longitude,
        destination_address: store.destination.label,
        destination_lat: store.destination.latitude,
        destination_lng: store.destination.longitude,
        stops: Math.max(1, store.stops.length + 1),
        notes: store.notes,
      })
      store.addOrder(order)
      store.addMessage({
        id: String(Date.now()),
        from: 'system',
        text: `Order ${order.order_code ?? order.code ?? `#${order.id}`} berhasil dibuat.`,
        time: new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }),
      })
      navigation.navigate('Tracking')
    } catch (error) {
      Alert.alert('Jojo App', getApiErrorMessage(error, 'Order gagal dibuat'))
    } finally {
      setLoading(false)
    }
  }

  const center = store.pickup ?? { label: 'Bandung', latitude: -6.9219, longitude: 107.6071 }

  return (
    <GradientScreen>
      <ScrollView contentContainerStyle={styles.content} showsVerticalScrollIndicator={false}>
        <View>
          <Text style={styles.title}>Order Jojo</Text>
          <Text style={styles.subtitle}>Step {step} dari 4</Text>
        </View>

        <View style={styles.stepper}>
          {[1, 2, 3, 4].map((item) => (
            <Pressable key={item} onPress={() => setStep(item)} style={[styles.stepDot, item <= step && styles.stepDotActive]}>
              <Text style={styles.stepText}>{item}</Text>
            </Pressable>
          ))}
        </View>

        {step === 1 && (
          <Card>
            <Text style={styles.cardTitle}>Layanan aktif</Text>
            <Text style={styles.big}>{store.service.toUpperCase()}</Text>
            <Text style={styles.subtitle}>Pilih layanan dari Home, lalu kembali ke order untuk lanjut.</Text>
          </Card>
        )}

        {step === 2 && (
          <Card>
            <Text style={styles.cardTitle}>Map picker</Text>
            <View style={styles.segment}>
              {(['pickup', 'destination', 'stop'] as const).map((target) => (
                <Pressable key={target} onPress={() => setMapTarget(target)} style={[styles.segmentButton, mapTarget === target && styles.segmentActive]}>
                  <Text style={styles.segmentText}>{target}</Text>
                </Pressable>
              ))}
            </View>
            <MapView
              style={styles.map}
              region={{
                latitude: center.latitude,
                longitude: center.longitude,
                latitudeDelta: 0.04,
                longitudeDelta: 0.04,
              }}
              onPress={onMapPress}
            >
              {store.pickup && <Marker coordinate={store.pickup} title="Pickup" pinColor={colors.green} />}
              {store.destination && <Marker coordinate={store.destination} title="Destination" pinColor={colors.blue} />}
              {store.stops.map((stop, index) => (
                <Marker key={`${stop.latitude}-${stop.longitude}-${index}`} coordinate={stop} title={`Stop ${index + 1}`} pinColor={colors.orange} />
              ))}
            </MapView>
          </Card>
        )}

        {step === 3 && (
          <Card>
            <Text style={styles.cardTitle}>Detail order</Text>
            <View style={styles.form}>
              <Field
                label="Catatan"
                value={store.notes}
                multiline
                style={styles.notes}
                onChangeText={store.setNotes}
                placeholder="Contoh: beli di Gacoan, antar ke lobby RS"
              />
              <Text style={styles.subtitle}>Stop tambahan: {store.stops.length}</Text>
              {store.stops.map((stop, index) => (
                <Pressable key={`${stop.label}-${index}`} style={styles.stopRow} onPress={() => store.removeStop(index)}>
                  <Text style={styles.stopText}>Stop {index + 1}</Text>
                  <Text style={styles.stopLabel}>{stop.label}</Text>
                </Pressable>
              ))}
            </View>
          </Card>
        )}

        {step === 4 && (
          <Card>
            <Text style={styles.cardTitle}>Preview harga</Text>
            <View style={styles.priceRow}>
              <Text style={styles.subtitle}>Tarif</Text>
              <Text style={styles.value}>{formatRupiah(store.quote?.price)}</Text>
            </View>
            <View style={styles.priceRow}>
              <Text style={styles.subtitle}>Service fee</Text>
              <Text style={styles.value}>{formatRupiah(store.quote?.service_fee ?? store.quote?.service_charge)}</Text>
            </View>
            <View style={styles.priceRow}>
              <Text style={styles.subtitle}>Keyword charge</Text>
              <Text style={styles.value}>{formatRupiah(store.quote?.keyword_charge)}</Text>
            </View>
            <View style={styles.priceRow}>
              <Text style={styles.cardTitle}>Total</Text>
              <Text style={styles.total}>{formatRupiah(store.quote?.total_price)}</Text>
            </View>
            <Button loading={loading} onPress={submit}>
              Buat order sekarang
            </Button>
          </Card>
        )}

        <View style={styles.actions}>
          <Button variant="ghost" disabled={step === 1} onPress={() => setStep((value) => Math.max(1, value - 1))}>
            Kembali
          </Button>
          {step < 4 && <Button onPress={() => setStep((value) => Math.min(4, value + 1))}>Lanjut</Button>}
        </View>
      </ScrollView>
    </GradientScreen>
  )
}

const styles = StyleSheet.create({
  content: {
    gap: 16,
    paddingBottom: 24,
  },
  title: {
    color: colors.text,
    fontSize: 34,
    fontWeight: '900',
  },
  subtitle: {
    color: colors.muted,
  },
  stepper: {
    flexDirection: 'row',
    gap: 10,
  },
  stepDot: {
    width: 42,
    height: 42,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 999,
    backgroundColor: colors.card,
  },
  stepDotActive: {
    backgroundColor: colors.green,
  },
  stepText: {
    color: '#06110D',
    fontWeight: '900',
  },
  cardTitle: {
    color: colors.text,
    fontSize: 18,
    fontWeight: '900',
  },
  big: {
    marginVertical: 10,
    color: colors.green,
    fontSize: 40,
    fontWeight: '900',
  },
  segment: {
    flexDirection: 'row',
    gap: 8,
    marginVertical: 12,
  },
  segmentButton: {
    flex: 1,
    alignItems: 'center',
    borderRadius: 14,
    paddingVertical: 10,
    backgroundColor: colors.card,
  },
  segmentActive: {
    backgroundColor: 'rgba(56,255,179,0.22)',
  },
  segmentText: {
    color: colors.text,
    fontWeight: '800',
  },
  map: {
    height: 330,
    borderRadius: 22,
  },
  form: {
    gap: 12,
  },
  notes: {
    minHeight: 120,
    textAlignVertical: 'top',
  },
  stopRow: {
    borderRadius: 16,
    padding: 12,
    backgroundColor: colors.card,
  },
  stopText: {
    color: colors.text,
    fontWeight: '900',
  },
  stopLabel: {
    marginTop: 4,
    color: colors.muted,
  },
  priceRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginBottom: 13,
  },
  value: {
    color: colors.text,
    fontWeight: '900',
  },
  total: {
    color: colors.green,
    fontSize: 24,
    fontWeight: '900',
  },
  actions: {
    flexDirection: 'row',
    gap: 12,
  },
})
