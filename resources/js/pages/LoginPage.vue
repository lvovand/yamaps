<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { errorMessage } from '../api'

const auth = useAuthStore()
const router = useRouter()

const email = ref('')
const password = ref('')
const error = ref('')
const sending = ref(false)

async function submit() {
    error.value = ''
    sending.value = true

    try {
        await auth.login({ email: email.value, password: password.value })
        router.push({ name: 'settings' })
    } catch (e) {
        error.value = errorMessage(e, 'Не удалось войти. Проверьте логин и пароль.')
    } finally {
        sending.value = false
    }
}
</script>

<template>
    <div class="min-h-screen flex items-center justify-center px-4 py-10">
        <form class="w-full max-w-sm bg-white rounded-xl shadow-sm border border-slate-200 p-6" @submit.prevent="submit">
            <h1 class="text-lg font-semibold mb-1">Вход</h1>
            <p class="text-sm text-slate-500 mb-6">Отзывы и рейтинг организаций с Яндекс.Карт</p>

            <label class="block text-sm mb-4">
                <span class="text-slate-700">Email</span>
                <input
                    v-model="email"
                    type="email"
                    required
                    autocomplete="username"
                    class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 focus:border-slate-400 focus:outline-none"
                >
            </label>

            <label class="block text-sm mb-5">
                <span class="text-slate-700">Пароль</span>
                <input
                    v-model="password"
                    type="password"
                    required
                    autocomplete="current-password"
                    class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 focus:border-slate-400 focus:outline-none"
                >
            </label>

            <p v-if="error" class="mb-4 text-sm text-red-600">{{ error }}</p>

            <button
                type="submit"
                :disabled="sending"
                class="w-full rounded-md bg-slate-900 px-4 py-2 text-white hover:bg-slate-800 disabled:opacity-60"
            >
                {{ sending ? 'Входим…' : 'Войти' }}
            </button>
        </form>
    </div>
</template>
