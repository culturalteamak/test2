const express = require('express');
const { createProxyMiddleware } = require('http-proxy-middleware');

const app = express();
const proxy = createProxyMiddleware({
    target: 'https://jys110.bygoukai.com/', changeOrigin: true, onProxyRes: (proxyRes, req, res) => {
        console.log(proxyRes.headers);
        delete proxyRes.headers['access-control-allow-origin'];
        delete proxyRes.headers['access-control-allow-headers'];

        proxyRes.headers['access-control-allow-origin'] = '*';
        proxyRes.headers['Access-Control-Allow-Headers'] = 'Origin, X-Requested-With, content-Type, Accept, Authorization,lang';
    }
});
// console.log(proxy);
app.use('/', proxy);
app.listen(8070);






















// 狗凯之家源码网 bygoukai.com bygoukai.com bygoukai.com
