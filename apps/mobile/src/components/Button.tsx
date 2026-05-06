import type { ReactNode } from 'react'
import { ActivityIndicator, Pressable, StyleSheet, Text } from 'react-native'
import { LinearGradient } from 'expo-linear-gradient'
import { colors } from '@/theme/colors'

type Props = {
  children: ReactNode
  onPress?: () => void
  variant?: 'primary' | 'ghost' | 'danger'
  loading?: boolean
  disabled?: boolean
}

export function Button({ children, onPress, variant = 'primary', loading = false, disabled = false }: Props) {
  if (variant === 'primary') {
    return (
      <Pressable disabled={disabled || loading} onPress={onPress} style={({ pressed }) => [styles.press, pressed && styles.pressed]}>
        <LinearGradient colors={[colors.green, colors.blue]} style={[styles.button, disabled && styles.disabled]}>
          {loading ? <ActivityIndicator color="#06110D" /> : <Text style={styles.primaryText}>{children}</Text>}
        </LinearGradient>
      </Pressable>
    )
  }

  return (
    <Pressable
      disabled={disabled || loading}
      onPress={onPress}
      style={({ pressed }) => [styles.button, styles[variant], pressed && styles.pressed, disabled && styles.disabled]}
    >
      {loading ? <ActivityIndicator color={colors.text} /> : <Text style={variant === 'danger' ? styles.dangerText : styles.ghostText}>{children}</Text>}
    </Pressable>
  )
}

const styles = StyleSheet.create({
  press: {
    borderRadius: 18,
  },
  button: {
    minHeight: 52,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 18,
    paddingHorizontal: 18,
  },
  ghost: {
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
  },
  danger: {
    borderWidth: 1,
    borderColor: 'rgba(255,76,101,0.42)',
    backgroundColor: 'rgba(255,76,101,0.13)',
  },
  pressed: {
    transform: [{ scale: 0.98 }],
    opacity: 0.9,
  },
  disabled: {
    opacity: 0.55,
  },
  primaryText: {
    color: '#06110D',
    fontWeight: '900',
    fontSize: 15,
  },
  ghostText: {
    color: colors.text,
    fontWeight: '800',
    fontSize: 15,
  },
  dangerText: {
    color: '#FFDDE2',
    fontWeight: '800',
    fontSize: 15,
  },
})
