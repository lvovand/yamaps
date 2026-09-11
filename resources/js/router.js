import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from './stores/auth'
import LoginPage from './pages/LoginPage.vue'
import SettingsPage from './pages/SettingsPage.vue'
import OrganizationPage from './pages/OrganizationPage.vue'
import ParsingSettingsPage from './pages/ParsingSettingsPage.vue'

const routes = [
    { path: '/login', name: 'login', component: LoginPage, meta: { guest: true } },
    { path: '/', name: 'settings', component: SettingsPage },
    { path: '/organizations/:id', name: 'organization', component: OrganizationPage },
    { path: '/settings/parsing', name: 'parsing-settings', component: ParsingSettingsPage },
    { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
    history: createWebHistory(),
    routes,
})

router.beforeEach(async (to) => {
    const auth = useAuthStore()

    // При первой загрузке страницы состояние ещё неизвестно — спрашиваем сервер.
    if (!auth.resolved) {
        await auth.fetchUser()
    }

    if (!to.meta.guest && !auth.user) {
        return { name: 'login' }
    }

    if (to.meta.guest && auth.user) {
        return { name: 'settings' }
    }

    return true
})

export default router
