import { useEffect, useState } from 'react'
import { ActivityIndicator, StyleSheet, View } from 'react-native'
import NetInfo from '@react-native-community/netinfo'
import { GestureHandlerRootView } from 'react-native-gesture-handler'
import { StatusBar } from 'expo-status-bar'
import { SafeAreaProvider } from 'react-native-safe-area-context'
import { AppNavigator } from '@/navigation/AppNavigator'
import { colors } from '@/theme/colors'
import { fetchMe, getSavedToken } from '@/services/api'
import { useAppStore } from '@/store/useAppStore'

export default function App() {
  const setSession = useAppStore((state) => state.setSession)
  const setOffline = useAppStore((state) => state.setOffline)
  const [booting, setBooting] = useState(true)

  useEffect(() => {
    const load = async () => {
      try {
        const token = await getSavedToken()
        if (token) {
          const user = await fetchMe()
          setSession(user, token)
        }
      } finally {
        setBooting(false)
      }
    }

    const timer = setTimeout(load, 0)
    return () => clearTimeout(timer)
  }, [setSession])

  useEffect(() => {
    const unsubscribe = NetInfo.addEventListener((state) => {
      setOffline(!(state.isConnected && state.isInternetReachable !== false))
    })
    return unsubscribe
  }, [setOffline])

  if (booting) {
    return (
      <View style={styles.boot}>
        <ActivityIndicator color={colors.green} size="large" />
      </View>
    )
  }

  return (
    <GestureHandlerRootView style={styles.root}>
      <SafeAreaProvider>
        <StatusBar style="light" />
        <AppNavigator />
      </SafeAreaProvider>
    </GestureHandlerRootView>
  )
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
  },
  boot: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.bg,
  },
})
