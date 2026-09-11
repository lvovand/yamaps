<script setup>
import { computed } from 'vue'

const props = defineProps({
    organization: { type: Object, required: true },
})

const style = computed(() => ({
    pending: 'bg-slate-100 text-slate-600',
    checking: 'bg-slate-100 text-slate-600',
    awaiting_choice: 'bg-amber-100 text-amber-800',
    parsing: 'bg-amber-100 text-amber-700',
    ready: 'bg-emerald-100 text-emerald-700',
    failed: 'bg-red-100 text-red-700',
}[props.organization.status]))

// Пока идёт выгрузка, показываем не просто «загружаем», а сколько отзывов уже собрано:
// проход по большой карточке занимает минуты, и молчащий индикатор выглядит как зависший.
const text = computed(() => {
    const { status, status_label: label, parsed_reviews: parsed } = props.organization

    return status === 'parsing' && parsed > 0 ? `${label} — ${parsed}` : label
})
</script>

<template>
    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium" :class="style">
        {{ text }}
    </span>
</template>
