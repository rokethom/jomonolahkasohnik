import { useState } from 'react'
import { Alert, StyleSheet, Text, View } from 'react-native'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { Field } from '@/components/Field'
import { GradientScreen } from '@/components/GradientScreen'
import { logout } from '@/services/api'
import { useAppStore } from '@/store/useAppStore'
import { colors } from '@/theme/colors'

export function ProfileScreen() {
  const user = useAppStore((state) => state.user)
  const token = useAppStore((state) => state.token)
  const setSession = useAppStore((state) => state.setSession)
  const clearSession = useAppStore((state) => state.clearSession)
  const [name, setName] = useState(user?.name ?? '')
  const [phone, setPhone] = useState(user?.phone ?? '')

  const doLogout = async () => {
    await logout()
    clearSession()
  }

  return (
    <GradientScreen>
      <View style={styles.content}>
        <Card>
          <View style={styles.profile}>
            <View style={styles.avatar}>
              <Text style={styles.avatarText}>{(user?.name ?? 'J').slice(0, 1).toUpperCase()}</Text>
            </View>
            <View style={{ flex: 1 }}>
              <Text style={styles.title}>{user?.name ?? 'Customer Jojo'}</Text>
              <Text style={styles.subtitle}>{user?.email ?? 'customer@jojo.app'}</Text>
            </View>
          </View>
        </Card>

        <Card>
          <View style={styles.form}>
            <Field label="Nama" value={name} onChangeText={setName} />
            <Field label="Nomor HP" value={phone} keyboardType="phone-pad" onChangeText={setPhone} />
            <Button
              onPress={() => {
                if (user) setSession({ ...user, name, phone }, token)
                Alert.alert('Jojo App', 'Profile tersimpan di perangkat.')
              }}
            >
              Simpan profile
            </Button>
            <Button variant="danger" onPress={doLogout}>
              Logout
            </Button>
          </View>
        </Card>
      </View>
    </GradientScreen>
  )
}

const styles = StyleSheet.create({
  content: {
    gap: 16,
  },
  profile: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 14,
  },
  avatar: {
    width: 76,
    height: 76,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 26,
    backgroundColor: colors.green,
  },
  avatarText: {
    color: '#06110D',
    fontSize: 28,
    fontWeight: '900',
  },
  title: {
    color: colors.text,
    fontSize: 26,
    fontWeight: '900',
  },
  subtitle: {
    marginTop: 4,
    color: colors.muted,
  },
  form: {
    gap: 14,
  },
})
