import { defineStore } from 'pinia'
import { ref } from 'vue'
import api, { requestCsrfCookie } from '../api'

export const useAuthStore = defineStore('auth', () => {
    const user = ref(null)
    // Пока не сходили за /me, неизвестно, авторизован пользователь или нет —
    // роутер в этот момент не должен никуда редиректить.
    const resolved = ref(false)

    async function fetchUser() {
        try {
            const { data } = await api.get('/me')
            user.value = data
        } catch {
            user.value = null
        } finally {
            resolved.value = true
        }
    }

    async function login(credentials) {
        await requestCsrfCookie()
        const { data } = await api.post('/login', credentials)
        user.value = data
    }

    async function logout() {
        await api.post('/logout')
        user.value = null
    }

    return { user, resolved, fetchUser, login, logout }
})
