import { computed } from 'vue';

/**
 * Mirrors the backend regex rules in app/Http/Controllers/OnboardingController.php
 * (credentialRules) so the form catches obvious typos before round-tripping
 * Voiceflow. Backend remains authoritative — these are UX hints, not the
 * security boundary.
 *
 * @param {object} form         The Inertia useForm() instance
 * @param {object} [options]
 * @param {boolean} [options.requireKey=true]      api_key must be present
 * @param {boolean} [options.requireProject=true]  project_id must be present
 */
export function useVoiceflowValidation(form, options = {}) {
    const requireKey = options.requireKey ?? true;
    const requireProject = options.requireProject ?? true;

    const PATTERNS = {
        api_key: /^VF\.DM\.[A-Za-z0-9]+\.[A-Za-z0-9]+$/,
        project_id: /^[a-f0-9]{24}$/i,
        environment: /^[A-Za-z0-9_-]{2,32}$/,
        workspace_api_key: /^VF\..+/,
    };

    const errors = computed(() => {
        const e = {};
        const k = (form.voiceflow_api_key ?? '').trim();
        const p = (form.voiceflow_project_id ?? '').trim();
        const env = (form.voiceflow_environment ?? '').trim();
        const ws = (form.voiceflow_workspace_api_key ?? '').trim();

        if (requireKey && !k) {
            e.voiceflow_api_key = 'Required.';
        } else if (k && !PATTERNS.api_key.test(k)) {
            e.voiceflow_api_key = 'Should look like VF.DM.xxxxxxxx.yyyy (Dialog Manager key).';
        }

        if (requireProject && !p) {
            e.voiceflow_project_id = 'Required.';
        } else if (p && !PATTERNS.project_id.test(p)) {
            e.voiceflow_project_id = 'Should be a 24-character hex string (e.g. 64f8a1b2c3d4e5f6a7b8c9d0).';
        }

        if (env && !PATTERNS.environment.test(env)) {
            e.voiceflow_environment = 'Short alphanumeric label (e.g. main, development).';
        }

        if (ws && !PATTERNS.workspace_api_key.test(ws)) {
            e.voiceflow_workspace_api_key = 'Workspace keys start with VF. (this is the workspace token, not the DM key).';
        }

        return e;
    });

    const isValid = computed(() => Object.keys(errors.value).length === 0);

    /**
     * Combine our client-side errors with any backend errors Laravel sent
     * back via the Inertia `form.errors` bag. The backend wins on the same
     * field (it's authoritative); client-only errors only show for fields
     * the backend hasn't complained about yet.
     */
    function errorFor(field) {
        return form.errors[field] || errors.value[field] || '';
    }

    return { errors, isValid, errorFor };
}
