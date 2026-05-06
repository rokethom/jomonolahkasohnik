import { useEffect, useRef, useState } from 'react'
import { FlatList, StyleSheet, Text, TextInput, View } from 'react-native'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { GradientScreen } from '@/components/GradientScreen'
import { useAppStore } from '@/store/useAppStore'
import { colors } from '@/theme/colors'
import type { ChatMessage } from '@/types'

export function ChatScreen() {
  const messages = useAppStore((state) => state.messages)
  const addMessage = useAppStore((state) => state.addMessage)
  const [text, setText] = useState('')
  const [online, setOnline] = useState(false)
  const wsRef = useRef<WebSocket | null>(null)

  useEffect(() => {
    const wsUrl = process.env.EXPO_PUBLIC_WS_URL
    if (!wsUrl) return

    const ws = new WebSocket(wsUrl)
    wsRef.current = ws
    ws.onopen = () => setOnline(true)
    ws.onclose = () => setOnline(false)
    ws.onmessage = (event) => {
      addMessage({
        id: String(Date.now()),
        from: 'cs',
        text: String(event.data),
        time: new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }),
      })
    }

    return () => ws.close()
  }, [addMessage])

  const send = () => {
    if (!text.trim()) return
    const message: ChatMessage = {
      id: String(Date.now()),
      from: 'customer',
      text,
      time: new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }),
    }
    addMessage(message)
    wsRef.current?.send(text)
    setText('')
  }

  return (
    <GradientScreen>
      <View style={styles.content}>
        <View style={styles.header}>
          <View>
            <Text style={styles.title}>Chat</Text>
            <Text style={styles.subtitle}>Driver dan customer service</Text>
          </View>
          <Text style={[styles.badge, online ? styles.online : styles.offline]}>{online ? 'Online' : 'Fallback'}</Text>
        </View>

        <Card padded={false}>
          <FlatList
            data={messages}
            keyExtractor={(item) => item.id}
            contentContainerStyle={styles.messages}
            renderItem={({ item }) => (
              <View style={[styles.bubble, item.from === 'customer' && styles.mine]}>
                <Text style={styles.from}>{item.from}</Text>
                <Text style={styles.messageText}>{item.text}</Text>
                <Text style={styles.time}>{item.time}</Text>
              </View>
            )}
          />
        </Card>

        <View style={styles.inputRow}>
          <TextInput
            value={text}
            onChangeText={setText}
            placeholder="Tulis pesan..."
            placeholderTextColor={colors.dim}
            style={styles.input}
            onSubmitEditing={send}
          />
          <Button onPress={send}>Kirim</Button>
        </View>
      </View>
    </GradientScreen>
  )
}

const styles = StyleSheet.create({
  content: {
    flex: 1,
    gap: 14,
    paddingBottom: 14,
  },
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    gap: 12,
  },
  title: {
    color: colors.text,
    fontSize: 34,
    fontWeight: '900',
  },
  subtitle: {
    color: colors.muted,
  },
  badge: {
    alignSelf: 'flex-start',
    overflow: 'hidden',
    borderRadius: 999,
    paddingHorizontal: 12,
    paddingVertical: 8,
    fontWeight: '900',
  },
  online: {
    color: '#06110D',
    backgroundColor: colors.green,
  },
  offline: {
    color: '#06110D',
    backgroundColor: colors.orange,
  },
  messages: {
    minHeight: 500,
    gap: 10,
    padding: 14,
  },
  bubble: {
    maxWidth: '82%',
    borderRadius: 22,
    padding: 13,
    backgroundColor: colors.cardStrong,
  },
  mine: {
    alignSelf: 'flex-end',
    backgroundColor: 'rgba(56,255,179,0.20)',
  },
  from: {
    color: colors.dim,
    fontSize: 12,
    fontWeight: '900',
    textTransform: 'capitalize',
  },
  messageText: {
    marginVertical: 6,
    color: colors.text,
    fontSize: 15,
  },
  time: {
    color: colors.dim,
    fontSize: 11,
  },
  inputRow: {
    flexDirection: 'row',
    gap: 10,
  },
  input: {
    flex: 1,
    minHeight: 52,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 18,
    paddingHorizontal: 14,
    color: colors.text,
    backgroundColor: colors.card,
  },
})
