import net from 'node:net'

net.createServer((browser) => {
  const server = net.connect(5173, 'node')
  browser.pipe(server).pipe(browser)
  browser.on('error', () => server.destroy())
  server.on('error', () => browser.destroy())
}).listen(5173, '127.0.0.1')
