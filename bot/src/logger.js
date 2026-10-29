"use strict";

/** 919876543210 -> 91******3210. Never log full phone numbers. */
function maskPhone(waId) {
    const s = String(waId || "");
    if (s.length <= 6) return "*".repeat(s.length);
    return s.slice(0, 2) + "*".repeat(s.length - 6) + s.slice(-4);
}

function line(level, msg, meta) {
    const time = new Date().toISOString();
    const extra = meta && Object.keys(meta).length ? " " + JSON.stringify(meta) : "";
    const out = `${time} ${level.toUpperCase().padEnd(5)} ${msg}${extra}`;
    if (level === "error" || level === "warn") console.error(out);
    else console.log(out);
}

module.exports = {
    maskPhone,
    info: (msg, meta) => line("info", msg, meta),
    warn: (msg, meta) => line("warn", msg, meta),
    error: (msg, meta) => line("error", msg, meta),
};
