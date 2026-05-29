# Frontend Integration Contract — EnviaYa Backend

> **Versión:** 1.0.0 · **Última actualización:** Mayo 2026
>
> Este documento es el **contrato oficial** entre el backend de EnviaYa y cualquier cliente frontend (React Native). El frontend **no debe necesitar leer código fuente** para integrarse. Todo lo necesario para consumir la API está aquí.

---

## Configuración Base

```
API URL:       http://localhost:8000          (local)
               https://api.enviaya.com        (producción)

Reverb WSS:    ws://localhost:8080/app/{KEY}  (local)
               wss://api.enviaya.com:443      (producción)

Content-Type:  application/json
Accept:        application/json
```

### Headers Requeridos en Todos los Endpoints Protegidos
```
Authorization: Bearer {token}
Content-Type:  application/json
Accept:        application/json
```

---

## Autenticación

### POST `/v1/register` — Registro de Usuario
**Body:**
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "secret123",
  "password_confirmation": "secret123",
  "phone": "3001234567",
  "address": "Calle 123 #45-67",
  "rol": 2
}
```
**Respuesta 201:**
```json
{
  "token": "1|AbCdEfGhIjKl...",
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "phone": "3001234567",
    "rol": 2,
    "state": 1
  }
}
```

---

### POST `/v1/auth/login` — Login
**Body:**
```json
{
  "email": "john@example.com",
  "password": "secret123"
}
```
**Respuesta 200:**
```json
{
  "token": "1|AbCdEfGhIjKl...",
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "rol": 2,
    "state": 1
  }
}
```
**Error 401:**
```json
{ "message": "Credenciales incorrectas" }
```

---

### POST `/v1/logout` — Cerrar Sesión 🔒
**Headers:** `Authorization: Bearer {token}`
**Respuesta 200:**
```json
{ "message": "Sesión cerrada exitosamente" }
```

---

### GET `/v1/profile` — Perfil del Usuario 🔒
**Respuesta 200:**
```json
{
  "id": 1,
  "name": "John Doe",
  "email": "john@example.com",
  "phone": "3001234567",
  "address": "Calle 123 #45-67",
  "rol": 2,
  "qualification": 4.8,
  "state": 1,
  "device_token": "fcm-token..."
}
```

---

### PUT `/v1/users/update` — Actualizar Perfil 🔒
**Body (todos los campos son opcionales):**
```json
{
  "name": "John Updated",
  "phone": "3009876543",
  "address": "Nueva Dirección 456",
  "device_token": "nuevo-fcm-token"
}
```
**Respuesta 200:**
```json
{ "message": "Usuario actualizado correctamente", "user": { ... } }
```

---

### POST `/v1/forgot-password` — Recuperar Contraseña
**Body:**
```json
{ "email": "john@example.com" }
```
**Respuesta 200:**
```json
{ "message": "Se envió un correo de recuperación" }
```

---

## Pedidos (Orders)

### POST `/v1/orders` — Crear Pedido 🔒
**Body:**
```json
{
  "business_id": 5,
  "address_delivery": "Calle 90 #10-23, Bogotá",
  "latitude_delivery": 4.6782,
  "longitude_delivery": -74.0582,
  "payment_method": "card",
  "products": [
    { "id": 12, "quantity": 2 },
    { "id": 15, "quantity": 1 }
  ],
  "notes": "Sin cebolla por favor"
}
```
**Respuesta 201:**
```json
{
  "message": "Pedido creado",
  "order": {
    "id": 123,
    "state": "pending",
    "total": 45000,
    "business": { "id": 5, "name": "Pizza Express" },
    "products": [ ... ],
    "payment_intent_reference": "REF_ABC123"
  }
}
```

---

### GET `/v1/payment-intent/{referenceId}` — Obtener Enlace de Pago 🔒
**Respuesta 200:**
```json
{
  "reference_id": "REF_ABC123",
  "payment_url": "https://checkout.bold.co/payment/REF_ABC123",
  "state": "pending",
  "amount": 45000,
  "currency": "COP"
}
```

---

### GET `/v1/payment/status` — Estado del Pago Actual 🔒
**Respuesta 200:**
```json
{
  "state": "approved",
  "reference": "REF_ABC123",
  "amount": 45000,
  "paid_at": "2025-09-15T14:30:00Z"
}
```

---

### POST `/v1/user` — Mis Pedidos 🔒
**Body:**
```json
{ "user_id": 1 }
```
**Respuesta 200:**
```json
[
  {
    "id": 123,
    "state": "delivered",
    "total": 45000,
    "created_at": "2025-09-15T14:00:00Z",
    "business": { "name": "Pizza Express" }
  }
]
```

---

## Chat

### POST `/v1/chats/send-message` — Enviar Mensaje 🔒
**Body:**
```json
{
  "user_id": 1,
  "recipient_id": 7,
  "content": "¿Ya van en camino?",
  "file_url": null,
  "file_type": null
}
```
**Respuesta 201:**
```json
{
  "message": "Mensaje enviado",
  "chat_id": 45,
  "data": {
    "message_id": 201,
    "user_id": 1,
    "name": "John Doe",
    "content": "¿Ya van en camino?",
    "created_at": "2025-09-15T14:35:00Z"
  }
}
```

---

### POST `/v1/chats/messages` — Obtener Mensajes de un Chat 🔒
**Body (por chat_id):**
```json
{ "chat_id": 45 }
```
**Body (por usuarios):**
```json
{ "user_id": 1, "recipient_id": 7 }
```
**Respuesta 200:**
```json
{
  "chat_id": 45,
  "type": "private",
  "participants": [
    { "user_id": 1, "name": "John Doe", "role_id": 2 },
    { "user_id": 7, "name": "Courier", "role_id": 3 }
  ],
  "messages": [
    {
      "message_id": 201,
      "user_id": 1,
      "name": "John Doe",
      "content": "¿Ya van en camino?",
      "created_at": "2025-09-15T14:35:00Z"
    }
  ]
}
```

---

### POST `/v1/chats/user-chats` — Listar Chats del Usuario 🔒
**Body:**
```json
{ "user_id": 1 }
```

---

## Geolocalización (Tracking)

### POST `/v1/geolocation` — Enviar Posición GPS (Domiciliario) 🔒
**Body:**
```json
{
  "order_id": 123,
  "latitude": 4.710989,
  "longitude": -74.072092
}
```
**Respuesta 200:**
```json
{ "message": "Geolocalización registrada" }
```

### GET `/v1/geolocation/latest` — Última Posición Registrada 🔒
**Respuesta 200:**
```json
{
  "order_id": 123,
  "latitude": 4.710989,
  "longitude": -74.072092,
  "created_at": "2025-09-15T14:30:00Z"
}
```

---

## Negocios

### GET `/v1/business` — Listar Negocios
**Query Params opcionales:** `?category_id=2&search=pizza`
**Respuesta 200:**
```json
[
  {
    "id": 5,
    "name": "Pizza Express",
    "category": "Comida Rápida",
    "qualification": 4.8,
    "address": "Calle 80 #20-10",
    "latitude": 4.696,
    "longitude": -74.043
  }
]
```

---

## Productos

### GET `/v1/products` — Listar Productos
**Query Params:** `?business_id=5`
**Respuesta 200:**
```json
[
  {
    "id": 12,
    "name": "Pizza Margarita",
    "price": 22000,
    "description": "Tomate, mozzarella y albahaca",
    "image_url": "https://...",
    "category_id": 1
  }
]
```

---

## Canales Reverb (WebSockets)

> **Todos los canales son privados.** El cliente debe autenticarse en `/broadcasting/auth` con el Bearer token.

### Canal de Chat
```
Canal:  private-chat.{chat_id}
Evento: MessageSent
```
**Payload del Evento:**
```json
{
  "message_id": 201,
  "chat_id": 45,
  "user_id": 1,
  "name": "John Doe",
  "role_id": 2,
  "content": "¿Ya van en camino?",
  "file_url": null,
  "file_type": null,
  "created_at": "2025-09-15T14:35:00.000000Z"
}
```

---

### Canal de Tracking GPS
```
Canal:  private-gps.order.{order_id}
Evento: LocationUpdated
```
**Payload del Evento:**
```json
{
  "order_id": 123,
  "latitude": 4.710989,
  "longitude": -74.072092,
  "timestamp": "2025-09-15T14:30:00+00:00"
}
```

---

### Canal de Notificaciones del Usuario
```
Canal:  private-App.Models.User.{user_id}
Evento: Illuminate\Notifications\Events\BroadcastNotificationCreated
```
**Payload del Evento:**
```json
{
  "id": "uuid-de-la-notificacion",
  "type": "App\\Notifications\\OrderStatusUpdated",
  "order_id": 123,
  "state": "in_transit",
  "message": "Tu pedido está en camino",
  "time": "2025-09-15 14:30:00"
}
```

---

## Respuestas de Error Estándar

### 401 — No Autenticado
```json
{ "message": "Unauthenticated." }
```
**Causa:** El token no fue enviado, está expirado o es inválido.
**Solución:** Redirigir al login.

---

### 403 — Sin Permisos
```json
{ "message": "This action is unauthorized." }
```
**Causa:** El usuario no tiene permisos para ese recurso.

---

### 422 — Error de Validación
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["El campo email es obligatorio."],
    "password": ["La contraseña debe tener al menos 8 caracteres."]
  }
}
```
**Estructura de errores:** `{ "errors": { "campo": ["mensaje"] } }`

