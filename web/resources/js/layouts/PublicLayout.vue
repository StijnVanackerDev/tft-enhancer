<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';
import PlayerSearch from '@/components/tft/PlayerSearch.vue';
import { Toaster } from '@/components/ui/sonner';
import { dashboard, home, login, register } from '@/routes';
import { index as comps } from '@/routes/comps';

const page = usePage();
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header
            class="border-b border-sidebar-border/70 dark:border-sidebar-border"
        >
            <div
                class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-3 px-4 py-3"
            >
                <Link :href="home()" class="flex items-center">
                    <AppLogo />
                </Link>
                <div
                    v-if="page.component !== 'Welcome'"
                    class="order-last w-full sm:order-none sm:w-auto sm:flex-1"
                >
                    <PlayerSearch compact />
                </div>
                <nav class="ml-auto flex items-center gap-4 text-sm">
                    <Link :href="comps()">Comps</Link>
                    <Link v-if="page.props.auth.user" :href="dashboard()">
                        Dashboard
                    </Link>
                    <template v-else>
                        <Link :href="login()">Log in</Link>
                        <Link :href="register()">Register</Link>
                    </template>
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-6">
            <slot />
        </main>

        <footer
            class="border-t border-sidebar-border/70 text-xs text-muted-foreground dark:border-sidebar-border"
        >
            <p class="mx-auto max-w-6xl px-4 py-4">
                TFT Enhancer isn't endorsed by Riot Games and doesn't reflect
                the views or opinions of Riot Games or anyone officially
                involved in producing or managing Riot Games properties. Riot
                Games, and all associated properties are trademarks or
                registered trademarks of Riot Games, Inc.
            </p>
        </footer>
        <Toaster />
    </div>
</template>
