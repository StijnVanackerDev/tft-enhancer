// Generates the placeholder icons Overwolf requires (see manifest.json "meta").
// Replace the files in public/icons with real artwork whenever you like.
import { writeFileSync, mkdirSync } from 'node:fs'
import { deflateSync } from 'node:zlib'

const SIZE = 256
const OUT = new URL('../public/icons/', import.meta.url)

function crc32(buf) {
  let c, crc = 0xffffffff
  for (let n = 0; n < buf.length; n++) {
    c = (crc ^ buf[n]) & 0xff
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
    crc = (crc >>> 8) ^ c
  }
  return (crc ^ 0xffffffff) >>> 0
}

function chunk(type, data) {
  const len = Buffer.alloc(4)
  len.writeUInt32BE(data.length)
  const td = Buffer.concat([Buffer.from(type), data])
  const crc = Buffer.alloc(4)
  crc.writeUInt32BE(crc32(td))
  return Buffer.concat([len, td, crc])
}

function png(pixel) {
  const raw = Buffer.alloc((SIZE * 4 + 1) * SIZE)
  for (let y = 0; y < SIZE; y++) {
    raw[y * (SIZE * 4 + 1)] = 0
    for (let x = 0; x < SIZE; x++) {
      const [r, g, b, a] = pixel(x, y)
      const o = y * (SIZE * 4 + 1) + 1 + x * 4
      raw[o] = r; raw[o + 1] = g; raw[o + 2] = b; raw[o + 3] = a
    }
  }
  const ihdr = Buffer.alloc(13)
  ihdr.writeUInt32BE(SIZE, 0)
  ihdr.writeUInt32BE(SIZE, 4)
  ihdr[8] = 8 // bit depth
  ihdr[9] = 6 // RGBA
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', ihdr),
    chunk('IDAT', deflateSync(raw)),
    chunk('IEND', Buffer.alloc(0)),
  ])
}

// A gold hexagon on a dark rounded square.
function icon(gray) {
  const c = SIZE / 2
  return (x, y) => {
    const dx = Math.abs(x - c), dy = Math.abs(y - c)
    const inHex = dy <= 90 && dx * 0.866 + dy * 0.5 <= 90
    const inBg = Math.max(dx, dy) <= 120
    if (inHex) return gray ? [170, 170, 170, 255] : [230, 180, 60, 255]
    if (inBg) return [25, 28, 36, 255]
    return [0, 0, 0, 0]
  }
}

function ico(pngBuf) {
  const header = Buffer.from([0, 0, 1, 0, 1, 0])
  const entry = Buffer.alloc(16)
  entry[0] = 0 // 0 means 256px
  entry[1] = 0
  entry.writeUInt16LE(1, 4) // colour planes
  entry.writeUInt16LE(32, 6) // bits per pixel
  entry.writeUInt32LE(pngBuf.length, 8)
  entry.writeUInt32LE(6 + 16, 12)
  return Buffer.concat([header, entry, pngBuf])
}

mkdirSync(OUT, { recursive: true })
const color = png(icon(false))
writeFileSync(new URL('icon.png', OUT), color)
writeFileSync(new URL('window_icon.png', OUT), color)
writeFileSync(new URL('icon_gray.png', OUT), png(icon(true)))
writeFileSync(new URL('launcher_icon.ico', OUT), ico(color))
console.log('Icons written to public/icons/')
