// Global test setup for the Vitest (jsdom) frontend layer.
//
// Ziggy's `route()` is injected app-wide via ZiggyVue in app.js, so components
// that call it have no import to mock. Provide a global stub that returns the
// name as a URL placeholder; individual tests can override window.route.
globalThis.route = (name, params) => {
    const query = params ? `?${new URLSearchParams(params).toString()}` : '';
    return `/__route__/${name}${query}`;
};
