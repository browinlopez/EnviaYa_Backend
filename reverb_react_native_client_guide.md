# Guía para conectar React Native a Laravel Reverb

Esta guía explica paso a paso cómo conectar tu aplicación en React Native a tu backend de Laravel Reverb para escuchar eventos de Chat, Notificaciones y GPS en tiempo real.

## 1. Instalación de dependencias

En tu proyecto de **React Native**, debes instalar el cliente de Pusher (que es compatible con Reverb) y Laravel Echo.

Ejecuta en la terminal de tu cliente:

```bash
npm install pusher-js laravel-echo
```
*(Nota: Si usas Expo o bare React Native, puede que necesites un polyfill para `TextEncoder`. Si tienes errores de "TextEncoder no definido", instala `fast-text-encoding` e impórtalo al inicio de tu App).*

## 2. Configuración de Laravel Echo

Crea un archivo llamado `echo.js` (o `echo.ts`) en tu proyecto de React Native. Aquí configuraremos la conexión hacia Laravel Reverb.

```javascript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Esto soluciona problemas de React Native con Pusher
window.Pusher = Pusher;

// Tu token de Sanctum guardado tras el login del usuario
// Puedes obtenerlo desde AsyncStorage, SecureStore, o tu state manager
const getUserToken = () => {
    return 'TU_TOKEN_BEARER_AQUI'; // Ej: "12|xYz123..."
};

const echo = new Echo({
    broadcaster: 'reverb',
    // IMPORTANTE: Estos datos deben coincidir con tu backend (.env)
    key: 'k27j9o18tmsxnzb2a0lp', // REVERB_APP_KEY
    wsHost: 'TU_IP_LOCAL', // Ej: '192.168.1.100' o tu dominio en producción
    wsPort: 8080,
    wssPort: 8080,
    forceTLS: false, // En producción cambia a true si usas wss
    enabledTransports: ['ws', 'wss'],
    // Configuración para autenticar canales privados con Sanctum
    authEndpoint: 'http://TU_IP_LOCAL/api/broadcasting/auth', // La ruta API que usas
    auth: {
        headers: {
            Authorization: `Bearer ${getUserToken()}`,
            Accept: 'application/json',
        },
    },
});

export default echo;
```

> [!WARNING]
> No uses `localhost` o `127.0.0.1` en `wsHost` si estás corriendo React Native en un dispositivo físico o emulador (Android). Usa la IP de tu computadora (ej: `192.168.x.x`).

## 3. Escuchando Eventos

En tus componentes de React Native, importa la instancia configurada de `echo` y suscríbete a los canales.

### 🎧 A. Escuchar Notificaciones Personales

Tus notificaciones viajan por el canal privado del usuario `App.Models.User.{id}`.

```javascript
import React, { useEffect } from 'react';
import echo from './echo';

export default function NotificationsComponent({ userId }) {
    useEffect(() => {
        // Suscribirse al canal privado
        const channel = echo.private(`App.Models.User.${userId}`);

        // Escuchar el evento
        channel.listen('NotificationSent', (event) => {
            console.log('¡Nueva notificación recibida!', event);
            // event.title, event.body, event.data
            // Llama aquí a tus alertas o Push Notifications locales
        });

        return () => {
            // Limpiar al desmontar
            echo.leave(`App.Models.User.${userId}`);
        };
    }, [userId]);

    return null;
}
```

### 💬 B. Escuchar Mensajes de Chat

El chat usa el canal `chat.{chatId}`.

```javascript
import React, { useEffect, useState } from 'react';
import echo from './echo';

export default function ChatRoom({ chatId }) {
    const [messages, setMessages] = useState([]);

    useEffect(() => {
        const channel = echo.private(`chat.${chatId}`);

        channel.listen('MessageSent', (event) => {
            console.log('Nuevo mensaje recibido:', event);
            // event.content, event.name, event.user_id
            setMessages((prev) => [...prev, event]);
        });

        return () => {
            echo.leave(`chat.${chatId}`);
        };
    }, [chatId]);

    // ... render de mensajes
}
```

### 📍 C. Escuchar el GPS de una Orden

El GPS se transmite en el canal `gps.order.{orderId}`.

```javascript
import React, { useEffect, useState } from 'react';
import echo from './echo';

export default function TrackingMap({ orderId }) {
    const [location, setLocation] = useState({ latitude: 0, longitude: 0 });

    useEffect(() => {
        const channel = echo.private(`gps.order.${orderId}`);

        channel.listen('LocationUpdated', (event) => {
            console.log('Nueva ubicación de repartidor:', event);
            // event.latitude, event.longitude
            setLocation({
                latitude: event.latitude,
                longitude: event.longitude,
            });
        });

        return () => {
            echo.leave(`gps.order.${orderId}`);
        };
    }, [orderId]);

    // ... Renderiza aquí tu mapa (ej: react-native-maps)
}
```

## 4. Iniciar el Servidor de Reverb

Para que todo funcione, debes asegurarte de que el servidor Reverb esté corriendo en tu servidor / entorno local:

```bash
php artisan reverb:start
```

Y asegúrate de que el Worker de las colas de eventos también esté corriendo (si usas eventos encolados en algún punto, aunque `ShouldBroadcastNow` lo despacha síncronamente, siempre es buena práctica):

```bash
php artisan queue:listen
```

## Resumen y Consideraciones Finales
*   Los canales están configurados como **Privados** para asegurar que la información no sea interceptada por terceros. Por eso requieres un Bearer token.
*   En Laravel 11/12 las rutas de broadcasting por defecto tienen el middleware `auth:sanctum`.
*   Asegúrate de que tus modelos despachen los eventos usando la sintaxis: `event(new App\Events\LocationUpdated($orderId, $lat, $lng));`
