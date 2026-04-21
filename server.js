import express from 'express'
import fs from 'fs'
import { api_make } from './api.js'
import { initiate_loop } from './looper.js'

const app = express()
const port = process.env.PORT || 3000

app.use('/', api_make())
initiate_loop()
console.log('loop initiated')

app.listen(port, () => {
    console.log(`Server listening on port ${port}`)
})
