"use strict";

/**
 * Conversation logic. State lives in whatsapp_conversations.state/context;
 * all prices, totals and rules come from Laravel.
 */
const api = require("./laravel");
const store = require("./store");
const msg = require("./messages");
const whatsapp = require("./whatsapp");
const log = require("./logger");

const STATE = {
    IDLE: "idle",
    TYPE: "checkout_type",
    ADDRESS: "checkout_address",
    PAY: "checkout_payment",
    CONFIRM: "checkout_confirm",
};

const GREETING = /^(hi+|hello|hey|hii+|start|home|namaste|helo)$/i;
const ADD = /^add\s+([a-z0-9]{1,10})(?:\s*[x×*]?\s*(\d{1,3}))?$/i;
const REMOVE = /^(?:remove|del|delete)\s+([a-z0-9]{1,10})$/i;

/**
 * Handles one inbound text. `reply(text)` sends a WhatsApp message.
 * @param {{ conv: object, name: string|null, text: string, messageId: string, reply: (t: string) => Promise }} ctx
 */
async function handleText(ctx) {
    const { conv } = ctx;
    const text = ctx.text.trim().replace(/\s+/g, " ");
    const word = text.toUpperCase();

    try {
        // Commands that work in every state
        if (GREETING.test(text)) return await showWelcome(ctx);
        if (word === "CANCEL" || word === "STOP") {
            if (conv.state !== STATE.IDLE) {
                await setState(conv, STATE.IDLE);
                return await ctx.reply("Checkout cancelled. Your cart is saved. 🛒\n\nReply *2* to see it or *HI* for options.");
            }
            return await showWelcome(ctx);
        }

        const add = text.match(ADD);
        if (add) return await addItem(ctx, add[1].toUpperCase(), add[2] ? Number(add[2]) : 1);
        const remove = text.match(REMOVE);
        if (remove) return await setQty(ctx, remove[1].toUpperCase(), 0);
        if (word === "CLEAR") return await clearCart(ctx);
        if (word === "CHECKOUT" || word === "ORDER" || word === "PLACE ORDER") return await startCheckout(ctx);
        if (word === "MENU") return await showMenu(ctx);
        if (word === "LIST" || word === "MENU LIST") return await showMenu(ctx, { forceText: true });
        if (word === "CART") return await showCart(ctx);
        if (["TRACK", "STATUS", "ORDERS", "MY ORDERS"].includes(word)) return await showOrders(ctx);
        if (word === "PAID" && conv.state === STATE.IDLE) {
            return await ctx.reply("🙏 Thanks! As soon as the payment is confirmed you'll get a message here and the kitchen starts on your order.");
        }

        // State-specific replies
        switch (conv.state) {
            case STATE.TYPE:
                return await onOrderType(ctx, word);
            case STATE.ADDRESS:
                return await onAddress(ctx, text, word);
            case STATE.PAY:
                return await onPayment(ctx, word);
            case STATE.CONFIRM:
                return await onConfirm(ctx, word);
            default:
                return await onIdle(ctx, word);
        }
    } catch (err) {
        if (err instanceof api.ApiError) {
            return ctx.reply(`⚠️ ${err.message}`);
        }
        if (err instanceof api.ApiUnavailableError) {
            log.error("Laravel unavailable", { error: err.message });
            return ctx.reply(msg.unavailable);
        }
        throw err;
    }
}

async function onIdle(ctx, word) {
    switch (word) {
        case "1":
            return showMenu(ctx);
        case "2":
            return showCart(ctx);
        case "3":
            return showOrders(ctx);
        case "4":
        case "INFO":
            return ctx.reply(msg.info(await api.restaurant()));
        case "5":
        case "CONTACT":
            return ctx.reply(msg.contact(await api.restaurant()));
        default:
            return ctx.reply(msg.help);
    }
}

async function showWelcome(ctx) {
    await setState(ctx.conv, STATE.IDLE);
    if (ctx.name) await api.upsertCustomer(ctx.conv.wa_id, ctx.name);
    return ctx.reply(msg.welcome(await api.restaurant(), ctx.name));
}

async function showMenu(ctx, { forceText = false } = {}) {
    await setState(ctx.conv, STATE.IDLE);
    const restaurant = await api.restaurant();

    if (restaurant.catalog_enabled && !forceText) {
        try {
            return await whatsapp.sendCatalog(ctx.conv, msg.catalogIntro(restaurant), restaurant.catalog_thumbnail_retailer_id);
        } catch (err) {
            // Meta refused it (e.g. catalog not linked to this number yet): fall back to the text menu.
            if (!(err instanceof whatsapp.SendError)) throw err;
            log.warn("Catalog message failed, sending text menu", { error: err.message });
        }
    }
    const menu = await api.menu();
    return ctx.reply(msg.menu(menu, restaurant));
}

