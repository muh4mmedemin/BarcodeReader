// Telefon kamerası için HTTPS girişi (tarayıcılar kamerayı yalnızca HTTPS'te açar).
// Tek port: /api/... → API sunucusu, geri kalan her şey → arayüz sunucusu.
// Sertifika kendinden imzalıdır; telefonda ilk açılışta tarayıcı uyarısı bir kez kabul edilir.
//
// Kullanım: node scripts/https-proxy.mjs <cert.pem> <key.pem> <https-port> <api-port> <web-port>
import { readFileSync } from 'node:fs';
import http from 'node:http';
import https from 'node:https';

const [cert, key, port, apiPort, webPort] = process.argv.slice(2);

https.createServer({ cert: readFileSync(cert), key: readFileSync(key) }, (req, res) => {
  const target = req.url.startsWith('/api/') ? Number(apiPort) : Number(webPort);
  const upstream = http.request(
    { host: '127.0.0.1', port: target, method: req.method, path: req.url, headers: req.headers },
    (up) => {
      res.writeHead(up.statusCode, up.headers);
      up.pipe(res);
    },
  );
  upstream.on('error', () => {
    res.writeHead(502, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('Sunucuya ulaşılamıyor');
  });
  req.pipe(upstream);
}).listen(Number(port), '0.0.0.0');
