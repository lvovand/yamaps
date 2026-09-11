import axios from 'axios'

// Sanctum в SPA-режиме авторизует по кукам сессии, поэтому запросы шлём с credentials,
// а axios сам подставляет XSRF-TOKEN из куки в заголовок.
const api = axios.create({
    baseURL: '/api',
    withCredentials: true,
    withXSRFToken: true,
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
})

// Куку XSRF-TOKEN выдаёт отдельный маршрут Sanctum — дёргаем его перед первым изменяющим запросом.
export async function requestCsrfCookie() {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

// Приводим ошибку axios к тексту, который не стыдно показать пользователю.
export function errorMessage(error, fallback = 'Что-то пошло не так. Попробуйте ещё раз.') {
    const data = error.response?.data

    if (data?.errors) {
        return Object.values(data.errors).flat().join(' ')
    }

    return data?.message || fallback
}

export default api
