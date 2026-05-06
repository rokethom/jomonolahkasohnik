import { StyleSheet, Text, TextInput, type TextInputProps, View } from 'react-native'
import { colors } from '@/theme/colors'

type Props = TextInputProps & {
  label: string
}

export function Field({ label, style, ...props }: Props) {
  return (
    <View style={styles.wrap}>
      <Text style={styles.label}>{label}</Text>
      <TextInput placeholderTextColor={colors.dim} style={[styles.input, style]} {...props} />
    </View>
  )
}

const styles = StyleSheet.create({
  wrap: {
    gap: 8,
  },
  label: {
    color: colors.muted,
    fontSize: 13,
    fontWeight: '800',
  },
  input: {
    minHeight: 52,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 18,
    paddingHorizontal: 14,
    color: colors.text,
    backgroundColor: 'rgba(0,0,0,0.22)',
  },
})
