import fs from 'fs'
import { send_mail } from './mail_sender.js'
import { randomUUID } from 'crypto'
import { exit } from 'process'

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
        from: env.SENDER, reply_to: env.FRIEND_MAIL, to: env.ASK_MAIL,
        port: env.SENDER_PORT, server: env.SENDER_SMTP_SERVER,
        user: env.SENDER, password: env.SENDER_KEY,
        subject: "TTMG - response", html_content: final_content
    })
    console.log('   response mail sent')
}

export function send_ask_mail(given_code) {
    if (given_code != generated_code) {
        console.log('   wrong code, ignoring ask mail')
        return
    }
    const env = get_env()
    const html_content = fs.readFileSync('mail.html', 'utf-8')
    const final_content = html_content.replace(/{{response_url}}/g, env.RESPONSE_URL)
    console.log('   sending ask mail...')
    send_mail({
        from: env.SENDER, reply_to: env.ASK_MAIL, to: env.FRIEND_MAIL,
        port: env.SENDER_PORT, server: env.SENDER_SMTP_SERVER,
        user: env.SENDER, password: env.SENDER_KEY,
        subject: "TTMG - asker", html_content: final_content
    })
    console.log('   mail ask sent')
}

let generated_code = null
function send_admin_launch_mail() {
    const env = get_env()
    generated_code = randomUUID().replace(/-/g, '').slice(0, 8)
    const html_content = fs.readFileSync('asker.html', 'utf-8')
    let final_content = html_content.replace(/{{ask_url}}/g, env.ASK_URL)
    final_content = final_content.replace(/{{code}}/g, generated_code)
    console.log('   sending admin mail...')
    send_mail({
        from: env.SENDER, reply_to: env.SENDER, to: env.ASK_MAIL,
        port: env.SENDER_PORT, server: env.SENDER_SMTP_SERVER,
        user: env.SENDER, password: env.SENDER_KEY,
        subject: "TTMG - asker", html_content: final_content
    })
    console.log('   ADmin as been asked to launch the loop')
}

function loop() {
    send_admin_launch_mail()
}


let today_done = false
let response_sent = false

export function initiate_loop() {
    if (process.env.DEBUG) {
        loop()
        return
    }
    setInterval(() => {
        const now = new Date()
        const day_of_week = now.getDay()
        if (day_of_week == 0 || day_of_week == 6) return
        const today = parseInt(now.getTime() / (1000 * 60 * 60 * 24))
        if (today == today_done) return
        const hours = now.getHours()
        const minutes = now.getMinutes()
        if (hours < 13) {
            if (hours >= 10 && hours < 12) {
                loop()
                today_done = today
            }
        }
    }, 1000)
}