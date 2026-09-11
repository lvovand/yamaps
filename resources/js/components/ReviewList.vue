<script setup>
import { computed } from 'vue'

const props = defineProps({
    reviews: { type: Array, required: true },
    pagination: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    page: { type: Number, required: true },
})

const emit = defineEmits(['update:page'])

const lastPage = computed(() => props.pagination?.last_page ?? 1)

function formatDate(value) {
    return value ? new Date(value).toLocaleDateString('ru-RU') : ''
}

function go(page) {
    if (page >= 1 && page <= lastPage.value) {
        emit('update:page', page)
    }
}
</script>

<template>
    <div class="mt-3">
        <p v-if="loading" class="text-sm text-slate-500">Загружаем отзывы…</p>

        <p v-else-if="!reviews.length" class="text-sm text-slate-500">
            Отзывов пока нет.
        </p>

        <ul v-else class="space-y-3">
            <li v-for="review in reviews" :key="review.id" class="bg-white border border-slate-200 rounded-xl p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <a
                        v-if="review.author_url"
                        :href="review.author_url"
                        target="_blank"
                        rel="noopener"
                        class="font-medium hover:underline"
                    >
                        {{ review.author || 'Аноним' }}
                    </a>
                    <span v-else class="font-medium">{{ review.author || 'Аноним' }}</span>

                    <span class="text-sm text-slate-500">{{ formatDate(review.published_at) }}</span>
                </div>

                <p class="mt-1 text-amber-500" :title="`Оценка: ${review.rating ?? '—'}`">
                    <span v-if="review.rating">{{ '★'.repeat(review.rating) }}<span class="text-slate-300">{{ '★'.repeat(5 - review.rating) }}</span></span>
                </p>

                <p v-if="review.text" class="mt-2 text-sm text-slate-700 whitespace-pre-line">{{ review.text }}</p>
                <p v-else class="mt-2 text-sm text-slate-400">Без текста, только оценка.</p>
            </li>
        </ul>

        <div v-if="lastPage > 1" class="mt-5 flex items-center justify-between gap-3">
            <button
                type="button"
                :disabled="page <= 1"
                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm disabled:opacity-40"
                @click="go(page - 1)"
            >
                Назад
            </button>

            <span class="text-sm text-slate-600">Страница {{ page }} из {{ lastPage }}</span>

            <button
                type="button"
                :disabled="page >= lastPage"
                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm disabled:opacity-40"
                @click="go(page + 1)"
            >
                Вперёд
            </button>
        </div>
    </div>
</template>
