import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import App from './App.vue'
import RequestsList from './pages/RequestsList.vue'
import RequestDetail from './pages/RequestDetail.vue'
import OutgoingList from './pages/OutgoingList.vue'
import OutgoingDetail from './pages/OutgoingDetail.vue'
import '../css/app.css'

const basePath = window.__tracing?.basePath ?? '/tracing'

const router = createRouter({
    history: createWebHistory(basePath),
    routes: [
        { path: '/',             component: RequestsList },
        { path: '/:id',          component: RequestDetail, props: true },
        { path: '/outgoing',     component: OutgoingList },
        { path: '/outgoing/:id', component: OutgoingDetail, props: true },
    ],
    scrollBehavior: () => ({ top: 0 }),
})

createApp(App).use(router).mount('#app')
