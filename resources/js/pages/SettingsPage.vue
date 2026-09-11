<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api, { errorMessage, requestCsrfCookie } from '../api'
import StatusBadge from '../components/StatusBadge.vue'

const router = useRouter()

// Одну и ту же карточку Яндекс отдаёт десятком разных адресов — показываем все,
// чтобы пользователь не гадал, годится ли тот, что у него скопирован.
const linkExamples = [
    { title: 'Карточка организации', url: 'https://yandex.ru/maps/org/termoland/3855941798/' },
    { title: 'Она же без названия в адресе', url: 'https://yandex.ru/maps/org/3855941798/' },
    { title: 'Вкладка отзывов', url: 'https://yandex.ru/maps/org/termoland/3855941798/reviews/' },
    { title: 'С регионом в адресе', url: 'https://yandex.ru/maps/2/saint-petersburg/org/termoland/3855941798/' },
    { title: 'С координатами и фильтрами', url: 'https://yandex.ru/maps/org/termoland/3855941798/?ll=30.31%2C59.82&z=17' },
    { title: 'Точка, открытая прямо на карте', url: 'https://yandex.ru/maps/2/saint-petersburg/?mode=poi&poi[uri]=ymapsbm1://org?oid=3855941798' },
    { title: 'Короткая ссылка из кнопки «Поделиться»', url: 'https://yandex.ru/maps/-/CThpBKJS' },
    { title: 'Страница организации в Яндекс Профиле', url: 'https://yandex.ru/profile/3855941798' },
]

const url = ref('')
const organizations = ref([])
const loading = ref(true)
const sending = ref(false)
const error = ref('')

async function load() {
    try {
        const { data } = await api.get('/organizations')
        organizations.value = data.data
    } catch (e) {
        error.value = errorMessage(e, 'Не удалось загрузить список организаций.')
    } finally {
        loading.value = false
    }
}

async function submit() {
    error.value = ''
    sending.value = true

    try {
        await requestCsrfCookie()
        const { data } = await api.post('/organizations', { url: url.value })
        url.value = ''

        // Дальше всё происходит на карточке: там виден ход проверки и, если отзывов
        // много, вопрос о режиме выгрузки.
        router.push({ name: 'organization', params: { id: data.data.id } })
    } catch (e) {
        error.value = errorMessage(e, 'Не удалось сохранить ссылку.')
    } finally {
        sending.value = false
    }
}

onMounted(load)
</script>

<template>
    <div class="mx-auto max-w-3xl px-4 py-8">
        <h1 class="text-xl font-semibold mb-1">Настройки</h1>
        <p class="text-sm text-slate-600 mb-6">
            Вставьте ссылку на карточку организации в Яндекс.Картах — мы соберём её рейтинг и отзывы.
        </p>

        <form class="bg-white border border-slate-200 rounded-xl p-4 sm:p-5 mb-8" @submit.prevent="submit">
            <label class="block text-sm mb-3">
                <span class="text-slate-700">Ссылка на организацию</span>
                <input
                    v-model="url"
                    type="text"
                    required
                    placeholder="https://yandex.ru/maps/org/termoland/3855941798/"
                    class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 focus:border-slate-400 focus:outline-none"
                >
            </label>

            <div class="mb-4 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                <p class="font-medium text-slate-700">Подойдёт ссылка в любом из этих видов:</p>

                <dl class="mt-2 space-y-2">
                    <div v-for="example in linkExamples" :key="example.url">
                        <dt>{{ example.title }}</dt>
                        <dd class="break-all font-mono text-slate-500">{{ example.url }}</dd>
                    </div>
                </dl>

                <p class="mt-2">
                    Домены <code>yandex.ru</code>, <code>yandex.com</code>, <code>yandex.by</code>
                    и <code>yandex.kz</code> равнозначны, <code>www</code> не мешает.
                </p>
            </div>

            <p v-if="error" class="mb-3 text-sm text-red-600">{{ error }}</p>

            <button
                type="submit"
                :disabled="sending"
                class="rounded-md bg-slate-900 px-4 py-2 text-sm text-white hover:bg-slate-800 disabled:opacity-60"
            >
                {{ sending ? 'Сохраняем…' : 'Добавить организацию' }}
            </button>
        </form>

        <h2 class="text-base font-semibold mb-3">Организации</h2>

        <p v-if="loading" class="text-sm text-slate-500">Загружаем…</p>

        <p v-else-if="!organizations.length" class="text-sm text-slate-500">
            Пока ничего не добавлено.
        </p>

        <ul v-else class="space-y-2">
            <li
                v-for="organization in organizations"
                :key="organization.id"
                class="bg-white border border-slate-200 rounded-xl p-4"
            >
                <RouterLink
                    :to="{ name: 'organization', params: { id: organization.id } }"
                    class="flex flex-wrap items-center justify-between gap-2"
                >
                    <span class="font-medium">
                        {{ organization.name || 'Организация без названия' }}
                    </span>
                    <StatusBadge :organization="organization" />
                </RouterLink>

                <p v-if="organization.status === 'ready'" class="mt-1 text-sm text-slate-500">
                    Рейтинг {{ organization.rating }} · оценок {{ organization.ratings_count }} ·
                    собрано отзывов {{ organization.collected_reviews }} из {{ organization.reviews_count }}
                </p>
            </li>
        </ul>
    </div>
</template>
