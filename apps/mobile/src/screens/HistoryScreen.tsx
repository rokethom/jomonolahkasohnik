import { useCallback, useState } from 'react'
import { ActivityIndicator, FlatList, RefreshControl, StyleSheet, Text, View } from 'react-native'
import { Card } from '@/components/Card'
import { GradientScreen } from '@/components/GradientScreen'
import { fetchOrders } from '@/services/api'
import { useAppStore } from '@/store/useAppStore'
import { colors } from '@/theme/colors'
import { formatRupiah } from '@/theme/format'

const statusColor: Record<string, string> = {
  pending: colors.orange,
  created: colors.orange,
  searching_driver: colors.orange,
  driver_accepted: colors.green,
  completed: colors.green,
  done: colors.green,
  cancelled: colors.red,
}

export function HistoryScreen() {
  const orders = useAppStore((state) => state.orders)
  const setOrders = useAppStore((state) => state.setOrders)
  const [loading, setLoading] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setOrders(await fetchOrders())
    } finally {
      setLoading(false)
    }
  }, [setOrders])

  return (
    <GradientScreen>
      <View style={styles.content}>
        <Text style={styles.title}>History</Text>
        {loading && orders.length === 0 ? (
          <ActivityIndicator color={colors.green} />
        ) : (
          <FlatList
            data={orders}
            keyExtractor={(item) => String(item.id)}
            refreshControl={<RefreshControl refreshing={loading} onRefresh={load} tintColor={colors.green} />}
            contentContainerStyle={styles.list}
            ListEmptyComponent={<Text style={styles.empty}>Belum ada order. Pull to refresh setelah membuat order.</Text>}
            renderItem={({ item }) => (
              <Card>
                <View style={styles.row}>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.code}>{item.order_code ?? item.code ?? `Order #${item.id}`}</Text>
                    <Text style={styles.route}>{item.pickup_address ?? 'Pickup'} ke {item.destination_address ?? 'Destination'}</Text>
                  </View>
                  <Text style={[styles.badge, { backgroundColor: statusColor[String(item.status).toLowerCase()] ?? colors.blue }]}>{item.status}</Text>
                </View>
                <Text style={styles.total}>{formatRupiah(item.total_price)}</Text>
              </Card>
            )}
          />
        )}
      </View>
    </GradientScreen>
  )
}

const styles = StyleSheet.create({
  content: {
    flex: 1,
    gap: 14,
  },
  title: {
    color: colors.text,
    fontSize: 34,
    fontWeight: '900',
  },
  list: {
    gap: 12,
    paddingBottom: 24,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 12,
  },
  code: {
    color: colors.text,
    fontSize: 18,
    fontWeight: '900',
  },
  route: {
    marginTop: 6,
    color: colors.muted,
  },
  badge: {
    overflow: 'hidden',
    borderRadius: 999,
    paddingHorizontal: 10,
    paddingVertical: 7,
    color: '#06110D',
    fontSize: 11,
    fontWeight: '900',
    textTransform: 'uppercase',
  },
  total: {
    marginTop: 14,
    color: colors.green,
    fontSize: 22,
    fontWeight: '900',
  },
  empty: {
    marginTop: 80,
    color: colors.muted,
    textAlign: 'center',
  },
})
