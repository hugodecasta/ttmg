import fs from 'fs'
import { send_mail } from './mail_sender.js'

export function get_env() {
    const env = fs.readFileSync('.env', 'utf-8')
    const lines = env.split('\n')
    const envVars = {}
    for (const line of lines) {
        const [key, value] = line.split('=')
        if (key && value) {
            envVars[key.trim()] = value.trim()
        }
    }
    return envVars
}

export function send_response(response) {
    const now = new Date()
    const today = parseInt(now.getTime() / (1000 * 60 * 60 * 24))
    if (response_sent == today) {
        console.log('   response already sent today, skipping')
        return
    }
    response_sent = today
    const env = get_env()
    const html_content = fs.readFileSync('response.html', 'utf-8')
    const final_content = html_content.replace('{{response}}', response)
    console.log('   sending response mail...')
    send_mail({
        from: env.FRIEND_MAIL, to: env.ASK_MAIL,
        port: env.SENDER_PORT, server: env.SENDER_SMTP_SERVER,
        user: env.SENDER, password: env.SENDER_KEY,
        subject: "TTMG - response", html_content: final_content
    })
    console.log('   response mail sent')
}

function loop() {
    const env = get_env()
    const html_content = fs.readFileSync('mail.html', 'utf-8')
    const final_content = html_content.replace(/{{response_url}}/g, env.RESPONSE_URL)
    console.log('   sending ask mail...')
    send_mail({
        from: env.ASK_MAIL, to: env.FRIEND_MAIL,
        port: env.SENDER_PORT, server: env.SENDER_SMTP_SERVER,
        user: env.SENDER, password: env.SENDER_KEY,
        subject: "TTMG - asker", html_content: final_content
    })
    console.log('   mail ask sent')
}

let today_done = false
let response_sent = false
export function initiate_loop() {
    setInterval(() => {
        const now = new Date()
        const today = parseInt(now.getTime() / (1000 * 60 * 60 * 24))
        if (today == today_done) return
        const hours = now.getHours()
        const minutes = now.getMinutes()
        if (hours < 13) {
            if (hours >= 10 && minutes >= 31) {
                loop()
                today_done = today
            }
        }
    }, 1000)
}