---

### 404 — No Encontrado
```json
{ "message": "No query results for model [App\\Models\\OrderSale] 999" }
```

---

### 500 — Error Interno del Servidor
```json
{ "message": "Server Error" }
```
**En desarrollo** (`APP_DEBUG=true`) se verá el stack trace completo.

---

## Roles del Sistema

| `rol` | Descripción |
|---|---|
| `1` | Administrador |
| `2` | Cliente / Comprador |
| `3` | Domiciliario |
| `4` | Propietario de Negocio |

---

## Variables de Entorno para React Native

Crear un archivo `.env` en la raíz de tu app React Native:

```env
EXPO_PUBLIC_API_URL=http://localhost:8000
EXPO_PUBLIC_REVERB_APP_KEY=tu-reverb-app-key
EXPO_PUBLIC_REVERB_HOST=localhost
EXPO_PUBLIC_REVERB_PORT=8080
EXPO_PUBLIC_REVERB_SCHEME=http
```

---

## Ejemplo de Cliente HTTP Recomendado (Axios)

```javascript
// src/utils/api.js
import axios from 'axios';
import AsyncStorage from '@react-native-async-storage/async-storage';

const apiClient = axios.create({
  baseURL: process.env.EXPO_PUBLIC_API_URL,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
  timeout: 15000,
});

// Inyectar token automáticamente en cada petición
apiClient.interceptors.request.use(async (config) => {
  const token = await AsyncStorage.getItem('auth_token');
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

// Manejar errores globales
apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      // Redirigir al login
      AsyncStorage.removeItem('auth_token');
    }
    return Promise.reject(error);
  }
);

export { apiClient };
```
