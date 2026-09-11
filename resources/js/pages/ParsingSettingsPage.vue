<script setup>
import { onMounted, ref } from 'vue'
import api, { errorMessage, requestCsrfCookie } from '../api'
import PasswordField from '../components/PasswordField.vue'

const form = ref(null)
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const saved = ref(false)
const checks = ref({})

const types = [
    { value: 'datacenter', label: 'Серверный' },
    { value: 'mobile', label: 'Мобильный' },
    { value: 'residential', label: 'Резидентный' },
]

async function load() {
    try {
        const { data } = await api.get('/settings/parsing')
        form.value = {
            ...data.data,
            proxies: data.data.proxies.map((p) => ({ ...p, password: '' })),
        }
    } catch (e) {
        error.value = errorMessage(e, 'Не удалось загрузить настройки.')
    } finally {
        loading.value = false
    }
}

function addProxy() {
    form.value.proxies.push({
        scheme: 'http',
        host: '',
        port: 8000,
        username: '',
        password: '',
        has_password: false,
        type: 'datacenter',
        rotate_url: '',
    })
}

function removeProxy(index) {
    form.value.proxies.splice(index, 1)
    delete checks.value[index]
}

function revealPassword(index) {
    return async () => {
        const { data } = await api.get(`/settings/parsing/proxies/${index}/password`)

        return data.password ?? ''
    }
}

async function check(index) {
    const proxy = form.value.proxies[index]
    checks.value[index] = { pending: true }

    try {
        await requestCsrfCookie()
        const { data } = await api.post('/settings/parsing/check-proxy', { ...proxy, index })
        checks.value[index] = data
    } catch (e) {
        checks.value[index] = { ok: false, message: errorMessage(e, 'Проверка не удалась.') }
    }
}

async function save() {
    error.value = ''
    saved.value = false
    saving.value = true

    try {
        await requestCsrfCookie()
        const { data } = await api.put('/settings/parsing', form.value)
        form.value = {
            ...data.data,
            proxies: data.data.proxies.map((p) => ({ ...p, password: '' })),
        }
        saved.value = true
    } catch (e) {
        error.value = errorMessage(e, 'Не удалось сохранить настройки.')
    } finally {
        saving.value = false
    }
}

onMounted(load)
</script>