/**
 * Customer tapped "Send cart" in the WhatsApp catalog.
 * @param {{ conv: object, name: string|null, items: {retailer_id: string, quantity: number}[], reply: Function }} ctx
 */
async function handleCatalogOrder(ctx) {
    try {
        const { cart, rejected, reduced = [] } = await api.replaceCart(ctx.conv.wa_id, ctx.name, ctx.items);
        await setState(ctx.conv, STATE.IDLE);
        await ctx.reply(msg.catalogCartReceived(cart, rejected, reduced));
        if (!cart.is_empty) return await startCheckout(ctx);
    } catch (err) {
        if (err instanceof api.ApiError) return ctx.reply(`⚠️ ${err.message}`);
        if (err instanceof api.ApiUnavailableError) {
            log.error("Laravel unavailable", { error: err.message });
            return ctx.reply(msg.unavailable);
        }
        throw err;
    }
}

async function showCart(ctx) {
    await setState(ctx.conv, STATE.IDLE);
    const { cart } = await api.cart(ctx.conv.wa_id);
    return ctx.reply(msg.cart(cart));
}

async function showOrders(ctx) {
    await setState(ctx.conv, STATE.IDLE);
    const { orders } = await api.orders(ctx.conv.wa_id, 3);
    return ctx.reply(msg.orders(orders));
}

async function addItem(ctx, code, qty) {
    if (qty < 1) return ctx.reply("Quantity must be at least 1. Example: *ADD 5 2*");
    await setState(ctx.conv, STATE.IDLE);
    const { cart } = await api.addToCart(ctx.conv.wa_id, ctx.name, code, qty);
    return ctx.reply(msg.added(cart, code));
}

async function setQty(ctx, code, qty) {
    await setState(ctx.conv, STATE.IDLE);
    const { cart } = await api.setCartQty(ctx.conv.wa_id, code, qty);
    return ctx.reply(`🗑️ Removed item ${code}.\n\n${msg.cart(cart)}`);
}

async function clearCart(ctx) {
    await setState(ctx.conv, STATE.IDLE);
    await api.clearCart(ctx.conv.wa_id);
    return ctx.reply("🗑️ Your cart is now empty.\n\nReply *1* to see the menu.");
}

async function startCheckout(ctx) {
    const [restaurant, { cart }] = await Promise.all([api.restaurant(), api.cart(ctx.conv.wa_id)]);

    if (cart.is_empty) {
        await setState(ctx.conv, STATE.IDLE);
        return ctx.reply("🛒 Your cart is empty.\n\nReply *1* to see the menu, then *ADD code qty*.");
    }
    if (cart.unavailable_items.length) {
        await setState(ctx.conv, STATE.IDLE);
        return ctx.reply(msg.cart(cart));
    }
    if (!restaurant.accepting_orders) {
        await setState(ctx.conv, STATE.IDLE);
        return ctx.reply(`⏸️ Sorry, we're not taking orders right now.\n🕙 Hours: ${restaurant.hours}\n\nYour cart is saved.`);
    }

    await setState(ctx.conv, STATE.TYPE, { delivery_enabled: restaurant.delivery_enabled });
    return ctx.reply(msg.askOrderType(restaurant));
}

async function onOrderType(ctx, word) {
    const deliveryEnabled = ctx.conv.context.delivery_enabled !== false;

    if ((word === "1" || word === "DELIVERY") && deliveryEnabled) {
        const { customer } = await api.upsertCustomer(ctx.conv.wa_id, ctx.name);
        const suggested = customer.last_delivery_address || null;
        await setState(ctx.conv, STATE.ADDRESS, { suggested_address: suggested });
        return ctx.reply(msg.askAddress(suggested));
    }
    if (word === "2" || word === "TAKEAWAY" || word === "PICKUP") {
        return goToConfirm(ctx, "takeaway", null);
    }
    return ctx.reply(deliveryEnabled ? "Please reply *1* for delivery or *2* for takeaway." : "Please reply *2* for takeaway, or *CANCEL*.");
}

