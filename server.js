import express from 'express'
import fs from 'fs'
import { api_make } from './api.js'
import { get_env, initiate_loop } from './looper.js'
import { get } from 'http'

const app = express()
const port = get_env().PORT || 3636

app.use('/', api_make())
initiate_loop()
console.log('loop initiated')

app.listen(port, () => {
    console.log(`Server listening on port ${port}`)
})
