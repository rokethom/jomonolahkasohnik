import { StyleSheet, Text } from 'react-native'
import { colors } from '@/theme/colors'
import { useAppStore } from '@/store/useAppStore'

export function OfflineBanner() {
  const isOffline = useAppStore((state) => state.isOffline)
  if (!isOffline) return null

  return <Text style={styles.banner}>Offline mode aktif. Data akan sinkron saat koneksi kembali.</Text>
}

const styles = StyleSheet.create({
  banner: {
    overflow: 'hidden',
    marginBottom: 10,
    borderRadius: 14,
    padding: 10,
    color: '#07110D',
    backgroundColor: colors.orange,
    fontWeight: '900',
    textAlign: 'center',
  },
})
