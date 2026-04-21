import nodemailer from "nodemailer"

/**
 * Send an email over SMTP.
 *
 * @param {Object} data
 * @param {string} data.from - Sender address.
 * @param {string|string[]} data.to - Recipient address(es).
 * @param {string} data.server - SMTP server hostname.
 * @param {number|string} data.port - SMTP server port.
 * @param {string} [data.object] - Email subject (French naming).
 * @param {string} [data.subject] - Email subject.
 * @param {string} [data.html_content] - HTML body.
 * @param {string} [data.text_content] - Plain text body.
 * @param {string} [data.user] - SMTP auth user.
 * @param {string} [data.password] - SMTP auth password.
 * @param {boolean} [data.secure] - Force TLS (defaults to port === 465).
 * @param {string|string[]} [data.cc] - CC recipient(s).
 * @param {string|string[]} [data.bcc] - BCC recipient(s).
 * @param {string|string[]} [data.reply_to] - Reply-To address(es).
 * @returns {Promise<{messageId: string, accepted: string[], rejected: string[]}>}
 */
export async function send_mail(data) {
    if (!data || typeof data !== "object") {
        throw new Error("send_mail expects a data object")
    }

    const {
        from,
        to,
        server,
        port,
        object,
        subject,
        html_content,
        text_content,
        user,
        password,
        secure,
        cc,
        bcc,
        reply_to,
    } = data

    if (!from) throw new Error("Missing required field: from")
    if (!to || (Array.isArray(to) && to.length === 0)) {
        throw new Error("Missing required field: to")
    }
    if (!server) throw new Error("Missing required field: server")
    if (!port) throw new Error("Missing required field: port")

    const finalSubject = subject || object
    if (!finalSubject) {
        throw new Error("Missing required field: object (or subject)")
    }

    if (!html_content && !text_content) {
        throw new Error("Missing message body: provide html_content or text_content")
    }

    const parsedPort = Number(port)
    if (!Number.isInteger(parsedPort) || parsedPort <= 0) {
        throw new Error("Invalid field: port must be a positive integer")
    }

    const transporter = nodemailer.createTransport({
        host: server,
        port: parsedPort,
        secure: typeof secure === "boolean" ? secure : parsedPort === 465,
        auth: user || password ? { user, pass: password } : undefined,
    })

    const info = await transporter.sendMail({
        from,
        to,
        cc,
        bcc,
        replyTo: reply_to,
        subject: finalSubject,
        html: html_content,
        text: text_content,
    })

    return {
        messageId: info.messageId,
        accepted: info.accepted || [],
        rejected: info.rejected || [],
    }
}

