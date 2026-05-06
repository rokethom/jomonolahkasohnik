import type { ReactNode } from 'react'
import { StyleSheet, View } from 'react-native'
import { colors } from '@/theme/colors'

export function Card({ children, padded = true }: { children: ReactNode; padded?: boolean }) {
  return <View style={[styles.card, padded && styles.padded]}>{children}</View>
}

const styles = StyleSheet.create({
  card: {
    overflow: 'hidden',
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 28,
    backgroundColor: colors.card,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 18 },
    shadowOpacity: 0.25,
    shadowRadius: 38,
    elevation: 8,
  },
  padded: {
    padding: 18,
  },
})
