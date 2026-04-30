import { Router } from 'express'
import { send_mail } from './mail_sender.js'
import { send_ask_mail, send_poke_mail, send_response } from './looper.js'

export function api_make() {
    const router = new Router()

    router.get('/respond/:response/:response_id', (req, res) => {
        const { response, response_id } = req.params
        send_response(response, response_id)
        // close the window
        res.send(`<!DOCTYPE html>
        <html lang="en">
        <body>
            <script>
                setTimeout(() => {
                    window.close()
                }, 10)
            </script>
        </body>
        </html>`)
    })

    router.get('/ask/:code', (req, res) => {
        const { code } = req.params
        send_ask_mail(code)
        // close the window
        res.send(`<!DOCTYPE html>
        <html lang="en">
        <body>
            <script>
                setTimeout(() => {
                    window.close()
                }, 10)
            </script>
        </body>
        </html>`)
    })

    router.get('/poke/', (req, res) => {
        const { code } = req.params
        send_poke_mail(code)
        // close the window
        res.send(`<!DOCTYPE html>
        <html lang="en">
        <body>
            <script>
                setTimeout(() => {
                    window.close()
                }, 10)
            </script>
        </body>
        </html>`)
    })

    return router
}