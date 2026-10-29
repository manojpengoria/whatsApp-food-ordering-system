"use strict";

const { config } = require("./config");

/** Business-rule error from Laravel (e.g. item_not_found). Safe to show its message to the customer. */
class ApiError extends Error {
    constructor(code, message, status) {
        super(message);
        this.code = code;
        this.status = status;
    }
}

/** Laravel is down or misconfigured. Show a generic "try again" message. */
class ApiUnavailableError extends Error {}

async function request(method, path, { query, body, timeoutMs = 8000 } = {}) {
    const url = new URL(config.laravel.apiUrl + path);
    for (const [k, v] of Object.entries(query || {})) {
        if (v !== undefined && v !== null) url.searchParams.set(k, String(v));
    }

    let response;
    try {
        response = await fetch(url, {
            method,
            headers: {
                Authorization: `Bearer ${config.internalToken}`,
                Accept: "application/json",
                ...(body ? { "Content-Type": "application/json" } : {}),
            },
            body: body ? JSON.stringify(body) : undefined,
            signal: AbortSignal.timeout(timeoutMs),
        });
    } catch (err) {
        throw new ApiUnavailableError(`Laravel not reachable at ${config.laravel.apiUrl}: ${err.message}`);
    }

    const data = await response.json().catch(() => null);
    if (response.ok) return data;

    if (response.status === 401 || response.status === 503 || response.status >= 500 || !data) {
        throw new ApiUnavailableError(`Laravel ${response.status}: ${data?.message || "no JSON body"}`);
    }
    throw new ApiError(data.error || "error", data.message || "Something went wrong.", response.status);
}

module.exports = {
    ApiError,
    ApiUnavailableError,
    restaurant: () => request("GET", "/restaurant"),
    menu: () => request("GET", "/menu"),
    upsertCustomer: (waId, name) => request("POST", "/customers", { body: { wa_id: waId, name } }),
    cart: (waId, orderType) => request("GET", "/cart", { query: { wa_id: waId, order_type: orderType } }),
    addToCart: (waId, name, code, quantity) => request("POST", "/cart/items", { body: { wa_id: waId, name, code, quantity } }),
    setCartQty: (waId, code, quantity) => request("PUT", `/cart/items/${encodeURIComponent(code)}`, { body: { wa_id: waId, quantity } }),
    replaceCart: (waId, name, items) => request("PUT", "/cart", { body: { wa_id: waId, name, items } }),
    clearCart: (waId) => request("DELETE", "/cart", { query: { wa_id: waId } }),
    orders: (waId, limit = 3) => request("GET", "/orders", { query: { wa_id: waId, limit } }),
    placeOrder: (payload) => request("POST", "/orders", { body: payload, timeoutMs: 15000 }),
    health: () => request("GET", "/restaurant", { timeoutMs: 1500 }),
    confirmWhatsappPayment: (payload) => request("POST", "/payments/whatsapp", { body: payload }),
    abandonOrder: (number, waId, reason) => request("POST", `/orders/${encodeURIComponent(number)}/abandon`, { body: { wa_id: waId, reason } }),
    forwardRazorpay: (raw, signature) => request("POST", "/payments/razorpay", { body: { raw, signature }, timeoutMs: 15000 }),
};
