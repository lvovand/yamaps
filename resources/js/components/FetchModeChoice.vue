<script setup>
import { ref } from 'vue'

const props = defineProps({
    organization: { type: Object, required: true },
})

const emit = defineEmits(['started'])

const sending = ref(null)

async function choose(mode) {
    sending.value = mode
    try {
        await emit('started', mode)
    } finally {
        sending.value = null
    }
}
</script>

<template>
    <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 sm:p-5">
        <h2 class="font-semibold">Как собирать отзывы?</h2>

        <p class="mt-2 text-sm text-slate-700">
            У организации {{ organization.reviews_count }} отзывов, но Яндекс отдаёт не больше
            {{ organization.slice_limit }} за одну выдачу — дальше его сервер отвечает ошибкой,
            и это ограничение действует одинаково и для нас, и для обычного браузера.
            Обойти его можно только повторными проходами в другом порядке сортировки.
        </p>

        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <button
                type="button"
                :disabled="sending"
                class="rounded-lg border border-slate-300 bg-white p-4 text-left hover:border-slate-400 disabled:opacity-60"
                @click="choose('recent')"
            >
                <span class="font-medium">Только свежие</span>
                <span class="mt-1 block text-sm text-slate-600">
                    Один проход, до {{ organization.slice_limit }} последних отзывов. Пара минут.
                </span>
            </button>

            <button
                type="button"
                :disabled="sending"
                class="rounded-lg border border-slate-300 bg-white p-4 text-left hover:border-slate-400 disabled:opacity-60"
                @click="choose('maximum')"
            >
                <span class="font-medium">Максимум</span>
                <span class="mt-1 block text-sm text-slate-600">
                    Несколько проходов с разной сортировкой. Дольше и больше запросов,
                    зато отзывов соберём заметно больше.
                </span>
            </button>
        </div>

        <p v-if="sending" class="mt-3 text-sm text-slate-600">Запускаем…</p>
    </div>
</template>
