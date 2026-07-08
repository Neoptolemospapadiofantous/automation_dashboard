<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ApplicationMark from '@/Components/ApplicationMark.vue';
import AppConfirmDialog from '@/Components/AppConfirmDialog.vue';
import SiteFooter from '@/Components/SiteFooter.vue';
import Banner from '@/Components/Banner.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { useTheme } from '@/composables/useTheme';
import SidebarLink from '@/Components/SidebarLink.vue';
import CreditMeter from '@/Components/CreditMeter.vue';

const props = defineProps({
    title: String,
});

const { theme, toggle: toggleTheme } = useTheme();

const page = usePage();
const notifications = computed(() => page.props.notifications ?? []);
const currentAgent = computed(() => page.props.currentAgent ?? null);
const teamAgents = computed(() => page.props.teamAgents ?? []);
// Latest product-news headline shown in the top bar (where the page title
// used to be). Shared globally via HandleInertiaRequests; null hides it.
const latestHeadline = computed(() => page.props.latestHeadline ?? null);
const isAdmin = computed(() => page.props.isAdmin === true);
const automationsEnabled = computed(() => page.props.automationsEnabled === true);
const hasRoute = (name) => route().has(name);
const showMobileNav = ref(false);

const markNotificationsRead = () => {
    if (!notifications.value.length) return;
    router.post(route('notifications.read'), {}, { preserveScroll: true });
};

const switchToTeam = (team) => {
    router.put(route('current-team.update'), { team_id: team.id }, { preserveState: false });
};

const switchToAgent = (agent) => {
    router.put(route('current-agent.update'), { agent_id: agent.id }, { preserveState: false });
};

const logout = () => {
    router.post(route('logout'));
};

// Mobile sidebar dismisses on link/button taps but NOT on group-label clicks.
// Previously the close handler sat on `<nav>` and any tap (including the
// "Inbox"/"Knowledge" group labels) collapsed the menu, which felt broken.
const handleMobileNavClick = (event) => {
    if (event.target.closest('a, button')) {
        showMobileNav.value = false;
    }
};
</script>