async function onAddress(ctx, text, word) {
    const suggested = ctx.conv.context.suggested_address;
    if (word === "YES" && suggested) return goToConfirm(ctx, "delivery", suggested);
    if (text.length < 10) {
        return ctx.reply("That address looks too short. Please send the full address with house no., street and area.");
    }
    return goToConfirm(ctx, "delivery", text.slice(0, 500));
}

async function goToConfirm(ctx, type, address) {
    const [{ cart }, restaurant] = await Promise.all([api.cart(ctx.conv.wa_id, type), api.restaurant()]);
    if (cart.is_empty) {
        await setState(ctx.conv, STATE.IDLE);
        return ctx.reply("🛒 Your cart is empty.\n\nReply *1* to see the menu.");
    }
    const methods = restaurant.payment_methods?.length ? restaurant.payment_methods : ["cash"];
    if (methods.length > 1) {
        await setState(ctx.conv, STATE.PAY, { order_type: type, address, methods });
        return ctx.reply(msg.askPayment(methods));
    }
    await setState(ctx.conv, STATE.CONFIRM, { order_type: type, address, payment_method: methods[0] });
    return ctx.reply(msg.confirm(cart, type, address, methods[0]));
}

async function onPayment(ctx, word) {
    const { order_type: type, address, methods = ["cash"] } = ctx.conv.context;
    const pick = methods[Number(word) - 1];
    if (!pick) return ctx.reply(msg.askPayment(methods));
    const { cart } = await api.cart(ctx.conv.wa_id, type);
    await setState(ctx.conv, STATE.CONFIRM, { order_type: type, address, payment_method: pick });
    return ctx.reply(msg.confirm(cart, type, address, pick));
}

/** After an order is placed: start the online payment (WhatsApp Pay or link) if one was chosen. */
async function sendPaymentStep(ctx, order) {
    const p = order.payment || {};
    if (order.status !== "awaiting_payment") return;
    try {
        if (p.method === "whatsapp_pay" && p.order_details) {
            await whatsapp.sendInteractive(
                ctx.conv,
                {
                    type: "order_details",
                    body: { text: msg.orderDetailsBody(order, p.window_minutes || 15).slice(0, 1024) },
                    footer: { text: "Secure payment on WhatsApp" },
                    action: { name: "review_and_pay", parameters: p.order_details },
                },
                `[Review and Pay] ${order.number} ${order.total}`,
                "order_details",
            );
            return;
        }
        if (p.method === "razorpay" && p.link_url) {
            await ctx.reply(msg.payOnline(order, p.link_url));
            return;
        }
        throw new Error(`no ${p.method} payment data`);
    } catch (err) {
        // Meta refused the payment message (e.g. configuration not active) or no link: don't leave a hidden order behind.
        log.error("Could not start online payment", { order: order.number, error: err.message });
        await api.abandonOrder(order.number, ctx.conv.wa_id, "Online payment could not be started").catch(() => {});
        await ctx.reply(msg.payStartFailed());
    }
}

async function onConfirm(ctx, word) {
    if (["NO", "N"].includes(word)) {
        await setState(ctx.conv, STATE.IDLE);
        return ctx.reply("Okay, order not placed. Your cart is saved. 🛒\n\nReply *2* to see it or *HI* for options.");
    }
    if (!["YES", "Y", "CONFIRM", "OK", "HAAN", "HA"].includes(word)) {
        return ctx.reply("Please reply *YES* to place the order or *NO* to cancel.");
    }

    const { order_type: orderType, address, payment_method: paymentMethod = "cash" } = ctx.conv.context;
    try {
        const { order, created } = await api.placeOrder({
            wa_id: ctx.conv.wa_id,
            name: ctx.name,
            order_type: orderType,
            delivery_address: address,
            payment_method: paymentMethod,
            // The WhatsApp message id makes a re-delivered "YES" return the same order.
            idempotency_key: `wa:${ctx.messageId}`,
        });
        await setState(ctx.conv, STATE.IDLE);
        log.info("Order placed", { order: order.number, from: log.maskPhone(ctx.conv.wa_id) });
        await ctx.reply(msg.placed(order));
        if (created !== false) await sendPaymentStep(ctx, order);
        return;
    } catch (err) {
        if (err instanceof api.ApiError) await setState(ctx.conv, STATE.IDLE);
        throw err;
    }
}

async function setState(conv, state, context = null) {
    if (conv.state === state && !context && Object.keys(conv.context || {}).length === 0) return;
    await store.saveState(conv.id, state, context);
    conv.state = state;
    conv.context = context || {};
}

module.exports = { handleText, handleCatalogOrder, STATE };