<template>
    <div class="mx-auto max-w-3xl px-4 py-8">
        <RouterLink to="/" class="text-sm text-slate-500 hover:text-slate-700">← К организациям</RouterLink>

        <h1 class="mt-4 text-xl font-semibold">Настройки парсинга</h1>
        <p class="mt-1 text-sm text-slate-600">
            Здесь настраивается, как приложение ходит к Яндексу. Чем аккуратнее запросы,
            тем дольше живёт адрес, с которого они идут.
        </p>

        <p v-if="loading" class="mt-6 text-sm text-slate-500">Загружаем…</p>

        <form v-else-if="form" class="mt-6 space-y-6" @submit.prevent="save">
            <section class="rounded-xl border border-slate-200 bg-white p-4 sm:p-5">
                <h2 class="font-medium">Как выходим в сеть</h2>

                <div class="mt-3 space-y-2">
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.transport" type="radio" value="direct" class="mt-1">
                        <span>
                            <span class="font-medium">Напрямую</span>
                            <span class="block text-slate-600">
                                Запросы идут с адреса сервера. Подходит, пока сервер в России,
                                но бан заденет и сам сайт.
                            </span>
                        </span>
                    </label>

                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.transport" type="radio" value="proxy" class="mt-1">
                        <span>
                            <span class="font-medium">Через прокси</span>
                            <span class="block text-slate-600">
                                Запросы идут через список ниже. При бане адрес меняется автоматически.
                            </span>
                        </span>
                    </label>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-4 sm:p-5">
                <h2 class="font-medium">Темп запросов</h2>
                <p class="mt-1 text-sm text-slate-600">
                    Пауза выбирается случайно из этого промежутка: ровный интервал сам по себе
                    выглядит машинным.
                </p>

                <div class="mt-3 grid gap-3 sm:grid-cols-3">
                    <label class="block text-sm">
                        <span class="text-slate-700">Пауза от, сек</span>
                        <input v-model.number="form.delay_min" type="number" min="1" max="60"
                               class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                    </label>

                    <label class="block text-sm">
                        <span class="text-slate-700">до, сек</span>
                        <input v-model.number="form.delay_max" type="number" min="1" max="120"
                               class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                    </label>

                    <label class="block text-sm">
                        <span class="text-slate-700">Таймаут, сек</span>
                        <input v-model.number="form.timeout" type="number" min="5" max="120"
                               class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                    </label>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-4 sm:p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-medium">Прокси</h2>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50"
                            @click="addProxy">
                        Добавить
                    </button>
                </div>

                <p v-if="!form.proxies.length" class="mt-3 text-sm text-slate-500">
                    Список пуст — запросы пойдут напрямую, даже если выбран режим «через прокси».
                </p>

                <div v-for="(proxy, index) in form.proxies" :key="index"
                     class="mt-4 rounded-lg border border-slate-200 p-3 sm:p-4">
                    <div class="grid gap-3 sm:grid-cols-4">
                        <label class="block text-sm">
                            <span class="text-slate-700">Протокол</span>
                            <select v-model="proxy.scheme" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                                <option>http</option>
                                <option>https</option>
                                <option>socks5</option>
                                <option>socks5h</option>
                            </select>
                        </label>

                        <label class="block text-sm sm:col-span-2">
                            <span class="text-slate-700">Адрес</span>
                            <input v-model="proxy.host" type="text" placeholder="1.2.3.4"
                                   class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                        </label>

                        <label class="block text-sm">
                            <span class="text-slate-700">Порт</span>
                            <input v-model.number="proxy.port" type="number" min="1" max="65535"
                                   class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                        </label>

                        <label class="block text-sm sm:col-span-2">
                            <span class="text-slate-700">Логин</span>
                            <input v-model="proxy.username" type="text" autocomplete="off"
                                   class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                        </label>

                        <div class="block text-sm sm:col-span-2">
                            <span class="text-slate-700">Пароль</span>
                            <PasswordField
                                v-model="proxy.password"
                                :saved="proxy.has_password"
                                :reveal="revealPassword(index)"
                                class="mt-1"
                            />
                        </div>

                        <label class="block text-sm sm:col-span-2">
                            <span class="text-slate-700">Тип</span>
                            <select v-model="proxy.type" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                                <option v-for="type in types" :key="type.value" :value="type.value">
                                    {{ type.label }}
                                </option>
                            </select>
                        </label>

                        <label v-if="proxy.type === 'mobile'" class="block text-sm sm:col-span-2">
                            <span class="text-slate-700">Ссылка смены IP</span>
                            <input v-model="proxy.rotate_url" type="url" placeholder="https://…"
                                   class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                        </label>
                    </div>

                    <p class="mt-2 text-xs text-slate-500">
                        <template v-if="proxy.type === 'mobile'">
                            При бане дёрнем ссылку смены IP и продолжим с этого же прокси.
                        </template>
                        <template v-else-if="proxy.type === 'residential'">
                            Адрес меняет провайдер — при бане просто повторим запрос.
                        </template>
                        <template v-else>
                            Адрес статичный: при бане прокси выбывает, берём следующий из списка.
                        </template>
                    </p>

                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50"
                                @click="check(index)">
                            Проверить
                        </button>

                        <button type="button" class="text-sm text-red-600 hover:underline" @click="removeProxy(index)">
                            Удалить
                        </button>

                        <span v-if="checks[index]" class="text-sm"
                              :class="checks[index].pending ? 'text-slate-500' : (checks[index].ok ? 'text-emerald-700' : 'text-red-600')">
                            {{ checks[index].pending ? 'Проверяем…' : checks[index].message }}
                        </span>
                    </div>
                </div>
            </section>

            <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
            <p v-if="saved" class="text-sm text-emerald-700">Настройки сохранены.</p>

            <button type="submit" :disabled="saving"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm text-white hover:bg-slate-800 disabled:opacity-60">
                {{ saving ? 'Сохраняем…' : 'Сохранить' }}
            </button>
        </form>
    </div>
</template>
