# Push Notifications — Guía React Native

Integración completa de notificaciones push usando **Firebase Cloud Messaging (FCM)** con el backend de EnviaYa.

---

## Descripción General

El sistema de notificaciones funciona así:
1. La app React Native obtiene un **FCM Device Token** al iniciar.
2. Lo registra en el backend mediante `PUT /v1/users/update`.
3. Cuando hay eventos relevantes (cambio de estado de pedido, nuevo mensaje), el backend envía la notificación push vía FCM al token registrado.
4. La notificación llega al dispositivo aunque la app esté en segundo plano o cerrada.

---

## Dependencias

```bash
npm install @react-native-firebase/app @react-native-firebase/messaging
npx pod-install  # iOS
```

---

## Paso 1: Obtener el Device Token

```javascript
// src/utils/pushNotifications.js
import messaging from '@react-native-firebase/messaging';
import { Platform } from 'react-native';

/**
 * Solicita permiso y obtiene el FCM Token.
 * @returns {Promise<string|null>} El token FCM o null si fue denegado.
 */
export async function getFcmToken() {
  // Solicitar permiso (obligatorio en iOS)
  const authStatus = await messaging().requestPermission();
  const isAuthorized = [
    messaging.AuthorizationStatus.AUTHORIZED,
    messaging.AuthorizationStatus.PROVISIONAL,
  ].includes(authStatus);

  if (!isAuthorized) {
    console.warn('[FCM] Permiso denegado');
    return null;
  }

  const token = await messaging().getToken();
  console.log('[FCM] Token obtenido:', token);
  return token;
}
```

---

## Paso 2: Registrar el Token en el Backend

### Endpoint
```
PUT /v1/users/update
Authorization: Bearer {token}
Content-Type: application/json
```

### Body
```json
{
  "device_token": "eXaMpLeToKeN123..."
}
```

### Respuesta (200)
```json
{
  "message": "Usuario actualizado correctamente",
  "user": {
    "id": 1,
    "name": "John Doe",
    "device_token": "eXaMpLeToKeN123..."
  }
}
```

### Implementación Completa

```javascript
// src/hooks/usePushNotifications.js
import { useEffect } from 'react';
import messaging from '@react-native-firebase/messaging';
import { getFcmToken } from '../utils/pushNotifications';
import { apiClient } from '../utils/api';

/**
 * Hook principal para registrar y escuchar notificaciones push.
 * Llamar una vez tras el login del usuario.
 */
export function usePushNotifications() {
  useEffect(() => {
    registerDeviceToken();

    // Escuchar notificaciones en primer plano
    const unsubscribeForeground = messaging().onMessage(async remoteMessage => {
      console.log('[FCM] Notificación en foreground:', remoteMessage);
      handleNotification(remoteMessage);
    });

    // Escuchar cuando el usuario abre la app desde una notificación (background)
    const unsubscribeBackground = messaging().onNotificationOpenedApp(remoteMessage => {
      console.log('[FCM] App abierta desde notificación:', remoteMessage);
      navigateFromNotification(remoteMessage);
    });

    // App estaba cerrada y se abrió con notificación
    messaging().getInitialNotification().then(remoteMessage => {
      if (remoteMessage) {
        console.log('[FCM] App iniciada desde notificación:', remoteMessage);
        navigateFromNotification(remoteMessage);
      }
    });

    // Renovar token si FCM lo regenera
    const unsubscribeTokenRefresh = messaging().onTokenRefresh(newToken => {
      console.log('[FCM] Token renovado:', newToken);
      updateDeviceToken(newToken);
    });

    return () => {
      unsubscribeForeground();
      unsubscribeBackground();
      unsubscribeTokenRefresh();
    };
  }, []);
}

async function registerDeviceToken() {
  const token = await getFcmToken();
  if (token) {
    await updateDeviceToken(token);
  }
}

async function updateDeviceToken(token) {
  try {
    await apiClient.put('/v1/users/update', { device_token: token });
    console.log('[FCM] Token registrado en el backend');
  } catch (error) {
    console.error('[FCM] Error registrando token:', error);
  }
}

function handleNotification(remoteMessage) {
  // Mostrar una alerta in-app o actualizar el estado global
  const { title, body } = remoteMessage.notification ?? {};
  const { type, order_id } = remoteMessage.data ?? {};
  console.log(`[Notificación] ${title}: ${body} | tipo: ${type}, pedido: ${order_id}`);
}

function navigateFromNotification(remoteMessage) {
  const { type, order_id } = remoteMessage.data ?? {};
  // Navegar según el tipo
  if (type === 'order_status') {
    // navigation.navigate('OrderDetail', { orderId: order_id });
  }
}
```

---

## Payload de las Notificaciones Push Enviadas por el Backend

Cuando el estado de un pedido cambia, el backend envía:

```json
{
  "notification": {
    "title": "Estado de tu pedido",
    "body": "Tu pedido #123 está en camino"
  },
  "data": {
    "type": "order_status",
    "order_id": "123",
    "state": "in_transit"
  }
}
```

### Estados Posibles (`state`)
| Estado | Descripción |
|---|---|
| `pending` | Pedido creado, buscando domiciliario |
| `accepted` | Domiciliario asignado |
| `in_transit` | Domiciliario en camino |
| `delivered` | Pedido entregado |
| `cancelled` | Pedido cancelado |

---

## Eliminar el Token al Cerrar Sesión

Al hacer logout, elimina el token para que el usuario no siga recibiendo notificaciones:

```javascript
async function logout() {
  try {
    // 1. Eliminar token del backend
    await apiClient.put('/v1/users/update', { device_token: null });

    // 2. Eliminar token local de FCM
    await messaging().deleteToken();

    // 3. Cerrar sesión en la API
    await apiClient.post('/v1/logout');
  } catch (error) {
    console.error('[Auth] Error en logout:', error);
  }
}
```

---

## Configuración en Firebase Console

1. Crear un proyecto en [Firebase Console](https://console.firebase.google.com/).
2. Agregar tu app Android/iOS.
3. Descargar `google-services.json` (Android) y `GoogleService-Info.plist` (iOS).
4. Copiar el **Server Key** desde `Configuración del proyecto > Cloud Messaging`.
5. Agregar `FCM_SERVER_KEY` y `FCM_SENDER_ID` al `.env` del backend.

---

## Errores Comunes

| Error | Causa | Solución |
|---|---|---|
| `messaging/unknown` | Token FCM inválido | Regenerar el token |
| `401 Unauthorized` | Token de API expirado | Renovar con el refresh token |
| Notificaciones no llegan en iOS | Permisos no solicitados | Llamar `requestPermission()` al iniciar |
| Notificaciones no llegan en Android | Batería optimizada | Excluir la app de optimización de batería |
