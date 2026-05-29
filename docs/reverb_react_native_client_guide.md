# Guía de Cliente: Laravel Reverb en React Native

Esta guía documenta la integración de Laravel Echo con Reverb, ajustada para la nueva arquitectura PostGIS y asíncrona.

## 1. Instalación de Dependencias

```bash
npm install laravel-echo pusher-js
```

## 2. Inicializar Laravel Echo

Debido a que Reverb es un servidor de websockets compatible con el protocolo de Pusher, usamos `pusher-js`.

```javascript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const echo = new Echo({
    broadcaster: 'reverb',
    key: 'tu_reverb_app_key', // REVERB_APP_KEY
    wsHost: 'tu-dominio.com',
    wsPort: 8080,
    wssPort: 8080,
    forceTLS: false, // true en producción
    enabledTransports: ['ws', 'wss'],
    authEndpoint: 'http://tu-dominio.com/api/broadcasting/auth',
    auth: {
        headers: {
            Authorization: `Bearer ${tuTokenDeUsuario}`,
        },
    },
});
```

## 3. Arquitectura Asíncrona (Consideraciones)

Con la implementación de **PostGIS** y colas (RabbitMQ/Horizon):

1. **GPS Tracking:** En lugar de saturar la base de datos principal, ahora las coordenadas viajan a través del endpoint `/api/orders/geolocation` que lanza un `PersistLocationJob`. Este Job graba asíncronamente en PostGIS (`ST_MakePoint`).
2. **Latencias:** Al escuchar eventos, considera que los cálculos pesados de distancia (`ST_Distance`) ocurren en el backend y los eventos de Reverb pueden tardar ~50ms más si pasan por RabbitMQ.

## 4. Escuchar Tracking de GPS (Private Channel)

Cuando un domiciliario transmite, los clientes suscritos al pedido reciben actualizaciones.

```javascript
echo.private(`gps.order.${orderId}`)
    .listen('LocationUpdated', (e) => {
        console.log("Nueva ubicación del repartidor:", e);
        // e.latitude, e.longitude (calculado desde ST_Y y ST_X en DB)
        // e.distance_meters (distancia al cliente, si está disponible)
    });
```
