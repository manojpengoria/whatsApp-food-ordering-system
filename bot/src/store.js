"use strict";

/**
 * The ONLY tables this bot writes: webhook_events, whatsapp_conversations, whatsapp_messages.
 * (Laravel owns their schema via migrations.) Everything else goes through Laravel's internal API.
 */
const { pool } = require("./db");

const NOW = "UTC_TIMESTAMP(3)";

async function recordWebhookEvent(payload, messageCount, statusCount) {
    const [res] = await pool.execute(
        `INSERT INTO webhook_events (payload, message_count, status_count, received_at) VALUES (?, ?, ?, ${NOW})`,
        [JSON.stringify(payload), messageCount, statusCount],
    );
    return res.insertId;
}

async function finishWebhookEvent(id, error = null) {
    await pool.execute(`UPDATE webhook_events SET processed_at = ${NOW}, error = ? WHERE id = ?`, [
        error ? String(error).slice(0, 2000) : null,
        id,
    ]);
}

async function getConversation(waId) {
    const [rows] = await pool.execute("SELECT * FROM whatsapp_conversations WHERE wa_id = ?", [waId]);
    return rows[0] ? normalizeConversation(rows[0]) : null;
}

async function upsertConversation(waId, profileName) {
    await pool.execute(
        `INSERT INTO whatsapp_conversations (wa_id, profile_name, state, created_at, updated_at)
         VALUES (?, ?, 'idle', ${NOW}, ${NOW}) AS new
         ON DUPLICATE KEY UPDATE profile_name = COALESCE(new.profile_name, whatsapp_conversations.profile_name),
                                 updated_at = ${NOW}`,
        [waId, profileName || null],
    );
    return getConversation(waId);
}

async function saveState(conversationId, state, context = null) {
    await pool.execute(`UPDATE whatsapp_conversations SET state = ?, context = ?, updated_at = ${NOW} WHERE id = ?`, [
        state,
        context ? JSON.stringify(context) : null,
        conversationId,
    ]);
}

async function touch(conversationId, column) {
    if (!["last_inbound_at", "last_outbound_at"].includes(column)) throw new Error("bad column");
    await pool.execute(`UPDATE whatsapp_conversations SET ${column} = ${NOW}, updated_at = ${NOW} WHERE id = ?`, [conversationId]);
}

/** Returns false if this Meta message id was already stored (duplicate webhook delivery). */
async function insertInbound({ waMessageId, conversationId, webhookEventId, waId, type, body }) {
    try {
        await pool.execute(
            `INSERT INTO whatsapp_messages
               (wa_message_id, conversation_id, webhook_event_id, wa_id, direction, type, body, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'in', ?, ?, 'received', ${NOW}, ${NOW})`,
            [waMessageId, conversationId, webhookEventId, waId, type, body],
        );
        return true;
    } catch (err) {
        if (err.code === "ER_DUP_ENTRY") return false;
        throw err;
    }
}

/**
 * Creates the outbound row before sending. With a clientRef, an existing row is returned
 * instead (so a retried notification is never sent twice).
 */
async function createOutbound({ clientRef = null, conversationId, waId, type = "text", body }) {
    try {
        const [res] = await pool.execute(
            `INSERT INTO whatsapp_messages
               (client_ref, conversation_id, wa_id, direction, type, body, status, created_at, updated_at)
             VALUES (?, ?, ?, 'out', ?, ?, 'sending', ${NOW}, ${NOW})`,
            [clientRef, conversationId, waId, type, body],
        );
        return { id: res.insertId, existing: null };
    } catch (err) {
        if (err.code === "ER_DUP_ENTRY" && clientRef) {
            const [rows] = await pool.execute("SELECT * FROM whatsapp_messages WHERE client_ref = ?", [clientRef]);
            return { id: rows[0].id, existing: rows[0] };
        }
        throw err;
    }
}

async function markSent(id, waMessageId) {
    await pool.execute(
        `UPDATE whatsapp_messages SET wa_message_id = ?, status = 'sent', error = NULL, updated_at = ${NOW} WHERE id = ?`,
        [waMessageId, id],
    );
}

async function markFailed(id, error) {
    await pool.execute(`UPDATE whatsapp_messages SET status = 'failed', error = ?, updated_at = ${NOW} WHERE id = ?`, [
        String(error).slice(0, 2000),
        id,
    ]);
}

async function markSending(id, body) {
    await pool.execute(`UPDATE whatsapp_messages SET status = 'sending', body = ?, error = NULL, updated_at = ${NOW} WHERE id = ?`, [
        body,
        id,
    ]);
}

const STATUS_RANK = { sending: 0, sent: 1, delivered: 2, read: 3 };

/** Applies Meta delivery receipts. Never moves backwards (read -> delivered). */
async function applyStatus(waMessageId, status, error = null) {
    if (status === "failed") {
        await pool.execute(`UPDATE whatsapp_messages SET status = 'failed', error = ?, updated_at = ${NOW} WHERE wa_message_id = ?`, [
            error ? String(error).slice(0, 2000) : null,
            waMessageId,
        ]);
        return;
    }
    if (!(status in STATUS_RANK)) return;
    await pool.execute(
        `UPDATE whatsapp_messages SET status = ?, updated_at = ${NOW}
         WHERE wa_message_id = ? AND direction = 'out'
           AND FIELD(status, 'sending', 'sent', 'delivered', 'read') < FIELD(?, 'sending', 'sent', 'delivered', 'read')`,
        [status, waMessageId, status],
    );
}

function normalizeConversation(row) {
    let context = row.context;
    if (typeof context === "string") {
        try {
            context = JSON.parse(context);
        } catch {
            context = null;
        }
    }
    return { ...row, context: context || {} };
}

module.exports = {
    recordWebhookEvent,
    finishWebhookEvent,
    getConversation,
    upsertConversation,
    saveState,
    touch,
    insertInbound,
    createOutbound,
    markSent,
    markFailed,
    markSending,
    applyStatus,
};