<template>
    <div>
        <Head :title="title" />
        <Banner />

        <div class="min-h-screen bg-bg-elev">
            <!-- ───────────────────────── Sidebar (desktop) ───────────────────────── -->
            <aside class="hidden lg:fixed lg:inset-y-0 lg:left-0 lg:z-30 lg:flex lg:w-60 lg:flex-col lg:border-r lg:border-border-line lg:bg-bg">
                <!-- Logo -->
                <div class="flex h-16 items-center justify-between border-b border-border-line px-5">
                    <Link :href="route('dashboard')" class="flex items-center gap-2">
                        <ApplicationMark class="block h-8 w-auto" />
                    </Link>
                    <span class="flex items-center gap-1.5 bp-ref">
                        <span class="bp-dot pulse-glow text-violet" aria-hidden="true" />
                        Flowstack
                    </span>
                </div>

                <!-- Agent picker (workspace switcher) -->
                <div v-if="currentAgent || teamAgents.length" class="border-b border-border-line px-3 py-3">
                    <Dropdown align="left" width="56">
                        <template #trigger>
                            <button type="button" class="group flex w-full items-center gap-2 rounded-none border border-border-line bg-bg px-2.5 py-2 text-left text-sm font-medium text-ink-dim transition hover:border-border-hi hover:bg-surface-hi hover:text-ink">
                                <span class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-none bg-surface-hi text-ink">
                                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                                    </svg>
                                </span>
                                <span class="flex-1 truncate">{{ currentAgent?.name ?? 'No agent' }}</span>
                                <svg class="size-4 text-ink-mute transition group-hover:text-ink-dim" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
                                </svg>
                            </button>
                        </template>
                        <template #content>
                            <div class="w-56">
                                <div class="block px-4 py-2 font-mono text-xs uppercase tracking-wider text-ink-mute">Agents</div>
                                <DropdownLink :href="route('agents.index')">All agents</DropdownLink>
                                <!-- Second+ agents skip the onboarding wizard
                                     (it's a one-time welcome). Land on the
                                     agents index where the create-dialog
                                     enforces plan limits and routes BYOK vs.
                                     managed correctly. -->
                                <DropdownLink :href="route('agents.index')">+ New agent</DropdownLink>
                                <template v-if="teamAgents.length > 1">
                                    <div class="border-t border-border-line" />
                                    <div class="block px-4 py-2 font-mono text-xs uppercase tracking-wider text-ink-mute">Switch</div>
                                    <template v-for="agent in teamAgents" :key="agent.id">
                                        <form @submit.prevent="switchToAgent(agent)">
                                            <DropdownLink as="button">
                                                <div class="flex items-center gap-2">
                                                    <svg v-if="agent.id === currentAgent?.id" class="size-4 text-green-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                                    </svg>
                                                    <span v-else class="size-4" />
                                                    <span>{{ agent.name }}</span>
                                                </div>
                                            </DropdownLink>
                                        </form>
                                    </template>
                                </template>
                            </div>
                        </template>
                    </Dropdown>
                </div>

                <!-- Nav -->
                <nav class="flex-1 space-y-5 overflow-y-auto px-3 py-4">
                    <!-- Top-level -->
                    <SidebarLink :href="route('dashboard')" active-pattern="dashboard">
                        <template #icon>
                            <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                        </template>
                        Dashboard
                    </SidebarLink>
                    <SidebarLink :href="route('chat.index')" active-pattern="chat.index">
                        <template #icon>
                            <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" /></svg>
                        </template>
                        Chat
                    </SidebarLink>

                    <!-- Inbox group -->
                    <div>
                        <div class="px-2 pb-1 font-mono text-xs font-semibold uppercase tracking-wider text-ink-mute">Inbox</div>
                        <div class="space-y-0.5">
                            <SidebarLink :href="route('leads.index')" active-pattern="leads.*">
                                <template #icon>
                                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                                </template>
                                Leads
                            </SidebarLink>
                            <SidebarLink :href="route('conversations.index')" :active-pattern="['conversations.index', 'conversations.show']">
                                <template #icon>
                                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" /></svg>
                                </template>
                                Conversations
                            </SidebarLink>
                        </div>
                    </div>

                    <!-- Knowledge group -->
                    <div>
                        <div class="px-2 pb-1 font-mono text-xs font-semibold uppercase tracking-wider text-ink-mute">Knowledge</div>
                        <div class="space-y-0.5">
                            <SidebarLink :href="route('knowledge.index')" active-pattern="knowledge.*">
                                <template #icon>
                                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" /></svg>
                                </template>
                                Documents
                            </SidebarLink>
                        </div>
                    </div>

                    <!-- Workspace group -->
                    <div>
                        <div class="px-2 pb-1 font-mono text-xs font-semibold uppercase tracking-wider text-ink-mute">Workspace</div>
                        <div class="space-y-0.5">
                            <SidebarLink :href="route('agents.index')" :active-pattern="['agents.index', 'agents.show']">
                                <template #icon>
                                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                </template>
                                Agents
                            </SidebarLink>
                            <SidebarLink v-if="isAdmin" :href="route('agents.faq.index')" active-pattern="agents.faq.*">
                                <template #icon>
                                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" /></svg>
                                </template>
                                FAQ
                            </SidebarLink>
                            <!-- Hidden while automations are off: the run log can't gain
                                 rows, so an always-empty audit page reads as unfinished.
                                 Reappears automatically when the flag flips. -->
                            <SidebarLink v-if="automationsEnabled" :href="route('agents.activity.index')" active-pattern="agents.activity.*">
                                <template #icon>
                                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5m.75-9l3-3 2.148 2.148A12.061 12.061 0 0116.5 7.605" /></svg>
                                </template>
                                Activity
                            </SidebarLink>
                            <SidebarLink :href="route('agents.versions.index')" active-pattern="agents.versions.*">
                                <template #icon>
                                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
                                </template>
                                Versions
                            </SidebarLink>
                            <SidebarLink :href="route('install.index')" active-pattern="install.*">
                                <template #icon>
                                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                                </template>
                                Install
                            </SidebarLink>
                            <SidebarLink :href="route('billing.index')" active-pattern="billing.*">
                                <template #icon>
                                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>
                                </template>
                                Billing
                            </SidebarLink>
                        </div>
                    </div>

                    <!-- Admin group — Hermes operator only (config/hermes.php
                         allowlist). Project/platform pages: future features
                         parked behind their flag (Actions) and the local-only
                         Hermes dev pages. FAQ lives in Workspace — it's agent
                         content authoring, not a platform page. -->
                    <div v-if="isAdmin">
                        <div class="px-2 pb-1 font-mono text-xs font-semibold uppercase tracking-wider text-ink-mute">Admin</div>
                        <div class="space-y-0.5">
                            <SidebarLink :href="route('agents.actions.index')" active-pattern="agents.actions.*">
                                <template #icon>
                                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                                </template>
                                Actions
                                <template v-if="!automationsEnabled" #badge>
                                    <span class="rounded-none bg-surface-hi px-1.5 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wider text-ink-mute">Soon</span>
                                </template>
                            </SidebarLink>
                            <SidebarLink v-if="hasRoute('hermes.metrics')" :href="route('hermes.metrics')" active-pattern="hermes.metrics">
                                <template #icon>
                                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5m.75-9l3-3 2.148 2.148A12.061 12.061 0 0116.5 7.605" /></svg>
                                </template>
                                Metrics
                            </SidebarLink>
                            <SidebarLink v-if="hasRoute('architecture.graph')" :href="route('architecture.graph')" active-pattern="architecture.graph">
                                <template #icon>
                                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 14.25v2.25m3-4.5v4.5m3-6.75v6.75m3-9v9M6 20.25h12A2.25 2.25 0 0020.25 18V6A2.25 2.25 0 0018 3.75H6A2.25 2.25 0 003.75 6v12A2.25 2.25 0 006 20.25z" /></svg>
                                </template>
                                Architecture
                            </SidebarLink>
                        </div>
                    </div>
                </nav>

                <!-- Credit meter -->
                <CreditMeter />

                <!-- User card. placement="top" because this trigger is pinned
                     to the bottom of a fixed-height sidebar — opening DOWN
                     would render the panel off-screen below the viewport. -->
                <div class="border-t border-border-line p-3">
                    <Dropdown align="left" width="60" placement="top">
                        <template #trigger>
                            <button type="button" class="flex w-full items-center gap-2 rounded-none px-2 py-1.5 text-left text-sm hover:bg-surface-hi">
                                <img
                                    v-if="$page.props.jetstream.managesProfilePhotos"
                                    class="size-7 rounded-full object-cover"
                                    :src="$page.props.auth.user.profile_photo_url"
                                    :alt="$page.props.auth.user.name"
                                />
                                <div v-else class="flex size-7 items-center justify-center rounded-full bg-surface-hi text-xs font-medium text-ink-dim">
                                    {{ $page.props.auth.user.name.charAt(0) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium text-ink">{{ $page.props.auth.user.name }}</div>
                                    <div class="truncate text-xs text-ink-mute">{{ $page.props.auth.user.current_team?.name }}</div>
                                </div>
                                <svg class="size-4 text-ink-mute" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
                                </svg>
                            </button>
                        </template>
                        <template #content>
                            <div class="block px-4 py-2 font-mono text-xs uppercase tracking-wider text-ink-mute">Account</div>
                            <DropdownLink :href="route('profile.show')">Profile</DropdownLink>
                            <DropdownLink v-if="$page.props.jetstream.hasApiFeatures" :href="route('api-tokens.index')">API tokens</DropdownLink>

                            <template v-if="$page.props.jetstream.hasTeamFeatures">
                                <div class="border-t border-border-line" />
                                <div class="block px-4 py-2 font-mono text-xs uppercase tracking-wider text-ink-mute">Team</div>
                                <DropdownLink :href="route('teams.show', $page.props.auth.user.current_team)">Team settings</DropdownLink>
                                <DropdownLink v-if="$page.props.jetstream.canCreateTeams" :href="route('teams.create')">+ New team</DropdownLink>
                                <template v-if="$page.props.auth.user.all_teams.length > 1">
                                    <div class="border-t border-border-line" />
                                    <div class="block px-4 py-2 font-mono text-xs uppercase tracking-wider text-ink-mute">Switch team</div>
                                    <template v-for="team in $page.props.auth.user.all_teams" :key="team.id">
                                        <form @submit.prevent="switchToTeam(team)">
                                            <DropdownLink as="button">
                                                <div class="flex items-center gap-2">
                                                    <svg v-if="team.id == $page.props.auth.user.current_team_id" class="size-4 text-green-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                                    </svg>
                                                    <span v-else class="size-4" />
                                                    <span>{{ team.name }}</span>
                                                </div>
                                            </DropdownLink>
                                        </form>
                                    </template>
                                </template>
                            </template>

                            <div class="border-t border-border-line" />
                            <form @submit.prevent="logout">
                                <DropdownLink as="button">Log out</DropdownLink>
                            </form>
                        </template>
                    </Dropdown>
                </div>
            </aside>

            <!-- ───────────────────────── Top bar (everywhere) ───────────────────────── -->
            <div class="lg:pl-60">
                <div class="sticky top-0 z-20 flex h-12 items-center justify-between border-b border-border-line bg-bg/95 px-4 backdrop-blur sm:px-6 lg:px-8">
                    <!-- Hamburger (mobile) -->
                    <button
                        type="button"
                        aria-label="Open navigation"
                        class="-ml-1.5 inline-flex items-center justify-center rounded-none p-2.5 text-ink-dim hover:bg-surface-hi lg:hidden"
                        @click="showMobileNav = true"
                    >
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                    </button>

                    <!-- Latest news headline — sits where the page title was,
                         left-aligned with the PageHeader content below. Hidden
                         on mobile (the hamburger owns the left there). Falls
                         back to a spacer when there's no headline. -->
                    <component
                        :is="latestHeadline.url ? 'a' : 'div'"
                        v-if="latestHeadline"
                        :href="latestHeadline.url || undefined"
                        class="hidden min-w-0 flex-1 lg:flex lg:items-center lg:gap-2"
                    >
                        <span class="bp-ref flex-shrink-0">NEWS</span>
                        <span
                            class="truncate text-sm font-medium text-ink"
                            :class="latestHeadline.url ? 'hover:underline' : ''"
                        >{{ latestHeadline.text }}</span>
                    </component>
                    <div v-else class="hidden flex-1 lg:block"><!-- spacer --></div>

                    <div class="flex items-center gap-2">
                        <!-- Theme toggle: light = white sheet, dark = black sheet -->
                        <button
                            type="button"
                            class="inline-flex items-center rounded-none p-2 text-ink-dim hover:bg-surface-hi"
                            :aria-label="theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'"
                            :title="theme === 'dark' ? 'Light' : 'Dark'"
                            @click="toggleTheme"
                        >
                            <!-- Sun (shown in dark mode → click for light) -->
                            <svg v-if="theme === 'dark'" class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
                            </svg>
                            <!-- Moon (shown in light mode → click for dark) -->
                            <svg v-else class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                            </svg>
                        </button>

                        <!-- Notifications bell -->
                        <Dropdown align="right" width="80">
                            <template #trigger>
                                <button type="button" aria-label="Notifications" class="relative inline-flex items-center rounded-none p-2 text-ink-dim hover:bg-surface-hi">
                                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                                    </svg>
                                    <span v-if="notifications.length" class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 font-mono text-[10px] font-semibold text-white">
                                        {{ notifications.length }}
                                    </span>
                                </button>
                            </template>
                            <template #content>
                                <div class="flex items-center justify-between px-4 py-2">
                                    <span class="font-mono text-xs font-semibold uppercase tracking-wider text-ink-mute">Notifications</span>
                                    <button v-if="notifications.length" type="button" class="text-xs text-ink underline hover:text-ink-dim" @click="markNotificationsRead">
                                        Mark all read
                                    </button>
                                </div>
                                <div class="max-h-80 overflow-y-auto">
                                    <Link
                                        v-for="n in notifications"
                                        :key="n.id"
                                        :href="route('leads.index', n.lead_id ? { focus: n.lead_id } : {})"
                                        class="block border-t border-border-line px-4 py-3 text-sm text-ink-dim hover:bg-surface-hi"
                                    >
                                        {{ n.message }}
                                    </Link>
                                    <p v-if="!notifications.length" class="border-t border-border-line px-4 py-6 text-center text-sm text-ink-dim">
                                        You're all caught up.
                                    </p>
                                </div>
                            </template>
                        </Dropdown>

                        <!-- Mobile-only user dropdown (desktop has it in the sidebar) -->
                        <div class="lg:hidden">
                            <Dropdown align="right" width="48">
                                <template #trigger>
                                    <button v-if="$page.props.jetstream.managesProfilePhotos" class="flex rounded-full border-2 border-transparent">
                                        <img class="size-8 rounded-full object-cover" :src="$page.props.auth.user.profile_photo_url" :alt="$page.props.auth.user.name" />
                                    </button>
                                    <button v-else type="button" class="inline-flex items-center rounded-none px-3 py-2 text-sm font-medium text-ink-dim hover:bg-surface-hi">
                                        {{ $page.props.auth.user.name }}
                                    </button>
                                </template>
                                <template #content>
                                    <DropdownLink :href="route('profile.show')">Profile</DropdownLink>
                                    <DropdownLink v-if="$page.props.jetstream.hasApiFeatures" :href="route('api-tokens.index')">API tokens</DropdownLink>
                                    <div class="border-t border-border-line" />
                                    <form @submit.prevent="logout">
                                        <DropdownLink as="button">Log out</DropdownLink>
                                    </form>
                                </template>
                            </Dropdown>
                        </div>
                    </div>
                </div>

                <!-- Optional page header slot.
                     Pages that use the new PageHeader component should put it
                     in the default slot (it brings its own white-card wrapper).
                     Older pages that just put an <h2> in #header still render
                     correctly thanks to the white-card fallback wrapper here. -->
                <header v-if="$slots.header" class="border-b border-border-line bg-bg px-4 py-5 sm:px-6 lg:px-8">
                    <div class="mx-auto max-w-7xl">
                        <slot name="header" />
                    </div>
                </header>

                <main>
                    <slot />
                </main>
            </div>

            <!-- ───────────────────────── Mobile sidebar (overlay) ───────────────────────── -->
            <div v-if="showMobileNav" class="fixed inset-0 z-40 lg:hidden">
                <div class="fixed inset-0 bg-ink/40" @click="showMobileNav = false" />
                <div class="fixed inset-y-0 left-0 flex w-72 flex-col bg-bg border-r border-border-line shadow-sheet">
                    <div class="flex h-16 items-center justify-between border-b border-border-line px-5">
                        <Link :href="route('dashboard')" @click="showMobileNav = false">
                            <ApplicationMark class="block h-8 w-auto" />
                        </Link>
                        <button type="button" aria-label="Close navigation" class="rounded-none p-2 text-ink-dim hover:bg-surface-hi" @click="showMobileNav = false">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Agent picker (mobile) — full switch list, mirrors desktop.
                         Previous version only showed the current agent name as
                         text; mobile users couldn't switch agents at all. -->
                    <div v-if="currentAgent || teamAgents.length" class="border-b border-border-line px-3 py-3">
                        <div class="px-2 pb-1.5 font-mono text-xs font-semibold uppercase tracking-wider text-ink-mute">Agent</div>
                        <template v-for="agent in teamAgents" :key="agent.id">
                            <button
                                type="button"
                                class="flex w-full items-center gap-2 rounded-none px-2 py-1.5 text-left text-sm hover:bg-surface-hi"
                                :class="agent.id === currentAgent?.id ? 'text-ink' : 'text-ink-dim'"
                                @click="switchToAgent(agent); showMobileNav = false"
                            >
                                <svg v-if="agent.id === currentAgent?.id" class="size-4 text-green-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                                <span v-else class="size-4" />
                                <span class="flex-1 truncate">{{ agent.name }}</span>
                            </button>
                        </template>
                        <!-- Mirror the desktop dropdown's "+ New agent" shortcut.
                             Lands on agents.index (NOT onboarding.intro — that
                             wizard is for the team's first agent only). -->
                        <Link
                            :href="route('agents.index')"
                            class="flex w-full items-center gap-2 rounded-none px-2 py-1.5 text-left text-sm text-ink-dim hover:bg-surface-hi"
                        >
                            <span class="size-4" />
                            <span class="flex-1">+ New agent</span>
                        </Link>
                    </div>

                    <nav class="flex-1 space-y-5 overflow-y-auto px-3 py-4" @click="handleMobileNavClick">
                        <SidebarLink :href="route('dashboard')" active-pattern="dashboard">Dashboard</SidebarLink>
                        <SidebarLink :href="route('chat.index')" active-pattern="chat.index">Chat</SidebarLink>
                        <div>
                            <div class="px-2 pb-1 font-mono text-xs font-semibold uppercase tracking-wider text-ink-mute">Inbox</div>
                            <div class="space-y-0.5">
                                <SidebarLink :href="route('leads.index')" active-pattern="leads.*">Leads</SidebarLink>
                                <SidebarLink :href="route('conversations.index')" :active-pattern="['conversations.index', 'conversations.show']">Conversations</SidebarLink>
                            </div>
                        </div>
                        <div>
                            <div class="px-2 pb-1 font-mono text-xs font-semibold uppercase tracking-wider text-ink-mute">Knowledge</div>
                            <div class="space-y-0.5">
                                <SidebarLink :href="route('knowledge.index')" active-pattern="knowledge.*">Documents</SidebarLink>
                            </div>
                        </div>
                        <div>
                            <div class="px-2 pb-1 font-mono text-xs font-semibold uppercase tracking-wider text-ink-mute">Workspace</div>
                            <div class="space-y-0.5">
                                <SidebarLink :href="route('agents.index')" :active-pattern="['agents.index', 'agents.show']">Agents</SidebarLink>
                                <SidebarLink v-if="isAdmin" :href="route('agents.faq.index')" active-pattern="agents.faq.*">FAQ</SidebarLink>
                                <SidebarLink v-if="automationsEnabled" :href="route('agents.activity.index')" active-pattern="agents.activity.*">Activity</SidebarLink>
                                <SidebarLink :href="route('agents.versions.index')" active-pattern="agents.versions.*">Versions</SidebarLink>
                                <SidebarLink :href="route('install.index')" active-pattern="install.*">Install</SidebarLink>
                                <SidebarLink :href="route('billing.index')" active-pattern="billing.*">Billing</SidebarLink>
                            </div>
                        </div>
                        <div v-if="isAdmin">
                            <div class="px-2 pb-1 font-mono text-xs font-semibold uppercase tracking-wider text-ink-mute">Admin</div>
                            <div class="space-y-0.5">
                                <SidebarLink :href="route('agents.actions.index')" active-pattern="agents.actions.*">
                                    Actions
                                    <template v-if="!automationsEnabled" #badge>
                                        <span class="rounded-none bg-surface-hi px-1.5 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wider text-ink-mute">Soon</span>
                                    </template>
                                </SidebarLink>
                                <SidebarLink v-if="hasRoute('hermes.metrics')" :href="route('hermes.metrics')" active-pattern="hermes.metrics">Metrics</SidebarLink>
                                <SidebarLink v-if="hasRoute('architecture.graph')" :href="route('architecture.graph')" active-pattern="architecture.graph">Architecture</SidebarLink>
                            </div>
                        </div>
                    </nav>
                </div>
            </div>
        </div>

        <!-- Bottom-of-page footer with legal/support links. Lives
             OUTSIDE the main flex column so it sits at the very bottom
             regardless of slot height. -->
        <SiteFooter />

        <!-- App-wide confirmation modal. Pages call confirm({...}) from
             useConfirm; the promise resolves true/false. Single mount
             point keeps modal styling consistent across the app. -->
        <AppConfirmDialog />
    </div>
</template>
