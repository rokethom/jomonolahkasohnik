import { useState } from 'react'
import { Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native'
import * as Linking from 'expo-linking'
import * as WebBrowser from 'expo-web-browser'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { Field } from '@/components/Field'
import { GradientScreen } from '@/components/GradientScreen'
import { fetchMe, getApiErrorMessage, googleLoginUrl, login, register, setAuthToken, saveToken } from '@/services/api'
import { useAppStore } from '@/store/useAppStore'
import { colors } from '@/theme/colors'

export function AuthScreen() {
  const setSession = useAppStore((state) => state.setSession)
  const [mode, setMode] = useState<'login' | 'register'>('login')
  const [loading, setLoading] = useState(false)
  const [form, setForm] = useState({ name: '', email: '', phone: '', password: '' })

  const submit = async () => {
    setLoading(true)
    try {
      const response =
        mode === 'login'
          ? await login({ email: form.email, password: form.password })
          : await register({
              name: form.name,
              email: form.email,
              phone: form.phone,
              password: form.password,
              password_confirmation: form.password,
            })
      setSession(response.user, response.token)
    } catch (error) {
      Alert.alert('Jojo App', getApiErrorMessage(error, mode === 'login' ? 'Login gagal' : 'Register gagal'))
    } finally {
      setLoading(false)
    }
  }

  const loginWithGoogle = async () => {
    const redirectUrl = Linking.createURL('auth')
    const result = await WebBrowser.openAuthSessionAsync(`${googleLoginUrl()}?redirect_uri=${encodeURIComponent(redirectUrl)}`, redirectUrl)

    if (result.type !== 'success') return

    const parsed = Linking.parse(result.url)
    const token = typeof parsed.queryParams?.token === 'string' ? parsed.queryParams.token : undefined
    if (!token) {
      Alert.alert('Jojo App', 'Google login belum mengirim token dari backend.')
      return
    }

    await saveToken(token)
    setAuthToken(token)
    const user = await fetchMe()
    setSession(user, token)
  }

  return (
    <GradientScreen>
      <ScrollView contentContainerStyle={styles.scroll} showsVerticalScrollIndicator={false}>
        <View style={styles.hero}>
          <View style={styles.logo}>
            <Text style={styles.logoText}>JO</Text>
          </View>
          <Text style={styles.title}>Jojo App</Text>
          <Text style={styles.subtitle}>Ojek, delivery, kurir, travel, dan custom order dalam satu aplikasi mobile.</Text>
        </View>

        <Card>
          <View style={styles.tabs}>
            <Pressable style={[styles.tab, mode === 'login' && styles.activeTab]} onPress={() => setMode('login')}>
              <Text style={styles.tabText}>Login</Text>
            </Pressable>
            <Pressable style={[styles.tab, mode === 'register' && styles.activeTab]} onPress={() => setMode('register')}>
              <Text style={styles.tabText}>Register</Text>
            </Pressable>
          </View>

          <View style={styles.form}>
            {mode === 'register' && (
              <>
                <Field label="Nama" value={form.name} onChangeText={(name) => setForm({ ...form, name })} />
                <Field label="Nomor HP" value={form.phone} keyboardType="phone-pad" onChangeText={(phone) => setForm({ ...form, phone })} />
              </>
            )}
            <Field label="Email" value={form.email} keyboardType="email-address" autoCapitalize="none" onChangeText={(email) => setForm({ ...form, email })} />
            <Field label="Password" value={form.password} secureTextEntry onChangeText={(password) => setForm({ ...form, password })} />
            <Button loading={loading} onPress={submit}>
              {mode === 'login' ? 'Masuk sekarang' : 'Buat akun'}
            </Button>
            <Button variant="ghost" onPress={loginWithGoogle}>
              Login dengan Google
            </Button>
          </View>
        </Card>
      </ScrollView>
    </GradientScreen>
  )
}

const styles = StyleSheet.create({
  scroll: {
    flexGrow: 1,
    justifyContent: 'center',
    gap: 22,
    paddingVertical: 28,
  },
  hero: {
    gap: 12,
  },
  logo: {
    width: 82,
    height: 82,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 28,
    backgroundColor: colors.green,
    shadowColor: colors.green,
    shadowOpacity: 0.32,
    shadowRadius: 32,
  },
  logoText: {
    color: '#06110D',
    fontSize: 26,
    fontWeight: '900',
  },
  title: {
    color: colors.text,
    fontSize: 54,
    lineHeight: 58,
    fontWeight: '900',
  },
  subtitle: {
    color: colors.muted,
    fontSize: 16,
    lineHeight: 24,
  },
  tabs: {
    flexDirection: 'row',
    gap: 8,
    marginBottom: 16,
    padding: 6,
    borderRadius: 18,
    backgroundColor: 'rgba(255,255,255,0.07)',
  },
  tab: {
    flex: 1,
    minHeight: 44,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 14,
  },
  activeTab: {
    backgroundColor: colors.cardStrong,
  },
  tabText: {
    color: colors.text,
    fontWeight: '900',
  },
  form: {
    gap: 14,
  },
})
