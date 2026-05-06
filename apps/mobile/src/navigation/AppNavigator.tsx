import { NavigationContainer, DefaultTheme } from '@react-navigation/native'
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs'
import { createNativeStackNavigator } from '@react-navigation/native-stack'
import { Text } from 'react-native'
import type { MainTabParamList, RootStackParamList } from '@/types'
import { colors } from '@/theme/colors'
import { useAppStore } from '@/store/useAppStore'
import { AuthScreen } from '@/screens/AuthScreen'
import { HomeScreen } from '@/screens/HomeScreen'
import { OrderScreen } from '@/screens/OrderScreen'
import { TrackingScreen } from '@/screens/TrackingScreen'
import { ChatScreen } from '@/screens/ChatScreen'
import { HistoryScreen } from '@/screens/HistoryScreen'
import { ProfileScreen } from '@/screens/ProfileScreen'

const Stack = createNativeStackNavigator<RootStackParamList>()
const Tab = createBottomTabNavigator<MainTabParamList>()

const navTheme = {
  ...DefaultTheme,
  colors: {
    ...DefaultTheme.colors,
    background: colors.bg,
    card: colors.bg,
    text: colors.text,
    border: colors.border,
    primary: colors.green,
  },
}

function TabIcon({ label, focused }: { label: string; focused: boolean }) {
  return <Text style={{ color: focused ? colors.green : colors.dim, fontWeight: '900', fontSize: 11 }}>{label}</Text>
}

function MainTabs() {
  return (
    <Tab.Navigator
      screenOptions={{
        headerShown: false,
        tabBarStyle: {
          height: 72,
          borderTopWidth: 1,
          borderTopColor: colors.border,
          backgroundColor: '#06110F',
        },
        tabBarActiveTintColor: colors.green,
        tabBarInactiveTintColor: colors.dim,
        tabBarLabelStyle: { fontWeight: '800', fontSize: 11 },
      }}
    >
      <Tab.Screen name="Home" component={HomeScreen} options={{ tabBarIcon: ({ focused }) => <TabIcon label="HM" focused={focused} /> }} />
      <Tab.Screen name="Order" component={OrderScreen} options={{ tabBarIcon: ({ focused }) => <TabIcon label="OR" focused={focused} /> }} />
      <Tab.Screen name="Tracking" component={TrackingScreen} options={{ tabBarIcon: ({ focused }) => <TabIcon label="TR" focused={focused} /> }} />
      <Tab.Screen name="Chat" component={ChatScreen} options={{ tabBarIcon: ({ focused }) => <TabIcon label="CH" focused={focused} /> }} />
      <Tab.Screen name="History" component={HistoryScreen} options={{ tabBarIcon: ({ focused }) => <TabIcon label="HS" focused={focused} /> }} />
      <Tab.Screen name="Profile" component={ProfileScreen} options={{ tabBarIcon: ({ focused }) => <TabIcon label="PF" focused={focused} /> }} />
    </Tab.Navigator>
  )
}

export function AppNavigator() {
  const token = useAppStore((state) => state.token)

  return (
    <NavigationContainer theme={navTheme}>
      <Stack.Navigator screenOptions={{ headerShown: false }}>
        {token ? <Stack.Screen name="Main" component={MainTabs} /> : <Stack.Screen name="Auth" component={AuthScreen} />}
      </Stack.Navigator>
    </NavigationContainer>
  )
}
