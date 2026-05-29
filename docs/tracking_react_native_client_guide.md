# Tracking — Guía React Native

Integración completa del sistema de **tracking GPS en tiempo real** del domiciliario para aplicaciones React Native.

---

## Descripción General

El sistema de tracking funciona en dos vías:
1. **Domiciliario (emisor):** Envía su ubicación periódicamente vía HTTP `POST /v1/geolocation` → el backend emite el evento `LocationUpdated` por WebSockets.
2. **Cliente/Negocio (receptor):** Escucha el canal Reverb `private-gps.order.{order_id}` para recibir la posición en tiempo real.

---

## Dependencias Recomendadas

```bash
npm install @laravel-echo/react-native pusher-js
npm install react-native-geolocation-service
npx pod-install  # iOS
```

---

## Paso 1: Configurar el Cliente WebSocket (Reverb)

```javascript
// src/utils/echo.js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js/react-native';

window.Pusher = Pusher;

const echo = new Echo({
  broadcaster: 'reverb',
  key: process.env.EXPO_PUBLIC_REVERB_APP_KEY,  // Leer de .env
  wsHost: process.env.EXPO_PUBLIC_REVERB_HOST,
  wsPort: process.env.EXPO_PUBLIC_REVERB_PORT ?? 8080,
  wssPort: process.env.EXPO_PUBLIC_REVERB_PORT ?? 443,
  forceTLS: (process.env.EXPO_PUBLIC_REVERB_SCHEME ?? 'https') === 'https',
  enabledTransports: ['ws', 'wss'],
  authEndpoint: `${process.env.EXPO_PUBLIC_API_URL}/broadcasting/auth`,
  auth: {
    headers: {
      Authorization: `Bearer ${getStoredToken()}`, // Tu función para obtener el token
      Accept: 'application/json',
    },
  },
});

export default echo;
```

---

## Paso 2: Escuchar el Canal GPS (Cliente / Negocio)

```javascript
// src/hooks/useOrderTracking.js
import { useEffect, useState, useRef } from 'react';
import echo from '../utils/echo';

/**
 * Hook para escuchar la ubicación en tiempo real de un domiciliario.
 * @param {number} orderId - ID del pedido activo.
 */
export function useOrderTracking(orderId) {
  const [location, setLocation] = useState(null);
  const channelRef = useRef(null);

  useEffect(() => {
    if (!orderId) return;

    const channelName = `gps.order.${orderId}`;

    // Suscribirse al canal privado
    channelRef.current = echo.private(channelName)
      .listen('LocationUpdated', (payload) => {
        console.log('[Tracking] Ubicación recibida:', payload);
        setLocation({
          latitude: parseFloat(payload.latitude),
          longitude: parseFloat(payload.longitude),
          timestamp: payload.timestamp,
          orderId: payload.order_id,
        });
      })
      .error((error) => {
        console.error('[Tracking] Error de canal:', error);
      });

    // Limpieza al desmontar
    return () => {
      if (channelRef.current) {
        echo.leave(channelName);
      }
    };
  }, [orderId]);

  return { location };
}
```

**Payload que llega del evento `LocationUpdated`:**
```json
{
  "order_id": 123,
  "latitude": 4.710989,
  "longitude": -74.072092,
  "timestamp": "2025-09-15T14:30:00+00:00"
}
```

---

## Paso 3: Enviar Ubicación GPS (App del Domiciliario)

El domiciliario debe enviar su posición periódicamente al backend.

### Endpoint
```
POST /v1/geolocation
Authorization: Bearer {token}
Content-Type: application/json
```

### Body
```json
{
  "order_id": 123,
  "latitude": 4.710989,
  "longitude": -74.072092
}
```

### Respuesta Exitosa (200)
```json
{
  "message": "Geolocalización registrada",
  "data": {
    "id": 45,
    "order_id": 123,
    "latitude": 4.710989,
    "longitude": -74.072092,
    "created_at": "2025-09-15T14:30:00.000000Z"
  }
}
```

### Implementación en React Native (con intervalo automático)

```javascript
// src/hooks/useDomiciliaryTracking.js
import { useEffect, useRef } from 'react';
import Geolocation from 'react-native-geolocation-service';
import { PermissionsAndroid, Platform } from 'react-native';
import { apiClient } from '../utils/api';

const TRACKING_INTERVAL_MS = 10000; // Enviar cada 10 segundos

async function requestLocationPermission() {
  if (Platform.OS === 'ios') {
    return Geolocation.requestAuthorization('whenInUse');
  }
  return PermissionsAndroid.request(
    PermissionsAndroid.PERMISSIONS.ACCESS_FINE_LOCATION
  );
}

/**
 * Hook para que el domiciliario envíe su posición periódicamente.
 * @param {number} orderId - ID del pedido activo.
 * @param {boolean} isActive - Si el tracking debe estar activo.
 */
export function useDomiciliaryTracking(orderId, isActive = false) {
  const intervalRef = useRef(null);

  useEffect(() => {
    if (!isActive || !orderId) return;

    requestLocationPermission().then(() => {
      intervalRef.current = setInterval(() => {
        Geolocation.getCurrentPosition(
          async (position) => {
            const { latitude, longitude } = position.coords;
            try {
              await apiClient.post('/v1/geolocation', {
                order_id: orderId,
                latitude,
                longitude,
              });
            } catch (error) {
              console.error('[Tracking] Error al enviar ubicación:', error);
            }
          },
          (error) => console.warn('[Tracking] GPS error:', error),
          { enableHighAccuracy: true, timeout: 5000, maximumAge: 1000 }
        );
      }, TRACKING_INTERVAL_MS);
    });

    return () => {
      if (intervalRef.current) clearInterval(intervalRef.current);
    };
  }, [orderId, isActive]);
}
```

---

## Paso 4: Obtener la Última Posición (REST)

Para obtener la última posición registrada sin WebSockets:

### Endpoint
```
GET /v1/geolocation/latest
Authorization: Bearer {token}
```

### Respuesta (200)
```json
{
  "order_id": 123,
  "latitude": 4.710989,
  "longitude": -74.072092,
  "created_at": "2025-09-15T14:30:00.000000Z"
}
```

---

## Errores Comunes

| Código | Descripción | Solución |
|---|---|---|
| 401 | Token expirado o inválido | Renovar token con refresh |
| 403 | No tienes permiso al canal | El `order_id` no pertenece al usuario |
| 422 | Validación fallida | Revisar que `latitude`, `longitude` y `order_id` sean válidos |
| 500 | Error PostGIS | Verificar que la extensión PostGIS esté activa en la BD |

---

## Notas de Rendimiento

- Enviar ubicación cada **10 segundos** es un balance aceptable para tracking sin agotar la batería.
- En iOS, usar `watchPosition` con `desiredAccuracy: BestForNavigation` si se requiere mayor precisión.
- Los datos históricos de ruta se guardan en la tabla `order_geolocations` y pueden consultarse una vez finalizado el pedido.
