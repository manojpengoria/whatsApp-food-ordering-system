"use strict";

const { config } = require("./config");
const store = require("./store");
const log = require("./logger");

const MAX_TEXT = 4096;

class SendError extends Error {
    constructor(message, { permanent = false, metaCode = null } = {}) {
        super(message);
        this.permanent = permanent;
        this.metaCode = metaCode;
    }
}

async function callGraph(to, message) {
    const { graphBaseUrl, graphVersion, phoneNumberId, accessToken } = config.whatsapp;
    const url = `${graphBaseUrl}/${graphVersion}/${phoneNumberId}/messages`;

    let response;
    try {
        response = await fetch(url, {
            method: "POST",
            headers: { Authorization: `Bearer ${accessToken}`, "Content-Type": "application/json" },
            body: JSON.stringify({ messaging_product: "whatsapp", recipient_type: "individual", to, ...message }),
            signal: AbortSignal.timeout(10_000),
        });
    } catch (err) {
        throw new SendError(`Network error calling WhatsApp: ${err.message}`);
    }

    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
        const e = result.error || {};
        const msg = `WhatsApp API ${response.status}: ${e.message || "unknown error"}${e.code ? ` (code ${e.code})` : ""}`;
        // 4xx (bad token, invalid number, outside window) won't succeed on retry; 429/5xx might.
        const permanent = response.status >= 400 && response.status < 500 && response.status !== 429;
        throw new SendError(msg, { permanent, metaCode: e.code ?? null });
    }
    return result.messages?.[0]?.id || null;
}

/**
 * Sends a text and records it in whatsapp_messages.
 * Returns { messageId, duplicate } — duplicate=true if clientRef was already sent before.
 */
async function sendText(conversation, body, { clientRef = null } = {}) {
    const text = body.length > MAX_TEXT ? body.slice(0, MAX_TEXT - 1) + "…" : body;
    return send(conversation, { type: "text", text: { body: text, preview_url: false } }, { clientRef, logBody: text, logType: "text" });
}

/** "View catalog" message: opens the WhatsApp photo catalog linked to this number. */
async function sendCatalog(conversation, body, thumbnailRetailerId = null) {
    const interactive = {
        type: "catalog_message",
        body: { text: body.slice(0, 1024) },
        action: { name: "catalog_message", ...(thumbnailRetailerId ? { parameters: { thumbnail_product_retailer_id: thumbnailRetailerId } } : {}) },
    };
    return send(conversation, { type: "interactive", interactive }, { logBody: body, logType: "catalog" });
}

/** Any interactive message (order_details, order_status, ...). */
async function sendInteractive(conversation, interactive, logBody, logType = "interactive", { clientRef = null } = {}) {
    return send(conversation, { type: "interactive", interactive }, { clientRef, logBody, logType });
}

/**
 * WhatsApp Pay lookup: GET /{phone-number-id}/payments/{configuration}/{reference_id}.
 * Meta says not to trust the webhook alone, so we always confirm here.
 */
async function lookupPayment(configurationName, referenceId) {
    const { graphBaseUrl, graphVersion, phoneNumberId, accessToken } = config.whatsapp;
    const url = `${graphBaseUrl}/${graphVersion}/${phoneNumberId}/payments/${encodeURIComponent(configurationName)}/${encodeURIComponent(referenceId)}`;
    const res = await fetch(url, { headers: { Authorization: `Bearer ${accessToken}` }, signal: AbortSignal.timeout(10_000) });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new SendError(`Payment lookup failed: ${data.error?.message || res.status}`, { permanent: res.status < 500 });
    const p = (data.payments || [])[0] || {};
    const ok = (p.transactions || []).find((t) => t.status === "success") || (p.transactions || [])[0] || {};
    return {
        status: p.status || "pending",
        amount_value: Number(p.amount?.value || 0),
        transaction_id: ok.id || null,
        pg_transaction_id: ok.pg_transaction_id || null,
        method: ok.method?.type || null,
        error: ok.error?.reason || null,
    };
}

async function send(conversation, message, { clientRef = null, logBody, logType }) {
    const text = logBody;
    const { id, existing } = await store.createOutbound({
        clientRef,
        conversationId: conversation.id,
        waId: conversation.wa_id,
        type: logType,
        body: text,
    });

    if (existing) {
        if (["sent", "delivered", "read"].includes(existing.status)) {
            return { messageId: existing.wa_message_id, duplicate: true };
        }
        if (existing.status === "sending") {
            throw new SendError("This notification is already being sent.", { permanent: false });
        }
        await store.markSending(id, text); // previously failed: try again on the same row
    }

    try {
        const messageId = await callGraph(conversation.wa_id, message);
        await store.markSent(id, messageId);
        await store.touch(conversation.id, "last_outbound_at");
        log.info("Message sent", { to: log.maskPhone(conversation.wa_id), id: messageId });
        return { messageId, duplicate: false };
    } catch (err) {
        await store.markFailed(id, err.message);
        log.error("Message send failed", { to: log.maskPhone(conversation.wa_id), error: err.message });
        throw err;
    }
}

module.exports = { sendText, sendCatalog, sendInteractive, lookupPayment, SendError };
