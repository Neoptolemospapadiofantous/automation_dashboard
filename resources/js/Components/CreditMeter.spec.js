import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import CreditMeter from '@/Components/CreditMeter.vue';

// Inertia's usePage() supplies shared props; stub it per-test with a billing
// payload. <Link> is stubbed so we don't pull in the router.
function mountWithBilling(billing) {
    vi.doMock('@inertiajs/vue3', () => ({
        usePage: () => ({ props: { billing } }),
        Link: { template: '<a><slot /></a>' },
    }));
}

async function freshMount(billing) {
    vi.resetModules();
    mountWithBilling(billing);
    const { default: Component } = await import('@/Components/CreditMeter.vue');
    return mount(Component, { global: { mocks: { route: globalThis.route } } });
}

describe('CreditMeter', () => {
    it('renders nothing when there is no billing context', async () => {
        const wrapper = await freshMount(null);
        expect(wrapper.find('div').exists()).toBe(false);
    });

    it('computes the used percentage and clamps at 100', async () => {
        const wrapper = await freshMount({
            plan_label: 'Pro', credits_total: 1000, credits_used: 1500,
            credits_remaining: 0, topup_balance: 0, max_agents: 1000, agents_count: 1,
        });
        const bar = wrapper.find('[style]');
        expect(bar.attributes('style')).toContain('width: 100%');
    });

    it('uses the warning tone once usage crosses 80%', async () => {
        const wrapper = await freshMount({
            plan_label: 'Pro', credits_total: 1000, credits_used: 850,
            credits_remaining: 150, topup_balance: 0, max_agents: 1000, agents_count: 1,
        });
        // The brand's signal yellow, not Tailwind's amber-500 — see app.css.
        expect(wrapper.html()).toContain('bg-signal');
    });

    it('tracks the message cap instead of credits when the own key covers the agent', async () => {
        const wrapper = await freshMount({
            plan_label: 'Operator', credits_used: 100, credits_total: 25000, credits_remaining: 24900,
            topup_balance: 0, max_agents: 5, agents_count: 1,
            own_key: { active: true, has_key: true, used: 5000, cap: 25000 },
        });
        expect(wrapper.html()).toContain('20,000 msgs left');
        expect(wrapper.html()).toContain('5,000 of 25,000 messages on your key');
        expect(wrapper.html()).toContain('24,900 credits held');
        expect(wrapper.find('.h-full').attributes('style')).toContain('width: 20%');
    });

    it('falls back to credits, and says so, once the own-key cap is reached', async () => {
        const wrapper = await freshMount({
            plan_label: 'Operator', credits_used: 100, credits_total: 25000, credits_remaining: 24900,
            topup_balance: 0, max_agents: 5, agents_count: 1,
            own_key: { active: false, has_key: true, used: 25000, cap: 25000 },
        });
        expect(wrapper.html()).toContain('24,900 available');
        expect(wrapper.html()).toContain('own key: monthly cap reached');
    });

    it('stays in the normal tone below 80%', async () => {
        const wrapper = await freshMount({
            plan_label: 'Free', credits_total: 1000, credits_used: 100,
            credits_remaining: 900, topup_balance: 0, max_agents: 1000, agents_count: 1,
        });
        expect(wrapper.html()).toContain('bg-ink-dim');
        expect(wrapper.html()).not.toContain('bg-signal');
    });
});
