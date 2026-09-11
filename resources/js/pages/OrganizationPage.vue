<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import api, { errorMessage, requestCsrfCookie } from '../api'
import StatusBadge from '../components/StatusBadge.vue'
import ReviewList from '../components/ReviewList.vue'
import FetchModeChoice from '../components/FetchModeChoice.vue'

const route = useRoute()

const organization = ref(null)
const reviews = ref([])
const pagination = ref(null)
const page = ref(1)
const loading = ref(true)
const loadingReviews = ref(false)
const error = ref('')

let poller = null

const inProgress = computed(() => ['pending', 'checking', 'parsing'].includes(organization.value?.status))
const needsChoice = computed(() => organization.value?.status === 'awaiting_choice')

// Разницу между «сколько есть у Яндекса» и «сколько забрали» нужно объяснять,
// иначе она выглядит как недоработка, а не как ограничение источника.
const incomplete = computed(() => {
    const o = organization.value

    return o?.status === 'ready' && o.reviews_count > o.collected_reviews
})

async function loadOrganization() {
    try {
        const { data } = await api.get(`/organizations/${route.params.id}`)
        organization.value = data.data
    } catch (e) {
        error.value = errorMessage(e, 'Не удалось загрузить данные организации.')
    } finally {
        loading.value = false
    }
}

async function loadReviews() {
    loadingReviews.value = true

    try {
        const { data } = await api.get(`/organizations/${route.params.id}/reviews`, {
            params: { page: page.value },
        })
        reviews.value = data.data
        pagination.value = data.meta
    } catch (e) {
        error.value = errorMessage(e, 'Не удалось загрузить отзывы.')
    } finally {
        loadingReviews.value = false
    }
}

async function start(mode) {
    error.value = ''

    try {
        await requestCsrfCookie()
        const { data } = await api.post(`/organizations/${route.params.id}/start`, { fetch_mode: mode })
        organization.value = data.data
        startPolling()
    } catch (e) {
        error.value = errorMessage(e, 'Не удалось запустить выгрузку.')
    }
}

async function refresh() {
    error.value = ''

    try {
        await requestCsrfCookie()
        const { data } = await api.post(`/organizations/${route.params.id}/refresh`)
        organization.value = data.data
        startPolling()
    } catch (e) {
        error.value = errorMessage(e, 'Не удалось запустить обновление.')
    }
}

// Пока задача в очереди, спрашиваем состояние сами: отзывы приезжают порциями,
// и страница должна показывать их, не заставляя пользователя нажимать «обновить».
function startPolling() {
    stopPolling()
    poller = setInterval(async () => {
        await loadOrganization()

        if (!inProgress.value) {
            stopPolling()
            await loadReviews()
        }
    }, 2000)
}

function stopPolling() {
    if (poller) {
        clearInterval(poller)
        poller = null
    }
}

watch(page, loadReviews)

onMounted(async () => {
    await loadOrganization()
    await loadReviews()

    if (inProgress.value) {
        startPolling()
    }
})

onUnmounted(stopPolling)
</script>

<template>
    <div class="mx-auto max-w-3xl px-4 py-8">
        <RouterLink to="/" class="text-sm text-slate-500 hover:text-slate-700">← К настройкам</RouterLink>

        <p v-if="loading" class="mt-6 text-sm text-slate-500">Загружаем…</p>

        <template v-else-if="organization">
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-semibold">
                    {{ organization.name || 'Организация' }}
                </h1>
                <StatusBadge :organization="organization" />
            </div>

            <a
                :href="organization.url"
                target="_blank"
                rel="noopener"
                class="mt-1 inline-block text-sm text-slate-500 hover:text-slate-700 break-all"
            >
                {{ organization.url }}
            </a>

            <p v-if="organization.error" class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ organization.error }}
            </p>

            <FetchModeChoice v-if="needsChoice" :organization="organization" @started="start" />

            <div v-if="organization.rating !== null" class="mt-5 grid grid-cols-3 gap-3">
                <div class="bg-white border border-slate-200 rounded-xl p-4">
                    <p class="text-xs text-slate-500">Средний рейтинг</p>
                    <p class="mt-1 text-2xl font-semibold">{{ organization.rating ?? '—' }}</p>
                </div>
                <div class="bg-white border border-slate-200 rounded-xl p-4">
                    <p class="text-xs text-slate-500">Оценок</p>
                    <p class="mt-1 text-2xl font-semibold">{{ organization.ratings_count ?? '—' }}</p>
                </div>
                <div class="bg-white border border-slate-200 rounded-xl p-4">
                    <p class="text-xs text-slate-500">Отзывов</p>
                    <p class="mt-1 text-2xl font-semibold">{{ organization.reviews_count ?? '—' }}</p>
                    <p class="mt-1 text-xs text-slate-500">
                        собрано {{ organization.collected_reviews }}
                    </p>
                </div>
            </div>

            <p v-if="incomplete" class="mt-3 text-sm text-slate-600">
                Собрали {{ organization.collected_reviews }} из {{ organization.reviews_count }}.
                Яндекс отдаёт не больше {{ organization.slice_limit }} отзывов за одну выдачу,
                поэтому часть остаётся недоступной — это ограничение самого источника.
            </p>

            <p v-if="inProgress" class="mt-5 text-sm text-slate-600">
                Собираем отзывы, это может занять несколько минут. Собрано: {{ organization.parsed_reviews }}.
            </p>

            <p v-if="error" class="mt-4 text-sm text-red-600">{{ error }}</p>

            <div class="mt-6 flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold">Отзывы</h2>
                <button
                    v-if="!needsChoice"
                    type="button"
                    :disabled="inProgress"
                    class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                    @click="refresh"
                >
                    Обновить данные
                </button>
            </div>

            <ReviewList
                v-model:page="page"
                :reviews="reviews"
                :pagination="pagination"
                :loading="loadingReviews"
            />
        </template>
    </div>
</template>
